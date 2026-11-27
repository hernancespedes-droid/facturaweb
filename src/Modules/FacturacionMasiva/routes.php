<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Auth\Guard;
use App\Modules\FacturacionMasiva\FacturacionMasivaController as C;

// Facturación masiva: comprobantes.masiva y además comprobantes.emitir (factura, y el formulario usa los buscadores de clientes y artículos de la factura).
return function (Router $r): void {
    $p = static fn (array $h): \Closure => Guard::permiso('comprobantes.emitir', Guard::permiso('comprobantes.masiva', $h));
    $r->get('/facturacion-masiva', $p([C::class, 'index']));
    $r->get('/facturacion-masiva/planilla', $p([C::class, 'plantilla']));
    $r->post('/facturacion-masiva/subir', $p([C::class, 'subir']));
    $r->post('/facturacion-masiva/eliminar', $p([C::class, 'eliminar']));
    $r->post('/facturacion-masiva/revisar', $p([C::class, 'revisar']));
    $r->post('/facturacion-masiva/procesar', $p([C::class, 'procesar']));
    $r->get('/facturacion-masiva/{id}/modificar', $p([C::class, 'editar']));
    $r->post('/facturacion-masiva/{id}/modificar', $p([C::class, 'guardar']));
};
