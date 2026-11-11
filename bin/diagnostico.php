<?php
declare(strict_types=1);

/*
 * Chequea el servidor: php bin/diagnostico.php
 * Ojo: la línea de comandos puede usar otra configuración de PHP que la web (los límites de
 * subida de archivos suelen diferir). Para ver lo que ve la web, usar la ruta /diagnostico
 * (ver docs/DESPLIEGUE.md).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

App\App::boot();
echo json_encode(
    App\Core\Diagnostico::report(),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
), "\n";
