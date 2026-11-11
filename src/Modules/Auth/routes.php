<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Auth\AuthController as C;
use App\Modules\Auth\Guard;

return function (Router $r): void {
    $r->get('/login', [C::class, 'loginForm']);
    $r->post('/login', [C::class, 'login']);
    $r->post('/salir', [C::class, 'logout']);
    $r->get('/mi-clave', Guard::sesion([C::class, 'claveForm']));
    $r->post('/mi-clave', Guard::sesion([C::class, 'cambiarClave']));
};
