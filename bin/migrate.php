<?php
declare(strict_types=1);

/*
 * Aplica las migraciones pendientes de database/migrations.
 *   php bin/migrate.php            aplica lo pendiente
 *   php bin/migrate.php --status   muestra qué está aplicado y qué no
 * Se usa igual en desarrollo y en el servidor (lee config/local.php o las variables de entorno).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\App;
use App\Core\Db;
use App\Core\Migrator;

App::boot();

$migrator = new Migrator(Db::pdo(), App::root() . '/database/migrations');

if (in_array('--status', $argv, true)) {
    foreach ($migrator->status() as $name => $applied) {
        printf("[%s] %s\n", $applied ? 'aplicada ' : 'PENDIENTE', $name);
    }
    exit(0);
}

$applied = $migrator->run();
if ($applied === []) {
    echo "Base al día: no hay migraciones pendientes.\n";
    exit(0);
}
foreach ($applied as $name) {
    echo "Aplicada: {$name}\n";
}
