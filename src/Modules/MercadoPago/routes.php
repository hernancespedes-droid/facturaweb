<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Auth\Guard;
use App\Modules\MercadoPago\IngresosController as I;
use App\Modules\MercadoPago\MercadoPagoController as C;

// mercadopago.cobrar: cobrar con QR o link y ver los propios. mercadopago.administrar: ver los de todos y reintentar emisiones.
return function (Router $r): void {
    // Notificación de Mercado Pago: sin sesión ni CSRF (ruta /api/), se autentica con la firma del encabezado x-signature.
    $r->post('/api/v1/mercadopago/webhook', [C::class, 'notificacion']);
    // Ingresos de dinero a la cuenta (cualquier pago que entra, no solo las ventas cobradas desde acá): mercadopago.ingresos
    $r->get('/mercadopago/ingresos', Guard::permiso('mercadopago.ingresos', [I::class, 'index']));
    $r->get('/mercadopago/ingresos/novedades', Guard::permiso('mercadopago.ingresos', [I::class, 'novedades']));
    $r->post('/mercadopago/ingresos/vistos', Guard::permiso('mercadopago.ingresos', [I::class, 'vistos']));
    $r->post('/mercadopago/ingresos/simular', Guard::permiso('mercadopago.ingresos', [I::class, 'simular']));
    $r->get('/mercadopago', Guard::permiso('mercadopago.cobrar', [C::class, 'index']));
    $r->post('/mercadopago/iniciar', Guard::permiso('mercadopago.cobrar', [C::class, 'iniciar']));
    $r->get('/mercadopago/cobro/{token}', Guard::permiso('mercadopago.cobrar', [C::class, 'cobro']));
    $r->get('/mercadopago/cobro/{token}/estado', Guard::permiso('mercadopago.cobrar', [C::class, 'estado']));
    $r->post('/mercadopago/cobro/{token}/cancelar', Guard::permiso('mercadopago.cobrar', [C::class, 'cancelar']));
    $r->post('/mercadopago/{id}/reintentar', Guard::permiso('mercadopago.administrar', [C::class, 'reintentar']));
    $r->get('/mercadopago/simulador/{token}', Guard::permiso('mercadopago.cobrar', [C::class, 'simulador']));
    $r->post('/mercadopago/simulador/{token}/pagar', Guard::permiso('mercadopago.cobrar', [C::class, 'simuladorPagar']));
};
