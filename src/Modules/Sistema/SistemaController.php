<?php
declare(strict_types=1);

namespace App\Modules\Sistema;

use App\App;
use App\Core\Db;
use App\Core\Migrator;
use App\Core\Session;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Auth;

/**
 * Página de sistema, solo para el Master: aplicar desde la web los cambios de base pendientes
 * (lo mismo que php bin/migrate.php, para hostings sin SSH) y ver los límites del servidor.
 * Se controla por rol y no por un permiso, porque el permiso lo crearía una migración: entran el Master y el
 * Administrador; aplicar los cambios de base (migrar) es solo del Master.
 */
final class SistemaController
{
    /** Master o Administrador. */
    private static function puedeEntrar(): bool
    {
        return Auth::esMaster() || Auth::esAdmin();
    }

    public static function index(Request $req, array $params): Response
    {
        if (!self::puedeEntrar()) {
            return new Response(403);
        }
        $estado = self::migrator()->status();
        $dir = App::root() . '/storage';
        return Response::html(View::render('sistema/index', [
            'title' => 'Sistema',
            'nav' => 'sistema',
            'puedeMigrar' => Auth::esMaster(),
            'migraciones' => $estado,
            'pendientes' => array_keys(array_filter($estado, static fn ($a) => !$a)),
            'version' => trim((string) @file_get_contents(App::root() . '/VERSION')),
            'servidor' => [
                'PHP' => PHP_VERSION,
                'MySQL' => (string) Db::pdo()->query('SELECT VERSION()')->fetchColumn(),
                'Subida máxima de archivos' => ini_get('upload_max_filesize') . ' (formulario: ' . ini_get('post_max_size') . ')',
                'Memoria por pedido' => (string) ini_get('memory_limit'),
                'Tiempo máximo por pedido' => ini_get('max_execution_time') . ' s',
                'storage/logs escribible' => is_writable($dir . '/logs') ? 'Sí' : 'No',
                'curl y openssl (para ARCA)' => function_exists('curl_init') && extension_loaded('openssl') ? 'Sí' : 'No',
            ],
            'migracionesVfp' => self::migracionesVfp(),
            'errores' => Auth::esMaster() ? self::ultimosErrores(15) : null,
        ]));
    }

