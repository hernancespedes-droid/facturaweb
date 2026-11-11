<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Auth\Guard;
use App\Modules\Clientes\ClientesController as C;

return function (Router $r): void {
    $r->get('/clientes', Guard::permiso('clientes.ver', [C::class, 'index']));
    $r->get('/clientes/nuevo', Guard::permiso('clientes.editar', [C::class, 'nuevo']));
    $r->post('/clientes', Guard::permiso('clientes.editar', [C::class, 'crear']));
    $r->get('/clientes/{codigo}', Guard::permiso('clientes.ver', [C::class, 'ver']));
    $r->post('/clientes/{codigo}', Guard::permiso('clientes.editar', [C::class, 'actualizar']));
    $r->post('/clientes/{codigo}/activo', Guard::permiso('clientes.editar', [C::class, 'activo']));
};
