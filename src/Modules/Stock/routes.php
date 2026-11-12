<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Auth\Guard;
use App\Modules\Stock\StockController as C;

// stock.ver: saldos y kardex. stock.mover: ingresos, egresos, ajustes y transferencias. stock.administrar: depósitos y recálculo.
return function (Router $r): void {
    $r->get('/stock', Guard::permiso('stock.ver', [C::class, 'index']));
    $r->get('/stock/articulo/{codigo}', Guard::permiso('stock.ver', [C::class, 'kardex']));
    $r->get('/stock/ajuste', Guard::permiso('stock.mover', [C::class, 'ajusteForm']));
    $r->post('/stock/ajuste', Guard::permiso('stock.mover', [C::class, 'ajuste']));
    $r->get('/stock/transferir', Guard::permiso('stock.mover', [C::class, 'transferirForm']));
    $r->post('/stock/transferir', Guard::permiso('stock.mover', [C::class, 'transferir']));
    $r->get('/stock/depositos', Guard::permiso('stock.administrar', [C::class, 'depositos']));
    $r->post('/stock/depositos', Guard::permiso('stock.administrar', [C::class, 'guardarDeposito']));
    $r->post('/stock/recalcular', Guard::permiso('stock.administrar', [C::class, 'recalcular']));
};
