<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Auth\Guard;
use App\Modules\Listados\ListadosController as C;

// Listados: permiso listados.ver (el Excel lo exige igual: es la misma ruta).
return function (Router $r): void {
    $r->get('/listados/iva-ventas', Guard::permiso('listados.ver', [C::class, 'ivaVentas']));
    $r->get('/listados/libro-iva-digital', Guard::permiso('listados.ver', [C::class, 'libroIva']));
    $r->get('/listados/libro-iva-digital/descargar', Guard::permiso('listados.ver', [C::class, 'libroIvaDescarga']));
};
