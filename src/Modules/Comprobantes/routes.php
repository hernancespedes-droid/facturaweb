<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Auth\Guard;
use App\Modules\Comprobantes\ComprobantesController as C;
use App\Modules\Comprobantes\ImpresionController as I;

// Consulta e impresión: comprobantes.ver. Emitir y buscadores: comprobantes.emitir. Las rutas fijas van antes que /{id}.
return function (Router $r): void {
    $ver = 'comprobantes.ver';
    $emitir = 'comprobantes.emitir';
    $r->get('/comprobantes', Guard::permiso($ver, [C::class, 'index']));
    $r->get('/comprobantes/nuevo', Guard::permiso($emitir, [C::class, 'nuevo']));
    $r->post('/comprobantes/editar', Guard::permiso($emitir, [C::class, 'editar']));
    $r->post('/comprobantes/revisar', Guard::permiso($emitir, [C::class, 'revisar']));
    $r->post('/comprobantes', Guard::permiso($emitir, [C::class, 'emitir']));
    $r->get('/comprobantes/buscar-cliente', Guard::permiso($emitir, [C::class, 'buscarCliente']));
    $r->get('/comprobantes/buscar-articulo', Guard::permiso($emitir, [C::class, 'buscarArticulo']));
    $r->get('/comprobantes/asociables', Guard::permiso($emitir, [C::class, 'asociables']));
    $r->get('/comprobantes/{id}', Guard::permiso($ver, [C::class, 'ver']));
    $r->get('/comprobantes/{id}/imprimir', Guard::permiso($ver, [I::class, 'imprimir']));
    $r->get('/comprobantes/{id}/pdf', Guard::permiso($ver, [I::class, 'pdf']));
    $r->post('/comprobantes/{id}/mail', Guard::permiso($emitir, [I::class, 'mail']));
};
