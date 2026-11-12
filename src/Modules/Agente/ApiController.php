<?php
declare(strict_types=1);

namespace App\Modules\Agente;

use App\App;
use App\Core\Db;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Migracion\MigradorVfp;

/**
 * API para el agente (sin sesión ni CSRF: se autentica con la clave en Authorization: Bearer fwk_…, siempre por HTTPS).
 *   GET  /api/v1/ping                  comprueba la conexión y la clave
 *   POST /api/v1/vfp/comprobantes      un .zip con subdiario, iva, estadistica, caja y ctact (+ datos.dbc): suma los comprobantes nuevos
 *   POST /api/v1/vfp/maestros          un .zip con clientes y/o articulos (+ datos.dbc): suma los nuevos y actualiza los que ya estaban (por código)
 * Los comprobantes que la web ya tiene se saltean (la sincronización se puede repetir sin duplicar nada).
 */
final class ApiController
{
    private const MAX_BYTES = 80 * 1024 * 1024;
    private const TABLAS = ['subdiario', 'iva', 'estadistica', 'caja', 'ctact'];

    private static function json(array $d, int $status = 200): Response
    {
        return Response::json($d, $status);
    }

    /** @return array<string,mixed>|Response */
    private static function autenticar(Request $req): array|Response
    {
        // Algunos hostings (PHP por FastCGI) no pasan Authorization: el agente manda también la misma clave en X-FW-Clave.
        $enc = (string) ($req->server['HTTP_AUTHORIZATION'] ?? $req->server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($enc === '' && ($req->server['HTTP_X_FW_CLAVE'] ?? '') !== '') {
            $enc = 'Bearer ' . $req->server['HTTP_X_FW_CLAVE'];
        }
        $clave = ApiClaves::validar($enc, (string) ($req->server['REMOTE_ADDR'] ?? ''));
        if ($clave === null) {
            usleep(300000); // frena los intentos a ciegas
            return self::json(['ok' => false, 'error' => 'Clave inválida o revocada.'], 401);
        }
        return $clave;
    }

    public static function ping(Request $req, array $params): Response
    {
        $c = self::autenticar($req);
        return $c instanceof Response ? $c : self::json(['ok' => true, 'sistema' => 'FacturaWeb', 'version' => trim((string) @file_get_contents(App::root() . '/VERSION')), 'clave' => $c['nombre']]);
    }

    public static function comprobantes(Request $req, array $params): Response
    {
        return self::recibirZip($req, 'subdiario|iva|estadistica|caja|ctact', 'subdiario', static function (string $dir, array $clave): array {
            $resumen = (new MigradorVfp(Db::pdo(), $dir))->sumarComprobantes('agente:' . $clave['nombre']);
            return ['ok' => true, 'nuevos' => $resumen['subdiario']['grabadas'] ?? 0, 'leidos' => $resumen['subdiario']['leidas'] ?? 0,
                'detalle' => self::detalle($resumen)];
        });
    }

    public static function maestros(Request $req, array $params): Response
    {
        return self::recibirZip($req, 'clientes|articulos', null, static function (string $dir, array $clave): array {
            $hay = array_values(array_filter(['clientes', 'articulos'], static fn ($t) => is_file($dir . '/' . $t . '.dbf')));
            $resumen = (new MigradorVfp(Db::pdo(), $dir))->sumarMaestros('agente:' . $clave['nombre'], $hay);
            return ['ok' => true, 'detalle' => self::detalle($resumen)];
        });
    }

    /** @param array<string,array<string,mixed>> $resumen */
    private static function detalle(array $resumen): array
    {
        return array_map(static fn ($r) => ['leidas' => $r['leidas'], 'grabadas' => $r['grabadas'], 'actualizadas' => $r['actualizadas'] ?? 0,
            'avisos' => array_slice($r['avisos'], 0, 5)], $resumen);
    }

    /**
     * Recibe el .zip (cuerpo crudo o formulario), deja en una carpeta temporal solo los DBF/FPT permitidos (+ datos.dbc) y llama a $procesar.
     * @param callable(string,array<string,mixed>):array<string,mixed> $procesar
     */
    private static function recibirZip(Request $req, string $tablas, ?string $obligatoria, callable $procesar): Response
    {
        $c = self::autenticar($req);
        if ($c instanceof Response) {
            return $c;
        }
        // El zip llega como cuerpo crudo (lo más simple desde PowerShell) o, si no, como archivo "zip" de un formulario.
        $tmp = tempnam(sys_get_temp_dir(), 'fwzip');
        $f = $req->files['zip'] ?? null;
        if (is_array($f) && ($f['error'] ?? 1) === UPLOAD_ERR_OK && is_file((string) $f['tmp_name'])) {
            copy((string) $f['tmp_name'], $tmp);
        } else {
            $in = fopen('php://input', 'rb');
            $out = fopen($tmp, 'wb');
            $n = $in !== false && $out !== false ? stream_copy_to_stream($in, $out, self::MAX_BYTES + 1) : 0;
            if ($out !== false) {
                fclose($out);
            }
            if ($in !== false) {
                fclose($in);
            }
            if ($n === false || $n === 0 || $n > self::MAX_BYTES) {
                @unlink($tmp);
                return self::json(['ok' => false, 'error' => $n > self::MAX_BYTES ? 'El envío es demasiado grande.' : 'No llegó ningún archivo.'], $n > self::MAX_BYTES ? 413 : 400);
            }
        }
        $dir = App::root() . '/storage/migracion/api_' . bin2hex(random_bytes(8));
        try {
            $zip = new \ZipArchive();
            if ($zip->open($tmp) !== true) {
                return self::json(['ok' => false, 'error' => 'El archivo no es un .zip válido.'], 400);
            }
            if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
                return self::json(['ok' => false, 'error' => 'No se pudo crear la carpeta temporal en storage/.'], 500);
            }
            $hay = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $nombre = basename(str_replace('\\', '/', (string) $zip->getNameIndex($i)));
                if (preg_match('/^(' . $tablas . ')\.(dbf|fpt)$|^datos\.(dbc|dct)$/i', $nombre) !== 1) {
                    continue;
                }
                $d = $zip->getFromIndex($i);
                if ($d !== false) {
                    file_put_contents($dir . '/' . strtolower($nombre), $d);
                    $hay[strtolower(pathinfo($nombre, PATHINFO_FILENAME))] = true;
                }
            }
            $zip->close();
            if ($obligatoria !== null && !isset($hay[$obligatoria])) {
                return self::json(['ok' => false, 'error' => "El zip no trae {$obligatoria}.dbf."], 400);
            }
            if ($obligatoria === null && !array_intersect_key($hay, array_flip(explode('|', $tablas)))) {
                return self::json(['ok' => false, 'error' => 'El zip no trae ninguna de las tablas esperadas (' . str_replace('|', ', ', $tablas) . ').'], 400);
            }
            @set_time_limit(600);
            return self::json($procesar($dir, $c));
        } catch (\Throwable $e) {
            App::registrar($e);
            return self::json(['ok' => false, 'error' => 'No se pudo cargar: ' . mb_substr($e->getMessage(), 0, 300)], 500);
        } finally {
            foreach (glob($dir . '/*') ?: [] as $x) {
                @unlink($x);
            }
            @rmdir($dir);
            @unlink($tmp);
        }
    }
}
