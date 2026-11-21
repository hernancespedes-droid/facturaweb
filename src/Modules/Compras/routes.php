<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Auth\Guard;
use App\Modules\Compras\ComprasController as C;

// Compras y centros de costos (0.43.0). compras.ver: tablero, compras y listados. compras.cargar: cargar y anular compras, proveedores.
// compras.pagar: pagar compras a cuenta corriente. compras.centros: centros, presupuestos, conceptos y plantillas. El Excel va por la misma ruta (?excel=1).
return function (Router $r): void {
    $r->get('/compras', Guard::permiso('compras.ver', [C::class, 'tablero']));
    $r->get('/compras/lista', Guard::permiso('compras.ver', [C::class, 'lista']));
    $r->get('/compras/nueva', Guard::permiso('compras.cargar', [C::class, 'nueva']));
    $r->post('/compras/nueva', Guard::permiso('compras.cargar', [C::class, 'grabar']));
    $r->get('/compras/ver/{id}', Guard::permiso('compras.ver', [C::class, 'ver']));
    $r->get('/compras/adjunto', Guard::permiso('compras.ver', [C::class, 'adjunto']));
    $r->post('/compras/anular', Guard::permiso('compras.cargar', [C::class, 'anular']));
    $r->post('/compras/revisada', Guard::permiso('compras.cargar', [C::class, 'revisada']));
    $r->post('/compras/pagar', Guard::permiso('compras.pagar', [C::class, 'pagar']));
    $r->get('/compras/a-pagar', Guard::permiso('compras.ver', [C::class, 'aPagar']));
    $r->get('/compras/analisis', Guard::permiso('compras.ver', [C::class, 'analisis']));
    $r->get('/compras/presupuesto', Guard::permiso('compras.ver', [C::class, 'presupuesto']));
    $r->get('/compras/iva', Guard::permiso('compras.ver', [C::class, 'iva']));
    $r->get('/compras/proveedores', Guard::permiso('compras.ver', [C::class, 'proveedores']));
    $r->get('/compras/proveedores/ficha', Guard::permiso('compras.ver', [C::class, 'proveedorFicha']));
    $r->post('/compras/proveedores/guardar', Guard::permiso('compras.cargar', [C::class, 'proveedorGuardar']));
    $r->get('/compras/buscar-proveedor', Guard::algun(['compras.ver', 'compras.cargar'], [C::class, 'buscarProveedor']));
    $r->get('/compras/centros', Guard::permiso('compras.centros', [C::class, 'centros']));
    $r->post('/compras/centros/centro', Guard::permiso('compras.centros', [C::class, 'centroGuardar']));
    $r->post('/compras/centros/presupuesto', Guard::permiso('compras.centros', [C::class, 'presupuestoGuardar']));
    $r->post('/compras/centros/copiar', Guard::permiso('compras.centros', [C::class, 'presupuestoCopiar']));
    $r->post('/compras/centros/concepto', Guard::permiso('compras.centros', [C::class, 'conceptoGuardar']));
    $r->post('/compras/centros/plantilla', Guard::permiso('compras.centros', [C::class, 'plantillaGuardar']));
    $r->post('/compras/centros/plantilla-borrar', Guard::permiso('compras.centros', [C::class, 'plantillaBorrar']));
};
