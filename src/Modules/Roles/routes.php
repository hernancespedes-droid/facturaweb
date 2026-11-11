<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Auth\Guard;
use App\Modules\Roles\RolesController as C;

return function (Router $r): void {
    $g = static fn (string $metodo) => Guard::permiso('usuarios.administrar', [C::class, $metodo]);
    $r->get('/roles', $g('index'));
    $r->get('/roles/nuevo', $g('nuevo'));
    $r->post('/roles', $g('crear'));
    $r->get('/roles/{id}', $g('editar'));
    $r->post('/roles/{id}', $g('actualizar'));
    $r->post('/roles/{id}/borrar', $g('borrar'));
};
