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
    // Mercado Pago (cobros con QR y link de pago). modo: apagado | simulado | produccion. Las claves van en config/local.php (o variables de entorno), nunca al repositorio.
    'mp' => [
        'modo'           => env('MP_MODO', 'apagado'),
        'access_token'   => env('MP_ACCESS_TOKEN', ''),
        'webhook_secret' => env('MP_WEBHOOK_SECRET', ''),
        'user_id'        => env('MP_USER_ID', ''),
        'pos_id'         => env('MP_POS_ID', ''),
        'expira_minutos' => (int) env('MP_EXPIRA_MINUTOS', '30'),
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
$cfg = is_file($local) ? array_replace_recursive($base, require $local) : $base;

/*
 * Varias instalaciones con el mismo código (Sistema → Instalaciones de clientes). Cada cliente tiene su dirección
 * (cliente.orgysis.site), su base y su carpeta storage/instancias/<dirección>/ con su config.php. Si la dirección del
 * pedido tiene esa carpeta, se usa SOLO su configuración (nunca la de local.php: base, mail, ARCA y Mercado Pago son del cliente).
 * Por consola (bin/*.php) se elige con la variable FACTURAWEB_HOST=cliente.orgysis.site.
 */
$host = strtolower((string) (getenv('FACTURAWEB_HOST') ?: ($_SERVER['HTTP_HOST'] ?? '')));
$host = (string) preg_replace('/:\d+$/', '', $host);
$raizInstancias = dirname(__DIR__) . '/storage/instancias';
$hostValido = $host !== '' && strlen($host) <= 190
    && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host) === 1;
if ($hostValido && is_file($raizInstancias . '/' . $host . '/config.php')) {
    $instancia = require $raizInstancias . '/' . $host . '/config.php';
    $cfg = array_replace_recursive($base, is_array($instancia) ? $instancia : []);
    $cfg['instancia'] = ['host' => $host, 'dir' => $raizInstancias . '/' . $host];
    return $cfg;
}
// Una vez creada alguna instalación, local.php (la principal) solo responde en sus propias direcciones: un subdominio
// nuevo sin instalación todavía no puede mostrar los datos de la principal.
$principal = is_file($raizInstancias . '/_principal.php') ? (array) require $raizInstancias . '/_principal.php' : [];
if ($principal !== [] && $host !== '' && !in_array($host, $principal, true)) {
    $cfg['instancia'] = ['desconocida' => $host];
}
return $cfg;
