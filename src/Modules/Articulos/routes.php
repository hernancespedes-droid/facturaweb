<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Articulos\ArticulosController as C;
use App\Modules\Auth\Guard;

return function (Router $r): void {
    $r->get('/articulos', Guard::permiso('articulos.ver', [C::class, 'index']));
    $r->get('/articulos/nuevo', Guard::permiso('articulos.editar', [C::class, 'nuevo']));
    $r->post('/articulos', Guard::permiso('articulos.editar', [C::class, 'crear']));
    // Actualizar precios con los márgenes (D-130): también es donde preguntan el stock, las compras y el importador después de cambiar costos
    $r->get('/articulos/precios', Guard::permiso('articulos.precios', [C::class, 'precios']));
    $r->post('/articulos/precios', Guard::permiso('articulos.precios', [C::class, 'actualizarPrecios']));
    // Actualización de precios por filtro (coeficiente, porcentaje o monto fijo; D-144…D-146), con vista previa y deshacer la última
    $r->get('/articulos/actualizacion', Guard::permiso('articulos.precios', [\App\Modules\Articulos\ActualizacionController::class, 'form']));
    $r->post('/articulos/actualizacion', Guard::permiso('articulos.precios', [\App\Modules\Articulos\ActualizacionController::class, 'confirmar']));
    $r->post('/articulos/actualizacion/deshacer', Guard::permiso('articulos.precios', [\App\Modules\Articulos\ActualizacionController::class, 'deshacer']));
    $r->get('/articulos/actualizacion/buscar-articulo', Guard::permiso('articulos.precios', [\App\Modules\Articulos\ActualizacionController::class, 'buscarArticulo']));
    $r->get('/articulos/ficha', Guard::permiso('articulos.ver', [C::class, 'ver']));
    $r->post('/articulos/ficha', Guard::permiso('articulos.editar', [C::class, 'actualizar']));
    // Foto del artículo: la ven todos los que trabajan con artículos (tarjetas del kiosco, buscadores, fichas); subirla o quitarla es de quien edita
    $r->get('/articulos/foto', Guard::algun(['articulos.ver', 'comprobantes.ver', 'comprobantes.emitir', 'kiosco.usar', 'cambios.hacer', 'stock.ver'], [C::class, 'foto']));
    $r->post('/articulos/foto', Guard::permiso('articulos.editar', [C::class, 'subirFoto']));
    $r->post('/articulos/foto/quitar', Guard::permiso('articulos.editar', [C::class, 'quitarFoto']));
};
