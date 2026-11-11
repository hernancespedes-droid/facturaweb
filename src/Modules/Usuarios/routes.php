<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Auth\Guard;
use App\Modules\Usuarios\UsuariosController as C;

return function (Router $r): void {
    $p = 'usuarios.administrar';
    $r->get('/usuarios', Guard::permiso($p, [C::class, 'index']));
    $r->get('/usuarios/nuevo', Guard::permiso($p, [C::class, 'nuevo']));
    $r->post('/usuarios', Guard::permiso($p, [C::class, 'crear']));
    $r->get('/usuarios/{id}', Guard::permiso($p, [C::class, 'editar']));
    $r->post('/usuarios/{id}', Guard::permiso($p, [C::class, 'actualizar']));
    $r->post('/usuarios/{id}/activo', Guard::permiso($p, [C::class, 'activo']));
    $r->post('/usuarios/{id}/clave', Guard::permiso($p, [C::class, 'resetClave']));
    $r->post('/usuarios/{id}/desbloquear', Guard::permiso($p, [C::class, 'desbloquear']));
};
