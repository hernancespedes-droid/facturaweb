<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Auth\Guard;
use App\Modules\Compras\ComprasController as C;
use App\Modules\Compras\OrdenesPagoController as OP;
use App\Modules\Compras\ComprasArcaController as AR;

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
    // órdenes de pago y cuenta corriente de proveedores (0.45.0)
    $r->get('/compras/pagos', Guard::permiso('compras.ver', [OP::class, 'lista']));
    $r->get('/compras/pagos/nueva', Guard::permiso('compras.pagar', [OP::class, 'nueva']));
    $r->post('/compras/pagos/nueva', Guard::permiso('compras.pagar', [OP::class, 'emitir']));
    $r->get('/compras/pagos/ver/{id}', Guard::permiso('compras.ver', [OP::class, 'ver']));
    $r->get('/compras/pagos/pdf/{id}', Guard::permiso('compras.ver', [OP::class, 'pdf']));
    $r->post('/compras/pagos/mail', Guard::permiso('compras.pagar', [OP::class, 'mail']));
    $r->post('/compras/pagos/anular', Guard::permiso('compras.pagar', [OP::class, 'anular']));
    $r->get('/compras/proveedores/cuenta', Guard::permiso('compras.ver', [OP::class, 'cuenta']));
    // QR, Mis Comprobantes de ARCA, Libro IVA Digital de compras y resultado del mes (0.47.0)
    $r->get('/compras/qr', Guard::permiso('compras.cargar', [AR::class, 'qr']));
    $r->post('/compras/qr', Guard::permiso('compras.cargar', [AR::class, 'qrLeer']));
    $r->get('/compras/mis-comprobantes', Guard::permiso('compras.ver', [AR::class, 'misComprobantes']));
    $r->post('/compras/mis-comprobantes', Guard::permiso('compras.cargar', [AR::class, 'misComprobantesImportar']));
    $r->post('/compras/desde-arca', Guard::permiso('compras.cargar', [AR::class, 'desdeArca']));
    $r->get('/compras/iva/libro', Guard::permiso('compras.ver', [AR::class, 'libroIva']));
    $r->get('/compras/resultado', Guard::permiso('compras.ver', [AR::class, 'resultado']));
    $r->get('/compras/a-pagar', Guard::permiso('compras.ver', [C::class, 'aPagar']));
    $r->get('/compras/analisis', Guard::permiso('compras.ver', [C::class, 'analisis']));
    $r->get('/compras/presupuesto', Guard::permiso('compras.ver', [C::class, 'presupuesto']));
    $r->get('/compras/iva', Guard::permiso('compras.ver', [C::class, 'iva']));
    $r->get('/compras/proveedores', Guard::permiso('compras.ver', [C::class, 'proveedores']));
    $r->get('/compras/proveedores/ficha', Guard::permiso('compras.ver', [C::class, 'proveedorFicha']));
    $r->post('/compras/proveedores/guardar', Guard::permiso('compras.cargar', [C::class, 'proveedorGuardar']));
    $r->get('/compras/buscar-articulo', Guard::permiso('compras.cargar', [C::class, 'buscarArticulo']));
    $r->get('/compras/buscar-proveedor', Guard::algun(['compras.ver', 'compras.cargar'], [C::class, 'buscarProveedor']));
    $r->get('/compras/centros', Guard::permiso('compras.centros', [C::class, 'centros']));
    $r->post('/compras/centros/centro', Guard::permiso('compras.centros', [C::class, 'centroGuardar']));
    $r->post('/compras/centros/presupuesto', Guard::permiso('compras.centros', [C::class, 'presupuestoGuardar']));
    $r->post('/compras/centros/copiar', Guard::permiso('compras.centros', [C::class, 'presupuestoCopiar']));
    $r->post('/compras/centros/concepto', Guard::permiso('compras.centros', [C::class, 'conceptoGuardar']));
    $r->post('/compras/centros/plantilla', Guard::permiso('compras.centros', [C::class, 'plantillaGuardar']));
    $r->post('/compras/centros/plantilla-borrar', Guard::permiso('compras.centros', [C::class, 'plantillaBorrar']));
};
