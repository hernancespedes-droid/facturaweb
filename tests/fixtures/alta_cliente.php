<?php
declare(strict_types=1);

// Proceso aparte para la prueba de concurrencia de ClientesTest: un alta de cliente.
require dirname(__DIR__, 2) . '/src/bootstrap.php';
App\App::boot();
(new App\Modules\Clientes\ClientesService())->guardar(null, ['razon_social' => 'T Concurrente ' . $argv[1], 'iva' => '3', 'forma_pago' => '1']);
