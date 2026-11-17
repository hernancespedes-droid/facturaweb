<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Portal\PortalController as P;

// Portal de clientes: sin el ingreso de usuarios del sistema (cada acción controla el acceso del cliente). Apagado = 404.
return function (Router $r): void {
    $r->get('/portal', [P::class, 'inicio']);
    $r->post('/portal', [P::class, 'pedir']);
    $r->get('/portal/acceso/{token}', [P::class, 'acceso']);
    $r->get('/portal/comprobantes', [P::class, 'comprobantes']);
    $r->get('/portal/comprobantes/{id}/pdf', [P::class, 'pdf']);
    $r->post('/portal/salir', [P::class, 'salir']);
};
