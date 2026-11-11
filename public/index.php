<?php
declare(strict_types=1);

// Servidor embebido de PHP (solo desarrollo): entrega directo los archivos estáticos.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    $real = realpath(__DIR__ . $path);
    if ($real !== false && is_file($real)
        && str_starts_with($real, __DIR__ . DIRECTORY_SEPARATOR)
        && basename($real) !== 'index.php') {
        return false;
    }
}

// El código vive en la carpeta de arriba (public/ dentro del proyecto) o, en hostings donde la
// carpeta pública es fija (public_html de DonWeb), en una carpeta hermana "facturaweb".
$bootstrap = dirname(__DIR__) . '/src/bootstrap.php';
if (!is_file($bootstrap)) {
    $bootstrap = dirname(__DIR__) . '/facturaweb/src/bootstrap.php';
}
require $bootstrap;

App\App::boot()->run();
