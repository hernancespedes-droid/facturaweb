<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Auth\Guard;
use App\Modules\Licencia\LicenciaController as C;

// Master y Administrador ven el estado; cargar una licencia es solo del Master (lo controla cada acción).
return function (Router $r): void {
    $r->get('/sistema/licencia', Guard::auth([C::class, 'index']));
    $r->post('/sistema/licencia', Guard::auth([C::class, 'cargar']));
};
