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

    private static function migrator(): Migrator
    {
        return new Migrator(Db::pdo(), App::root() . '/database/migrations');
    }
}
