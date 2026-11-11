<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Articulos\ArticulosController as C;
use App\Modules\Auth\Guard;

return function (Router $r): void {
    $r->get('/articulos', Guard::permiso('articulos.ver', [C::class, 'index']));
    $r->get('/articulos/nuevo', Guard::permiso('articulos.editar', [C::class, 'nuevo']));
    $r->post('/articulos', Guard::permiso('articulos.editar', [C::class, 'crear']));
    $r->get('/articulos/ficha', Guard::permiso('articulos.ver', [C::class, 'ver']));
    $r->post('/articulos/ficha', Guard::permiso('articulos.editar', [C::class, 'actualizar']));
};
