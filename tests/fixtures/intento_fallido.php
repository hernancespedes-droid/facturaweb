<?php
declare(strict_types=1);

// Proceso aparte para la prueba de concurrencia de AuthTest: un intento de ingreso fallido.
require dirname(__DIR__, 2) . '/src/bootstrap.php';
putenv('DB_NAME=facturaweb_test');
App\App::boot();
(new App\Modules\Auth\LoginService())->intentar($argv[1], 'clave-equivocada');
