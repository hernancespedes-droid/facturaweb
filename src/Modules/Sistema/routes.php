<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Auth\Guard;
use App\Modules\Sistema\SistemaController as C;

// Master y Administrador (cada acción controla el rol; migrar es solo del Master).
return function (Router $r): void {
    $r->get('/sistema', Guard::auth([C::class, 'index']));
    $r->post('/sistema/migrar', Guard::auth([C::class, 'migrar']));
};
