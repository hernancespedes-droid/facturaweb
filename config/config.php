<?php
declare(strict_types=1);

/*
 * Configuración base. Los valores se pueden pisar de dos formas:
 *   1) Variables de entorno (las usa el entorno de pruebas).
 *   2) Archivo config/local.php (lo crea Hernán en el servidor; NO va al repositorio).
 * Ver config/local.example.php.
 */

$base = [
    'app' => [
        'name'      => 'FacturaWeb',
        'env'       => env('APP_ENV', 'production'),
        'debug'     => false,
        // Solo si el sistema queda en una subcarpeta (ej. "/facturador"). Sin barra final.
        'base_path' => env('APP_BASE_PATH', ''),
        // Dirección pública del sistema (ej. "https://facturador.oys.com.ar"), para los enlaces que salen del sistema.
        // Vacía: se toma la del navegador que imprime.
        'url' => env('APP_URL', ''),
        // Clave temporal para habilitar /diagnostico?token=... (vacía = ruta apagada).
        'diag_token' => env('DIAG_TOKEN', ''),
        // Clave para /instalar (primera instalación sin SSH). Vacía = ruta apagada.
        'instalar_token' => env('INSTALAR_TOKEN', ''),
    ],
    'auth' => [
        // Minutos sin uso tras los cuales la sesión se cierra sola.
        'inactividad_minutos' => (int) env('AUTH_INACTIVIDAD_MINUTOS', '240'),
    ],
    // Envío de comprobantes por mail (SMTP). Vacío = no configurado. Se completa en config/local.php.
    // Factura electrónica de ARCA. modo: interno (sin CAE) | simulado | homologacion | produccion. 'config' = ruta al
    // config_afip.php del cliente, FUERA de public_html. Se completa en config/local.php (ver docs/ARCA-PUESTA-EN-MARCHA.md).
    'afip' => [
        'modo'   => env('AFIP_MODO', 'interno'),
        'config' => env('AFIP_CONFIG', ''),
    ],
    'mail' => [
        'host'      => env('MAIL_HOST', ''),
        'port'      => (int) env('MAIL_PORT', '587'),
        'secure'    => env('MAIL_SECURE', 'tls'),   // tls (587), ssl (465) o vacío (solo pruebas locales)
        'user'      => env('MAIL_USER', ''),
        'pass'      => env('MAIL_PASS', ''),
        'from'      => env('MAIL_FROM', ''),
        'from_name' => env('MAIL_FROM_NAME', ''),
    ],
    'db' => [
        'host'    => env('DB_HOST', '127.0.0.1'),
        'port'    => (int) env('DB_PORT', '3306'),
        'name'    => env('DB_NAME', 'facturaweb'),
        'user'    => env('DB_USER', 'facturaweb'),
        'pass'    => env('DB_PASS', ''),
        'charset' => 'utf8mb4',
    ],
];

$local = __DIR__ . '/local.php';

return is_file($local) ? array_replace_recursive($base, require $local) : $base;
