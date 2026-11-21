<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Auth\Guard;
use App\Modules\Configuracion\ConfiguracionController as C;

return function (Router $r): void {
    $r->get('/configuracion', Guard::permiso('configuracion.administrar', [C::class, 'index']));
    $r->post('/configuracion', Guard::permiso('configuracion.administrar', [C::class, 'guardar']));
    $r->post('/configuracion/logo', Guard::permiso('configuracion.administrar', [C::class, 'subirLogo']));
    $r->post('/configuracion/logo/quitar', Guard::permiso('configuracion.administrar', [C::class, 'quitarLogo']));
    // Apariencia (0.31.0): logo, color y nombre de la instalación en todo el sistema.
    $r->post('/configuracion/apariencia', Guard::permiso('configuracion.administrar', [C::class, 'guardarApariencia']));
    $r->post('/configuracion/apariencia/logo', Guard::permiso('configuracion.administrar', [C::class, 'subirLogoPantalla']));
    $r->post('/configuracion/apariencia/logo/quitar', Guard::permiso('configuracion.administrar', [C::class, 'quitarLogoPantalla']));
    // Sin sesión: la pantalla de ingreso y el portal también muestran el logo (no es un dato reservado).
    $r->get('/marca/logo', [C::class, 'marcaLogo']);
    $r->get('/marca/icono', [C::class, 'marcaIcono']);
};
