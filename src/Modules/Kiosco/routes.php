<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Auth\Guard;
use App\Modules\Kiosco\KioscoController as C;

// Modo Kiosco: kiosco.usar (y «habilitar modo Kiosco» en Parámetros). Solo emite presupuestos.
return function (Router $r): void {
    $r->get('/kiosco', Guard::permiso('kiosco.usar', [C::class, 'index']));
    $r->get('/kiosco/articulos', Guard::permiso('kiosco.usar', [C::class, 'articulos']));
    $r->get('/kiosco/buscar-cliente', Guard::permiso('kiosco.usar', [C::class, 'buscarCliente']));
    $r->post('/kiosco/cobrar', Guard::permiso('kiosco.usar', [C::class, 'cobrar']));
    $r->post('/kiosco/emitir', Guard::permiso('kiosco.usar', [C::class, 'emitir']));
};
