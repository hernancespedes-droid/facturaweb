<?php
declare(strict_types=1);

/*
 * Consulta a Mercado Pago los ingresos de dinero a la cuenta y los registra (los mismos que ve Mercado Pago → Ingresos).
 * No hace falta: la campanita de las pantallas abiertas ya consulta como mucho una vez por minuto. Sirve para un cron del hosting, así también
 * se registran (y se mandan los mails) cuando nadie tiene el sistema abierto:
 *
 *   php bin/mp-ingresos.php                                  instalación principal
 *   FACTURAWEB_HOST=cliente.dominio.com php bin/mp-ingresos.php   una instalación de cliente
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\App;
use App\Modules\MercadoPago\IngresosService;
use App\Modules\MercadoPago\MercadoPago;

App::boot();

if (MercadoPago::modo() !== 'produccion') {
    fwrite(STDOUT, "Mercado Pago no está en producción en esta instalación (" . MercadoPago::modo() . "): no hay nada que consultar.\n");
    exit(0);
}
$n = (new IngresosService())->consultarSiToca(50);
fwrite(STDOUT, $n < 0 ? "Ya se consultó hace menos de un minuto.\n" : "Ingresos nuevos: {$n}\n");
