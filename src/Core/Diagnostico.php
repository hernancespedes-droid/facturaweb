<?php
declare(strict_types=1);

namespace App\Core;

use App\App;

/**
 * Chequea el servidor donde se instaló el sistema: versión de PHP, extensiones, límites de
 * subida, permisos de escritura, conexión a la base y salida a internet.
 * Se usa por línea de comandos (php bin/diagnostico.php) y por la ruta /diagnostico.
 */
final class Diagnostico
{
    /** Extensiones que necesita el sistema. */
    public const EXTENSIONES = ['pdo_mysql', 'mbstring', 'curl', 'openssl', 'dom'];

    /** Servicios a los que el sistema va a llamar (ARCA, para la factura electrónica). */
    private const SALIDAS = [
        'arca_wsaa' => 'https://wsaa.afip.gov.ar/ws/services/LoginCms',
        'arca_wsfe' => 'https://servicios1.afip.gov.ar/wsfev1/service.asmx',
    ];

    /**
     * @return array<string,mixed>
     */
    public static function report(bool $checkNetwork = true): array
    {
        $ext = [];
        foreach (self::EXTENSIONES as $name) {
            $ext[$name] = extension_loaded($name);
        }

        $root = App::root();
        $report = [
            'php' => [
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'cumple_minimo_8_2' => PHP_VERSION_ID >= 80200,
            ],
            'extensiones' => $ext,
            'extensiones_faltantes' => array_keys(array_filter($ext, static fn (bool $ok): bool => !$ok)),
            'limites' => [
                'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
                'post_max_size' => (string) ini_get('post_max_size'),
                'max_file_uploads' => (string) ini_get('max_file_uploads'),
                'memory_limit' => (string) ini_get('memory_limit'),
                'max_execution_time' => (string) ini_get('max_execution_time'),
            ],
            'escritura' => [
                'storage/logs' => is_writable($root . '/storage/logs'),
            ],
            'espacio_libre_mb' => (int) round((float) @disk_free_space($root) / 1048576),
            'zona_horaria' => date_default_timezone_get(),
            'db' => self::db(),
            'notas' => [
                'cron' => 'No se puede detectar desde PHP: verificar en el panel si hay tareas programadas.',
            ],
        ];

        if ($checkNetwork) {
            $report['salida_https'] = [];
            foreach (self::SALIDAS as $name => $url) {
                $report['salida_https'][$name] = self::probar($url);
            }
        }
        return $report;
    }

    /**
     * @return array<string,mixed>
     */
    private static function db(): array
    {
        try {
            $version = Db::pdo()->query('SELECT VERSION()')->fetchColumn();
            return ['ok' => true, 'version' => (string) $version];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private static function probar(string $url): string
    {
        if (!function_exists('curl_init')) {
            return 'sin curl';
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        // Cualquier respuesta HTTP (aunque sea 404 o 405) indica que hay salida a internet.
        return $code > 0 ? "conecta (HTTP {$code})" : 'sin conexión: ' . $error;
    }
}
