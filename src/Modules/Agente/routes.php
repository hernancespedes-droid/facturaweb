<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Agente\AgenteController as A;
use App\Modules\Agente\ApiController as P;
use App\Modules\Auth\Guard;

return function (Router $r): void {
    // API del agente: sin sesión (la clave va en el encabezado). Las rutas /api/ no abren sesión ni piden CSRF.
    $r->get('/api/v1/ping', [P::class, 'ping']);
    $r->post('/api/v1/vfp/comprobantes', [P::class, 'comprobantes']);
    $r->post('/api/v1/vfp/maestros', [P::class, 'maestros']);
    $r->get('/api/v1/vfp/pendientes', [P::class, 'pendientes']);
    $r->post('/api/v1/vfp/confirmar', [P::class, 'confirmar']);
    // Administración de claves
    $r->get('/agente', Guard::permiso('agente.administrar', [A::class, 'index']));
    $r->post('/agente', Guard::permiso('agente.administrar', [A::class, 'crear']));
    $r->get('/agente/vfp/lote', Guard::permiso('agente.administrar', [A::class, 'descargarLote']));
    $r->post('/agente/vfp/reintentar', Guard::permiso('agente.administrar', [A::class, 'reintentarVfp']));
    $r->post('/agente/{id}/revocar', Guard::permiso('agente.administrar', [A::class, 'revocar']));
};
