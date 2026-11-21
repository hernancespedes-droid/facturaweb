<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Auth\Guard;
use App\Modules\Stock\ReservasController as R;
use App\Modules\Stock\StockController as C;

// stock.ver: saldos y kardex. stock.mover: ingresos, egresos, ajustes y transferencias. stock.administrar: depósitos y recálculo.
return function (Router $r): void {
    $r->get('/stock', Guard::permiso('stock.ver', [C::class, 'index']));
    $r->get('/stock/movimientos', Guard::permiso('stock.ver', [C::class, 'movimientos']));
    $r->get('/stock/existencias', Guard::permiso('stock.ver', [C::class, 'existencias']));
    $r->get('/stock/buscar-cliente', Guard::permiso('stock.ver', [\App\Modules\Comprobantes\ComprobantesController::class, 'buscarCliente']));
    $r->get('/stock/ventas-sin-stock', Guard::permiso('stock.ver', [C::class, 'ventasSinStock']));
    $r->get('/stock/articulo/{codigo}', Guard::permiso('stock.ver', [C::class, 'kardex']));
    $r->get('/stock/buscar-articulo', Guard::permiso('stock.ver', [C::class, 'buscarArticulo']));
    $r->get('/stock/ajuste', Guard::permiso('stock.mover', [C::class, 'ajusteForm']));
    $r->post('/stock/ajuste', Guard::permiso('stock.mover', [C::class, 'ajuste']));
    $r->get('/stock/transferir', Guard::permiso('stock.mover', [C::class, 'transferirForm']));
    $r->post('/stock/transferir', Guard::permiso('stock.mover', [C::class, 'transferir']));
    $r->get('/stock/depositos', Guard::permiso('stock.administrar', [C::class, 'depositos']));
    $r->post('/stock/depositos', Guard::permiso('stock.administrar', [C::class, 'guardarDeposito']));
    // Reservas de las ventas en curso: las pide la pantalla que vende (comprobantes o kiosco)
    $r->post('/stock/reservar', Guard::algun(['comprobantes.emitir', 'kiosco.usar'], [R::class, 'reservar']));
    $r->post('/stock/liberar', Guard::algun(['comprobantes.emitir', 'kiosco.usar'], [R::class, 'liberar']));
    $r->post('/stock/recalcular', Guard::permiso('stock.administrar', [C::class, 'recalcular']));
};
