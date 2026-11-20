<?php
declare(strict_types=1);

namespace App\Core;

use App\App;
use App\Http\Response;
use PDO;

/**
 * Actualización automática de la base. Después de actualizar el código (GIT), el primer pedido que llega a la instalación aplica solo los cambios de base
 * pendientes: nadie tiene que entrar a «Sistema → Aplicar cambios» en cada cliente.
 *
 * DECISIÓN D-59 (Dev Senior, a confirmar por Hernán): cómo se evita el desastre.
 *   · Las migraciones son siempre aditivas e idempotentes (regla de CLAUDE.md), así que aplicarlas sin que nadie mire es seguro.
 *   · Se compara una «firma» (versión + cantidad de archivos de migración) con la guardada en `sistema_estado`: un pedido normal cuesta una consulta.
 *   · Un candado de MySQL (GET_LOCK) asegura que solo un pedido migre; los demás ven «se está actualizando» unos segundos (503 con Retry-After).
 *   · Si una migración falla, NO se bloquea el sistema: se anota en storage/logs y en storage/automigracion_error.json (visible en Sistema y en /salud), no se
 *     reintenta durante un minuto, y el Master la resuelve a mano (Sistema → Aplicar cambios muestra el error). La firma no se guarda hasta que migre bien.
 *   · No toca una base sin instalar (sin `schema_migrations`): eso es de /instalar.
 */
final class AutoMigracion
{
    private const CANDADO = 'fw_migrar'; // el mismo que usa «Sistema → Aplicar cambios»
    private const REINTENTO_SEGUNDOS = 60;

    /** Carpeta de migraciones y archivo del último error (las pruebas los cambian). */
    public static ?string $dir = null;
    public static ?string $archivoError = null;

    private static function dir(): string
    {
        return self::$dir ?? App::root() . '/database/migrations';
    }

    private static function archivoError(): string
    {
        return self::$archivoError ?? App::root() . '/storage/automigracion_error.json';
    }

    public static function firma(): string
    {
        $archivos = glob(self::dir() . '/*.sql') ?: [];
        return trim((string) @file_get_contents(App::root() . '/VERSION')) . ':' . count($archivos);
    }

    /** El último error de migración automática, si lo hubo y no se resolvió. @return array{ts:int,mensaje:string}|null */
    public static function ultimoError(): ?array
    {
        $j = @file_get_contents(self::archivoError());
        $d = $j !== false ? json_decode($j, true) : null;
        return is_array($d) && isset($d['ts'], $d['mensaje']) ? ['ts' => (int) $d['ts'], 'mensaje' => (string) $d['mensaje']] : null;
    }

    public static function limpiarError(): void
    {
        @unlink(self::archivoError());
    }

    /** Una consulta de un solo valor, cerrando el cursor (si no, MySQL se queja de la consulta siguiente). */
    private static function valor(PDO $pdo, string $sql): mixed
    {
        $st = $pdo->query($sql);
        $v = $st->fetchColumn();
        $st->closeCursor();
        return $v;
    }

    /** @return string|false la firma guardada, o false si todavía no hay tabla o dato */
    private static function guardada(PDO $pdo): string|false
    {
        try {
            return self::valor($pdo, "SELECT valor FROM sistema_estado WHERE clave = 'migraciones_firma'");
        } catch (\PDOException) {
            return false;
        }
    }

    /** Deja asentado que la base está al día con este código (también lo hace «Aplicar cambios» a mano). */
    public static function marcarAlDia(?PDO $pdo = null): void
    {
        $pdo ??= Db::pdo();
        $ins = $pdo->prepare('INSERT INTO sistema_estado (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)');
        $ins->execute(['migraciones_firma', self::firma()]);
        $ins->execute(['migrada_en', date('Y-m-d H:i:s')]);
        self::limpiarError();
    }

    /** Se llama al principio de cada pedido. Devuelve una respuesta solo si hay que pedir que esperen (otro pedido está migrando). */
    public static function asegurar(): ?Response
    {
        try {
            $pdo = Db::pdo();
            $firma = self::firma();
            if (self::guardada($pdo) === $firma) {
                return null;
            }
            if (self::valor($pdo, "SHOW TABLES LIKE 'schema_migrations'") === false) {
                return null; // base sin instalar: lo hace /instalar
            }
            $err = self::ultimoError();
            if ($err !== null && time() - $err['ts'] < self::REINTENTO_SEGUNDOS) {
                return null; // falló hace un momento: no se insiste en cada pedido
            }
            if ((int) self::valor($pdo, "SELECT GET_LOCK('" . self::CANDADO . "', 0)") !== 1) {
                return self::esperar();
            }
            try {
                if (self::guardada($pdo) === $firma) {
                    return null; // otro pedido ya terminó
                }
                $aplicadas = (new Migrator($pdo, self::dir()))->run();
                self::marcarAlDia($pdo);
                if ($aplicadas !== []) {
                    error_log('[automigracion] aplicadas: ' . implode(', ', $aplicadas));
                }
            } catch (\Throwable $e) {
                @file_put_contents(self::archivoError(), (string) json_encode(['ts' => time(), 'mensaje' => mb_substr($e->getMessage(), 0, 500)], JSON_UNESCAPED_UNICODE));
                error_log('[automigracion] ERROR: ' . $e->getMessage());
            } finally {
                self::valor($pdo, "SELECT RELEASE_LOCK('" . self::CANDADO . "')");
            }
        } catch (\Throwable $e) {
            error_log('[automigracion] no se pudo comprobar: ' . $e->getMessage()); // sin base: lo maneja el resto de la aplicación
        }
        return null;
    }

    private static function esperar(): Response
    {
        $html = '<!doctype html><html lang="es-AR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta http-equiv="refresh" content="4">'
            . '<title>Actualizando</title><style>body{font:16px/1.5 Arial,sans-serif;background:#f3f5f9;color:#18202b;margin:0}.c{max-width:460px;margin:12vh auto;padding:24px;background:#fff;border-radius:14px}</style></head>'
            . '<body><div class="c"><h1>Actualizando el sistema</h1><p>Se están aplicando mejoras. Es cuestión de unos segundos: esta pantalla se vuelve a cargar sola. No se pierde nada de lo que ya estaba guardado.</p></div></body></html>';
        return new Response(503, $html, ['Content-Type' => 'text/html; charset=utf-8', 'Retry-After' => '5', 'Cache-Control' => 'no-store']);
    }
}
