<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Auth\Guard;
use App\Modules\Configuracion\ConfiguracionController as C;

return function (Router $r): void {
    $r->get('/configuracion', Guard::permiso('configuracion.administrar', [C::class, 'index']));
    $r->post('/configuracion', Guard::permiso('configuracion.administrar', [C::class, 'guardar']));
};