    /**
     * Últimos errores de storage/logs (este mes y el anterior), del más nuevo al más viejo. Solo para el Master: tienen
     * rutas del servidor y detalles internos.
     *
     * @return array<int,array{fecha:string,tipo:string,mensaje:string,donde:string}>
     */
    private static function ultimosErrores(int $cuantos): array
    {
        $dir = App::root() . '/storage/logs';
        $out = [];
        foreach ([date('Y-m'), date('Y-m', strtotime('first day of last month'))] as $mes) {
            $f = $dir . '/app-' . $mes . '.log';
            if (!is_file($f)) {
                continue;
            }
            // Solo el final del archivo (puede ser grande).
            $tam = (int) filesize($f);
            $h = fopen($f, 'rb');
            if ($h === false) {
                continue;
            }
            fseek($h, max(0, $tam - 200000));
            $texto = (string) stream_get_contents($h);
            fclose($h);
            preg_match_all('/^\[([^\]]+)\] ([\w\\\\]+): (.*?) en (\S+:\d+)$/m', $texto, $m, PREG_SET_ORDER);
            foreach (array_reverse($m) as $x) {
                $out[] = ['fecha' => $x[1], 'tipo' => $x[2], 'mensaje' => mb_substr($x[3], 0, 300), 'donde' => str_replace(App::root() . '/', '', $x[4])];
                if (count($out) >= $cuantos) {
                    return $out;
                }
            }
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> últimas corridas del migrador DBF (vacío si no hay tabla todavía) */
    private static function migracionesVfp(): array
    {
        try {
            $filas = Db::pdo()->query('SELECT id, fecha, origen, tablas, resumen FROM migracion_vfp ORDER BY id DESC LIMIT 5')->fetchAll();
        } catch (\PDOException) {
            return [];
        }
        foreach ($filas as &$f) {
            $f['resumen'] = json_decode((string) $f['resumen'], true) ?: [];
        }
        return $filas;
    }

    public static function migrar(Request $req, array $params): Response
    {
        if (!Auth::esMaster()) {
            return new Response(403);
        }
        $pdo = Db::pdo();
        if ((int) $pdo->query("SELECT GET_LOCK('fw_migrar', 0)")->fetchColumn() !== 1) {
            Session::flash('error', 'Ya se están aplicando cambios de base. Esperá unos segundos.');
            return Response::redirect(url('/sistema'));
        }
        try {
            $aplicadas = self::migrator()->run();
            Session::flash('ok', $aplicadas === []
                ? 'La base ya estaba al día.'
                : 'Cambios aplicados: ' . implode(', ', $aplicadas) . '.');
        } catch (\RuntimeException $e) {
            // El detalle técnico le sirve al Master para pasárselo a O&S.
            Session::flash('error', 'No se pudo completar: ' . $e->getMessage() . ' Lo que se aplicó antes del error quedó registrado.');
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('fw_migrar')");
        }
        return Response::redirect(url('/sistema'));
    }

    /**
     * Migración desde el VFP subiendo un .zip con los DBF (para hostings sin SSH). Solo el Master. Del zip se
     * extraen solo las tablas (.dbf .fpt .dbc .dct), con nombre limpio, a storage/migracion/<al azar>/, y se
     * borran al terminar.
     */
    public static function migrarVfp(Request $req, array $params): Response
    {
        if (!Auth::esMaster()) {
            return new Response(403);
        }
        $f = $req->files['zip'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file((string) $f['tmp_name'])) {
            Session::flash('error', 'Elegí el .zip con los DBF (si pesa mucho, el hosting puede rechazarlo: máximo ' . ini_get('upload_max_filesize') . ').');
            return Response::redirect(url('/sistema#migracion-vfp'));
        }
        $dir = App::root() . '/storage/migracion/' . bin2hex(random_bytes(8));
        try {
            $n = self::extraerDbf((string) $f['tmp_name'], $dir);
            if ($n === 0) {
                Session::flash('error', 'El zip no tiene tablas DBF.');
                return Response::redirect(url('/sistema#migracion-vfp'));
            }
            $simular = ($req->post['simular'] ?? '') === '1';
            @set_time_limit(600);
            $resumen = (new \App\Modules\Migracion\MigradorVfp(Db::pdo(), $dir))
                ->migrar(null, ($req->post['reemplazar'] ?? '') === '1', $simular);
            $grabadas = array_sum(array_map(static fn ($r) => $r['grabadas'], $resumen));
            $avisos = array_sum(array_map(static fn ($r) => count($r['avisos']), $resumen));
            Session::flash('ok', ($simular ? 'Simulación (no se grabó nada): ' : 'Migración grabada: ')
                . number_format($grabadas, 0, ',', '.') . ' registros, ' . $avisos . ' aviso(s).'
                . ($simular ? ' ' . self::resumenTexto($resumen) : ' El detalle quedó abajo.'));
        } catch (\RuntimeException $e) {
            Session::flash('error', 'No se migró nada: ' . $e->getMessage());
        } finally {
            foreach (glob($dir . '/*') ?: [] as $x) {
                @unlink($x);
            }
            @rmdir($dir);
        }
        return Response::redirect(url('/sistema#migracion-vfp'));
    }

    /** Extrae del zip solo los archivos de tablas, sin carpetas. Devuelve cuántos .dbf extrajo. */
    private static function extraerDbf(string $zip, string $dir): int
    {
        $z = new \ZipArchive();
        if ($z->open($zip) !== true) {
            throw new \RuntimeException('El archivo no es un .zip válido.');
        }
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new \RuntimeException('No se pudo crear la carpeta temporal en storage/.');
        }
        $n = 0;
        for ($i = 0; $i < $z->numFiles; $i++) {
            $nombre = basename(str_replace('\\', '/', (string) $z->getNameIndex($i)));
            if (preg_match('/^[A-Za-z0-9_ .-]{1,80}\.(dbf|fpt|dbc|dct)$/i', $nombre) !== 1) {
                continue;
            }
            $datos = $z->getFromIndex($i);
            if ($datos === false) {
                continue;
            }
            file_put_contents($dir . '/' . $nombre, $datos);
            $n += preg_match('/\.dbf$/i', $nombre);
        }
        $z->close();
        return $n;
    }

    /** @param array<string,array{leidas:int,grabadas:int,avisos:string[]}> $resumen */
    private static function resumenTexto(array $resumen): string
    {
        $partes = [];
        foreach ($resumen as $t => $r) {
            $partes[] = $t . ' ' . $r['grabadas'] . (count($r['avisos']) > 0 ? ' (' . count($r['avisos']) . ' avisos: ' . implode(' / ', array_slice($r['avisos'], 0, 3)) . ')' : '');
        }
        return implode('; ', $partes) . '.';
    }

    private static function migrator(): Migrator
    {
        return new Migrator(Db::pdo(), App::root() . '/database/migrations');
    }
}
