<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Pwa\PwaController as C;

// Instalar el sistema como aplicación (PWA, 0.62.0): sin sesión, cada instalación con su nombre, color y logo.
return function (Router $r): void {
    $r->get('/manifest.webmanifest', [C::class, 'manifiesto']);
    $r->get('/sw.js', [C::class, 'serviceWorker']);
    $r->get('/app/sin-conexion', [C::class, 'sinConexion']);
    $r->get('/app/icono-{tam}.png', [C::class, 'icono']);
};
