<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Auth\Guard;
use App\Modules\CuentaCorriente\CuentaCorrienteController as C;

// ctacte.ver: consultas, recibos ya hechos, deuda y cheques (y sus Excel). ctacte.cobrar: cargar recibos. ctacte.imputar: imputación manual.
// ctacte.ajustar: ajustes, anular recibos y cambiar el estado de los cheques. Las rutas fijas van antes que /{id}.
return function (Router $r): void {
    $ver = 'ctacte.ver';
    $r->get('/cuenta-corriente', Guard::permiso($ver, [C::class, 'resumen']));
    $r->get('/cuenta-corriente/cliente', Guard::permiso($ver, [C::class, 'clienteIr']));
    $r->get('/cuenta-corriente/cliente/{codigo}', Guard::permiso($ver, [C::class, 'cliente']));
    $r->get('/cuenta-corriente/buscar-cliente', Guard::permiso($ver, [C::class, 'buscarCliente']));
    $r->get('/cuenta-corriente/imputar', Guard::permiso('ctacte.imputar', [C::class, 'imputarForm']));
    $r->post('/cuenta-corriente/imputar', Guard::permiso('ctacte.imputar', [C::class, 'imputar']));
    $r->get('/cuenta-corriente/ajuste', Guard::permiso('ctacte.ajustar', [C::class, 'ajusteForm']));
    $r->post('/cuenta-corriente/ajuste', Guard::permiso('ctacte.ajustar', [C::class, 'ajuste']));
    $r->get('/recibos', Guard::permiso($ver, [C::class, 'recibos']));
    $r->get('/recibos/nuevo', Guard::permiso('ctacte.cobrar', [C::class, 'reciboNuevo']));
    $r->post('/recibos', Guard::permiso('ctacte.cobrar', [C::class, 'reciboGrabar']));
    $r->get('/recibos/{id}', Guard::permiso($ver, [C::class, 'recibo']));
    $r->get('/recibos/{id}/imprimir', Guard::permiso($ver, [C::class, 'imprimir']));
    $r->post('/recibos/{id}/anular', Guard::permiso('ctacte.ajustar', [C::class, 'anular']));
    $r->get('/cheques', Guard::permiso($ver, [C::class, 'cheques']));
    $r->post('/cheques/{id}/estado', Guard::permiso('ctacte.ajustar', [C::class, 'estadoCheque']));
};
