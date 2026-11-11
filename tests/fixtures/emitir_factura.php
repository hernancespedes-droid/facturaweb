<?php
declare(strict_types=1);

// Proceso aparte para la prueba de concurrencia de ComprobantesTest: emite una factura A a cuenta corriente.
require dirname(__DIR__, 2) . '/src/bootstrap.php';
putenv('DB_NAME=facturaweb_test');
App\App::boot();
$svc = new App\Modules\Comprobantes\ComprobantesService();
$r = $svc->armar(['tipo' => 'FC', 'cliente' => '990001', 'sucursal' => '99', 'forma_pago' => '2',
    'items' => [['articulo' => 'T-ART', 'descripcion' => '', 'cantidad' => '1', 'precio' => '10', 'alicuota' => '21']]]);
$e = $svc->emitir($r['cbte'], bin2hex(random_bytes(16)), 0);
echo $e['numero'], "\n";
