<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Auth\Guard;
use App\Modules\Sistema\SistemaController as C;

// Master y Administrador (cada acción controla el rol; migrar es solo del Master).
return function (Router $r): void {
    $r->get('/sistema', Guard::auth([C::class, 'index']));
    $r->post('/sistema/migrar', Guard::auth([C::class, 'migrar']));
    $r->get('/sistema/instalaciones', Guard::auth([C::class, 'instalaciones']));
    $r->post('/sistema/instalaciones', Guard::auth([C::class, 'instalacionesGuardar']));
    $r->post('/sistema/instalaciones/revisar', Guard::auth([C::class, 'instalacionesRevisar']));
    $r->post('/sistema/instalaciones/{id}/borrar', Guard::auth([C::class, 'instalacionesBorrar']));
    $r->post('/sistema/migrar-vfp', Guard::auth([C::class, 'migrarVfp']));
    // Instalación sin SSH: solo con instalar_token en config/local.php y sin ningún Master todavía.
    $r->get('/instalar', [\App\Modules\Sistema\InstalarController::class, 'form']);
    $r->post('/instalar', [\App\Modules\Sistema\InstalarController::class, 'instalar']);
};
