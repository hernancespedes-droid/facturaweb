<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Auth\Guard;
use App\Modules\Caja\CajaController as C;

// caja.turno: mi turno (abrir, arqueo, cerrar) y mis movimientos. caja.mover: ingresos y egresos manuales y su anulación. caja.auditar: auditoría y turnos de todos.
return function (Router $r): void {
    $r->get('/caja', Guard::permiso('caja.turno', [C::class, 'index']));
    $r->post('/caja/abrir', Guard::permiso('caja.turno', [C::class, 'abrir']));
    $r->post('/caja/sumar', Guard::permiso('caja.turno', [C::class, 'sumar']));
    $r->post('/caja/cerrar', Guard::permiso('caja.turno', [C::class, 'cerrar']));
    $r->post('/caja/mover', Guard::permiso('caja.mover', [C::class, 'mover']));
    $r->post('/caja/anular', Guard::permiso('caja.mover', [C::class, 'anular']));
    $r->get('/caja/turnos', Guard::permiso('caja.turno', [C::class, 'turnos']));
    $r->get('/caja/auditoria', Guard::permiso('caja.auditar', [C::class, 'auditoria']));
};
