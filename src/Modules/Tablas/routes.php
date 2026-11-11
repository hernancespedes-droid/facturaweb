<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Auth\Guard;
use App\Modules\Tablas\TablasController as C;

// Cada acción verifica el permiso de su tabla (tablas.<tabla>) en TablasController::def().
return function (Router $r): void {
    $r->get('/tablas', Guard::auth([C::class, 'inicio']));
    $r->get('/tablas/{tabla}', Guard::auth([C::class, 'index']));
    $r->get('/tablas/{tabla}/nuevo', Guard::auth([C::class, 'nuevo']));
    $r->post('/tablas/{tabla}', Guard::auth([C::class, 'crear']));
    $r->get('/tablas/{tabla}/{id}', Guard::auth([C::class, 'editar']));
    $r->post('/tablas/{tabla}/{id}', Guard::auth([C::class, 'actualizar']));
    $r->post('/tablas/{tabla}/{id}/borrar', Guard::auth([C::class, 'borrar']));
};
