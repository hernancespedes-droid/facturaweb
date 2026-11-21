<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Auth\Guard;
use App\Modules\Importacion\ImportacionController as C;

// importar.datos para entrar; cada destino pide además su permiso (clientes.editar, articulos.editar, stock.mover): ver Destinos::PERMISO.
return function (Router $r): void {
    $r->get('/importar', Guard::permiso('importar.datos', [C::class, 'index']));
    $r->get('/importar/plantilla', Guard::permiso('importar.datos', [C::class, 'plantilla']));
    $r->post('/importar/subir', Guard::permiso('importar.datos', [C::class, 'subir']));
    $r->get('/importar/columnas', Guard::permiso('importar.datos', [C::class, 'columnas']));
    $r->post('/importar/procesar', Guard::permiso('importar.datos', [C::class, 'procesar']));
    $r->get('/importar/resultado', Guard::permiso('importar.datos', [C::class, 'resultado']));
    $r->post('/importar/modelo/borrar', Guard::permiso('importar.datos', [C::class, 'borrarModelo']));
};
