<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\Listados\Estadistica;
use App\Modules\Listados\ListaCostos;
use App\Modules\Stock\Existencias;
use App\Modules\Stock\MovimientosStock;
use App\Modules\Stock\StockService;

/**
 * Listados de 0.32.0: estadística de ventas (con ranking), rentabilidad, costos y márgenes, movimientos de stock (trazabilidad) y existencias a una fecha.
 * Costo grabado al emitir (D-62), presupuestos sin doble conteo y NC restando (D-63), permisos: sin listados.costos no se ve ningún costo.
 */
final class ListadosEstadisticaTest extends ComprobantesTestCase
{
    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = Db::pdo();
        $pdo->exec('DELETE FROM stock_movimientos');
        $pdo->exec('DELETE FROM stock_saldos');
        $pdo->exec("UPDATE configuracion SET valor = '1' WHERE clave = 'stock.controlar'");
        \App\Modules\Configuracion\Configuracion::reset();
        $pdo->exec("UPDATE articulos SET costo = 40, margen = 150, rubro = NULL WHERE codigo = 'T-ART'");
        $pdo->exec("INSERT INTO articulos (codigo, descripcion, alicuota, precio_unitario, costo, moneda) VALUES ('T-USD', 'IMPORTADO', '21', 30, 10, 'u\$s'), ('T-BAJO', 'EN OFERTA', '21', 50, 80, '\$')");
        $pdo->exec("DELETE FROM cotizacion_dolar WHERE fecha = '2001-01-01'");
        $pdo->exec("INSERT INTO cotizacion_dolar (fecha, importe) VALUES ('2001-01-01', 2)");
        $this->stock = new StockService();
        $this->stock->cargar('INGRESO', 'T-ART', $this->stock->depositoPrincipal(), '10', 'unidades', 'stock inicial', null);
    }

    protected function tearDown(): void
    {
        $pdo = Db::pdo();
        $pdo->exec('DELETE FROM stock_movimientos');
        $pdo->exec('DELETE FROM stock_saldos');
        $pdo->exec("UPDATE configuracion SET valor = '0' WHERE clave = 'stock.controlar'");
        $pdo->exec("DELETE FROM cotizacion_dolar WHERE fecha = '2001-01-01'");
        $pdo->exec("DELETE FROM rol_permisos WHERE rol_id = (SELECT id FROM roles WHERE codigo = 'operador') AND permiso_id = (SELECT id FROM permisos WHERE codigo = 'listados.ver')");
        \App\Modules\Configuracion\Configuracion::reset();
        parent::tearDown();
    }

    private function hoy(array $extra = []): array
    {
        return $extra + ['desde' => date('d/m/Y'), 'hasta' => date('d/m/Y')];
    }

    /** Factura 2 x 100, NC de 1, presupuesto 2 x 121 (neto 200) facturado después (la factura no cuenta). @return array{fc:array,pr:array} */
    private function cargarVentas(): array
    {
        $fc = $this->emitir($this->pedido());
        $this->emitir($this->pedido(['tipo' => 'NC', 'asociado' => (string) $fc['id'], 'items' => [['articulo' => 'T-ART', 'descripcion' => '', 'cantidad' => '1', 'precio' => '100', 'alicuota' => '21']]]));
        $pr = $this->emitir($this->pedido(['tipo' => 'PR', 'items' => [['articulo' => 'T-ART', 'descripcion' => '', 'cantidad' => '2', 'precio' => '121', 'alicuota' => '21']]]));
        $r = $this->svc->armar(['tipo' => 'FC', 'presupuesto' => (string) $pr['id']]);
        $this->assertSame([], $r['errores']);
        $this->svc->emitir($r['cbte'], bin2hex(random_bytes(16)), 0);
        return ['fc' => $fc, 'pr' => $pr];
    }

    public function testElCostoSeGrabaAlEmitirEnPesos(): void
    {
        $fc = $this->emitir($this->pedido(['items' => [
            ['articulo' => 'T-ART', 'descripcion' => '', 'cantidad' => '2', 'precio' => '100', 'alicuota' => '21'],
            ['articulo' => 'T-USD', 'descripcion' => '', 'cantidad' => '1', 'precio' => '60', 'alicuota' => '21'],
            ['descripcion' => 'Flete', 'cantidad' => '1', 'precio' => '10', 'alicuota' => '21'],
        ]]));
        $st = Db::pdo()->prepare('SELECT articulo, costo FROM estadistica WHERE subdiario_id = ? ORDER BY orden');
        $st->execute([$fc['id']]);
        $c = array_map(static fn (array $x) => [$x['articulo'], (float) $x['costo']], $st->fetchAll());
        $hay = (float) Db::pdo()->query("SELECT importe FROM cotizacion_dolar WHERE fecha <= CURDATE() ORDER BY fecha DESC LIMIT 1")->fetchColumn();
        $this->assertSame([['T-ART', 40.0], ['T-USD', round(10 * $hay, 4)], [null, 0.0]], $c, 'costo unitario en pesos (el de dólares con la cotización); el renglón libre sin costo');
        // si después cambia el costo de la ficha, la venta conserva el suyo
        Db::pdo()->exec("UPDATE articulos SET costo = 70 WHERE codigo = 'T-ART'");
        $r = Estadistica::resumen($this->hoy(['articulo' => 'T-ART']));
        $this->assertSame(80.0, $r['totales']['costo']);
        $this->assertSame(0, $r['totales']['estimados']);
    }

    public function testEstadisticaRestaLasNotasYNoCuentaDosVecesElPresupuesto(): void
    {
        $this->cargarVentas();
        $r = Estadistica::resumen($this->hoy());
        $t = $r['totales'];
        // FC 2 − NC 1 + PR 2 = 3 unidades; neto 200 − 100 + 200 = 300; la factura del presupuesto no suma
        $this->assertSame([3.0, 300.0, 363.0, 3], [$t['unidades'], $t['neto'], $t['total'], $t['comprobantes']], 'unidades, neto, con IVA y comprobantes');
        $this->assertSame([120.0, 180.0, 60.0], [$t['costo'], $t['margen'], $t['margen_pct']], 'costo 3 x 40, margen y margen %');
        $this->assertCount(1, $r['filas']);
        $this->assertSame(['T-ART', 'ARTICULO DE PRUEBA', 100.0], [$r['filas'][0]['grupo'], $r['filas'][0]['nombre'], $r['filas'][0]['pct_venta']]);
        // solo fiscales: sin el presupuesto (y tampoco la factura que salió de él)
        $fis = Estadistica::resumen($this->hoy(['fiscales' => '1']))['totales'];
        $this->assertSame([1.0, 100.0], [$fis['unidades'], $fis['neto']]);
        // por cliente y por mes
        $cli = Estadistica::resumen($this->hoy(['agrupar' => 'cliente']));
        $this->assertSame(['990001', 'T CLIENTE RI SA'], [$cli['filas'][0]['grupo'], $cli['filas'][0]['nombre']]);
        $mes = Estadistica::resumen($this->hoy(['agrupar' => 'mes']));
        $this->assertSame(date('Y-m'), $mes['filas'][0]['grupo']);
        // otro período: nada
        $this->assertSame([], Estadistica::resumen(['desde' => '01/01/2001', 'hasta' => '02/01/2001'])['filas']);
        // detalle: un renglón por ítem (FC, NC, PR), la NC en negativo
        $d = Estadistica::detalle($this->hoy(['orden' => 'unidades']), 1, 50);
        $this->assertSame(3, $d['total']);
        $this->assertContains(-1.0, array_map(static fn (array $x) => (float) $x['unidades'], $d['filas']));
    }

    public function testRankingYCostoEstimado(): void
    {
        $this->cargarVentas();
        $this->emitir($this->pedido(['items' => [['articulo' => 'T-BAJO', 'descripcion' => '', 'cantidad' => '5', 'precio' => '50', 'alicuota' => '21']]]));
        $porImporte = Estadistica::resumen($this->hoy(['primeros' => '1']));
        $this->assertSame(['T-ART'], array_column($porImporte['filas'], 'grupo'), 'el primero por importe (300 contra 250)');
        $this->assertSame(550.0, $porImporte['totales']['neto'], 'los totales son de todo el período');
        $porCantidad = Estadistica::resumen($this->hoy(['primeros' => '1', 'por' => 'cantidad']));
        $this->assertSame(['T-BAJO'], array_column($porCantidad['filas'], 'grupo'), 'el primero por cantidad (5 contra 3)');
        $bajo = Estadistica::resumen($this->hoy(['articulo' => 'T-BAJO']))['filas'][0];
        $this->assertSame([400.0, -150.0], [$bajo['costo'], $bajo['margen']], 'vendido debajo del costo: margen negativo');
        // una venta anterior a 0.32.0 (sin costo grabado) usa el costo de hoy y queda marcada
        Db::pdo()->exec("UPDATE estadistica SET costo = 0 WHERE articulo = 'T-BAJO'");
        Db::pdo()->exec("UPDATE articulos SET costo = 60 WHERE codigo = 'T-BAJO'");
        $est = Estadistica::resumen($this->hoy(['articulo' => 'T-BAJO']));
        $this->assertSame([300.0, 1], [$est['filas'][0]['costo'], (int) $est['filas'][0]['estimados']]);
    }

    public function testListaDeCostosYMargenes(): void
    {
        $r = ListaCostos::listar(['q' => 'T-', 'precios' => 'neto'], 1, 50);
        $por = array_column($r['filas'], null, 'codigo');
        $this->assertSame(150.0, round((float) $por['T-ART']['margen_real'], 2), '(100 − 40) / 40');
        $this->assertTrue((float) $por['T-BAJO']['margen_real'] < 0, 'precio debajo del costo');
        $this->assertSame(['T-BAJO'], array_column(ListaCostos::listar(['q' => 'T-', 'ver' => 'bajo_costo', 'precios' => 'neto'], 1, 50)['filas'], 'codigo'));
        $this->assertSame(['T-BAJO'], array_column(ListaCostos::listar(['q' => 'T-', 'ver' => 'margen_menor', 'margen' => '10', 'precios' => 'neto'], 1, 50)['filas'], 'codigo'));
        // con precios con IVA, el margen sale del precio sin IVA
        $iva = array_column(ListaCostos::listar(['q' => 'T-ART', 'precios' => 'iva'], 1, 50)['filas'], null, 'codigo');
        $this->assertSame(round((100 / 1.21 - 40) / 40 * 100, 2), round((float) $iva['T-ART']['margen_real'], 2));
    }

    public function testMovimientosDeStockConSaldoCorridoYResumen(): void
    {
        $dep = $this->stock->depositoPrincipal();
        Db::pdo()->exec("UPDATE stock_movimientos SET fecha = DATE_SUB(NOW(), INTERVAL 40 DAY)"); // el stock inicial es de antes del período
        $ventas = $this->cargarVentas(); // FC −2, NC +1, PR −2
        $this->stock->cargar('EGRESO', 'T-ART', $dep, '1', 'unidades', 'rotura', null);
        $r = MovimientosStock::listar($this->hoy(['articulo' => 'T-ART', 'orden' => 'fecha']), 1, 50);
        $this->assertSame(4, $r['total']);
        $this->assertSame([10.0, 1.0, 5.0, 6.0], [$r['inicial'], $r['entradas'], $r['salidas'], $r['final']], 'inicial, entradas, salidas y final');
        $this->assertSame([8.0, 9.0, 7.0, 6.0], array_map(static fn (array $x) => (float) $x['saldo'], $r['filas']), 'saldo corrido en orden de fecha');
        $this->assertSame((int) $ventas['fc']['id'], (int) $r['filas'][0]['subdiario_id'], 'cada movimiento apunta a su comprobante');
        $this->assertSame('rotura', $r['filas'][3]['motivo']);
        // con un filtro por origen no hay saldo corrido
        $this->assertNull(MovimientosStock::listar($this->hoy(['articulo' => 'T-ART', 'origen' => 'FC']), 1, 50)['inicial']);
        $this->assertSame(1, MovimientosStock::listar($this->hoy(['origen' => 'EGRESO']), 1, 50)['total']);
        // resumido por artículo y depósito, valorizado a costo de hoy (40)
        $res = MovimientosStock::resumen($this->hoy(), 1, 50);
        $this->assertSame(1, $res['total']);
        $x = $res['filas'][0];
        $this->assertSame([10.0, 1.0, 5.0, 6.0, 240.0], [(float) $x['inicial'], (float) $x['entradas'], (float) $x['salidas'], (float) $x['final'], round((float) $x['valorizado'], 2)]);
        // existencias: hoy 6; hace 20 días, 10 (solo el ingreso inicial)
        $deps = $this->stock->depositos();
        $hoy = Existencias::listar(['q' => 'T-ART'], $deps, 1, 50);
        $this->assertSame(6.0, (float) $hoy['filas'][0]['total']);
        $antes = Existencias::listar(['q' => 'T-ART', 'fecha' => date('d/m/Y', strtotime('-20 days')), 'valorizar' => 'costo'], $deps, 1, 50);
        $this->assertSame([10.0, 400.0], [(float) $antes['filas'][0]['total'], round((float) $antes['filas'][0]['valorizado'], 2)]);
        Db::pdo()->exec("UPDATE articulos SET stock_minimo_unidades = 8 WHERE codigo = 'T-ART'");
        $this->assertSame(['T-ART'], array_column(Existencias::listar(['q' => 'T-', 'mostrar' => 'bajo'], $deps, 1, 50)['filas'], 'codigo'), 'bajo el mínimo');
        $this->assertSame([], Existencias::listar(['q' => 'T-', 'mostrar' => 'negativos'], $deps, 1, 50)['filas']);
    }

    public function testPantallasYPermisos(): void
    {
        $this->cargarVentas();
        $this->loginComo($this->crearUsuario('t_adm_est', 'admin'));
        foreach (['/listados/estadistica' => ['Estadística de ventas', 'ARTICULO DE PRUEBA', 'Margen'], '/listados/rentabilidad' => ['Rentabilidad de ventas', '% del margen'],
            '/listados/costos' => ['Costos y márgenes', 'Margen real'], '/stock/movimientos' => ['Movimientos de stock', 'Saldo inicial'],
            '/stock/existencias' => ['Existencias por depósito', 'Valorizar']] as $ruta => $textos) {
            $res = $this->get($ruta, $ruta === '/stock/movimientos' ? ['articulo' => 'T-ART'] : []);
            $this->assertSame(200, $res->status, $ruta);
            foreach ($textos as $txt) {
                $this->assertStringContainsString($txt, $res->body, "{$ruta}: {$txt}");
            }
        }
        $this->assertStringContainsString('Detalle: cada renglón vendido', $this->get('/listados/estadistica', $this->hoy(['detalle' => '1']))->body);
        $this->assertSame(200, $this->get('/listados/estadistica', ['agrupar' => 'subrubro', 'excel' => 'grilla'])->status, 'Excel de la grilla');
        $this->assertStringContainsString('ARTICULO DE PRUEBA', $this->get('/listados/buscar-articulo', ['q' => 'T-ART'])->body);

        // sin listados.ver: nada; con listados.ver pero sin listados.costos: la estadística sin ningún costo
        $op = $this->crearUsuario('t_op_est', 'operador');
        $this->loginComo($op);
        foreach (['/listados/estadistica', '/listados/rentabilidad', '/listados/costos', '/listados/buscar-cliente'] as $ruta) {
            $this->assertSame(403, $this->get($ruta)->status, "operador: {$ruta}");
        }
        Db::pdo()->exec("INSERT IGNORE INTO rol_permisos (rol_id, permiso_id) SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'listados.ver' WHERE r.codigo = 'operador'");
        $this->loginComo($op);
        $res = $this->get('/listados/estadistica');
        $this->assertSame(200, $res->status);
        foreach (['Margen', 'Costo'] as $txt) {
            $this->assertStringNotContainsString('>' . $txt, $res->body, "sin listados.costos no ve: {$txt}");
        }
        $this->assertSame(403, $this->get('/listados/rentabilidad')->status);
        $this->assertSame(403, $this->get('/listados/costos')->status);
    }
}
