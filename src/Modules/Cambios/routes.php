<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Auth\Guard;
use App\Modules\Cambios\CambiosController as C;

// Leer el ticket y hacer cambios: cambios.hacer (y «habilita modo ticket de cambio» en Parámetros). El PDF del ticket: comprobantes.ver.
return function (Router $r): void {
    $r->get('/cambios', Guard::permiso('cambios.hacer', [C::class, 'index']));
    $r->get('/cambios/nuevo', Guard::permiso('cambios.hacer', [C::class, 'nuevo']));
    $r->post('/cambios', Guard::permiso('cambios.hacer', [C::class, 'emitir']));
    $r->get('/cambios/buscar-articulo', Guard::permiso('cambios.hacer', [C::class, 'buscarArticulo']));
    $r->get('/cambios/ticket/{id}', Guard::permiso('comprobantes.ver', [C::class, 'ticket']));
};
