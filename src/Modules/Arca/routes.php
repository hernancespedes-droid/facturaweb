<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Arca\ArcaController as C;
use App\Modules\Auth\Guard;

// ARCA: permiso arca.administrar; borrar pruebas lo vuelve a verificar como Master.
return function (Router $r): void {
    $p = 'arca.administrar';
    $r->get('/arca', Guard::permiso($p, [C::class, 'index']));
    $r->post('/arca/diagnostico', Guard::permiso($p, [C::class, 'diagnostico']));
    $r->post('/arca/borrar-pruebas', Guard::permiso($p, [C::class, 'borrarPruebas']));
    $r->post('/arca/{id}/recuperar', Guard::permiso($p, [C::class, 'recuperar']));
};
