<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Auth\Guard;
use App\Modules\Soporte\SoporteApi as A;
use App\Modules\Soporte\SoporteController as C;

// Soporte: cada instalación abre y sigue sus tickets (soporte.usar); la bandeja de O&S es solo del Master en la principal (lo controla cada acción).
// La API la usan las instalaciones de clientes (sin sesión ni CSRF, con la clave de su config) y solo responde en la principal.
return function (Router $r): void {
    $r->get('/api/v1/soporte/tickets', [A::class, 'listar']);
    $r->post('/api/v1/soporte/tickets', [A::class, 'crear']);
    $r->get('/api/v1/soporte/tickets/{id}', [A::class, 'ver']);
    $r->post('/api/v1/soporte/tickets/{id}/responder', [A::class, 'responder']);
    $r->post('/api/v1/soporte/tickets/{id}/estado', [A::class, 'estado']);
    $r->get('/api/v1/soporte/adjuntos/{id}', [A::class, 'adjuntoVer']);

    $r->get('/soporte/bandeja', Guard::auth([C::class, 'bandeja']));
    $r->post('/soporte/bandeja/configuracion', Guard::auth([C::class, 'configuracion']));
    $r->get('/soporte/bandeja/adjunto/{id}', Guard::auth([C::class, 'bandejaAdjunto']));
    $r->get('/soporte/bandeja/{id}', Guard::auth([C::class, 'bandejaVer']));
    $r->post('/soporte/bandeja/{id}/responder', Guard::auth([C::class, 'bandejaResponder']));

    $r->get('/soporte', Guard::permiso('soporte.usar', [C::class, 'index']));
    $r->get('/soporte/nuevo', Guard::permiso('soporte.usar', [C::class, 'nuevo']));
    $r->post('/soporte/nuevo', Guard::permiso('soporte.usar', [C::class, 'crear']));
    $r->get('/soporte/adjunto/{id}', Guard::permiso('soporte.usar', [C::class, 'adjunto']));
    $r->get('/soporte/{id}', Guard::permiso('soporte.usar', [C::class, 'ver']));
    $r->post('/soporte/{id}/responder', Guard::permiso('soporte.usar', [C::class, 'responder']));
    $r->post('/soporte/{id}/estado', Guard::permiso('soporte.usar', [C::class, 'estado']));
};
