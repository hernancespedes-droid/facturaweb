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
