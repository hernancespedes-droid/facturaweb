<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\Tablero\Graficos;
use App\Modules\Tablero\Tablero;

/**
 * Tablero de inicio (0.40.0): períodos y comparación (D-91), venta sin doble conteo (presupuesto facturado, NC restando), sucursal,
 * avisos (D-92) y qué ve cada rol (D-90: sin listados.ver no hay importes; sin listados.costos no hay margen).
 */
final class TableroTest extends ComprobantesTestCase
{
    protected function tearDown(): void
    {
        $pdo = Db::pdo();
        $pdo->exec("DELETE FROM stock_saldos WHERE articulo LIKE 'T-%'");
        $pdo->exec("DELETE FROM rol_permisos WHERE rol_id = (SELECT id FROM roles WHERE codigo = 'operador') AND permiso_id IN (SELECT id FROM permisos WHERE codigo = 'listados.ver')");
        $this->limpiarUsuarios();
        parent::tearDown();
    }

    public function testPeriodosYComparacion(): void
    {
        $p = Tablero::periodo('mes', '2026-03-31');
        $this->assertSame(['2026-03-01', '2026-03-31', '2026-02-01', '2026-02-28'], [$p['desde'], $p['hasta'], $p['cmp_desde'], $p['cmp_hasta']], 'el 31 de marzo se compara con febrero entero');
        $p = Tablero::periodo('mes', '2026-11-21');
        $this->assertSame(['2026-11-01', '2026-11-21', '2026-10-01', '2026-10-21'], [$p['desde'], $p['hasta'], $p['cmp_desde'], $p['cmp_hasta']]);
        $this->assertStringContainsString('al día 21', $p['titulo']);
        $p = Tablero::periodo('hoy', '2026-11-21');
        $this->assertSame(['2026-11-21', '2026-11-14'], [$p['desde'], $p['cmp_desde']]);
        $this->assertSame('vs. el sábado pasado', $p['vs']);
        $p = Tablero::periodo('anterior', '2026-01-15');
        $this->assertSame(['2025-12-01', '2025-12-31', '2025-11-01', '2025-11-30'], [$p['desde'], $p['hasta'], $p['cmp_desde'], $p['cmp_hasta']]);
        $p = Tablero::periodo('12m', '2026-11-21');
        $this->assertSame(['2025-12-01', '2026-11-21', '2024-12-01', '2025-11-21'], [$p['desde'], $p['hasta'], $p['cmp_desde'], $p['cmp_hasta']]);
        $this->assertSame('mes', Tablero::periodo('cualquiera', '2026-11-21')['clave'], 'un período desconocido es el mes');
    }

    public function testVentaSinDobleConteoYConSucursal(): void
    {
        $fc = $this->emitir($this->pedido()); // total 242
        $this->emitir($this->pedido(['tipo' => 'NC', 'asociado' => (string) $fc['id'], 'items' => [['articulo' => 'T-ART', 'descripcion' => '', 'cantidad' => '1', 'precio' => '100', 'alicuota' => '21']]]));
        $pr = $this->emitir($this->pedido(['tipo' => 'PR', 'items' => [['articulo' => 'T-ART', 'descripcion' => '', 'cantidad' => '2', 'precio' => '121', 'alicuota' => '21']]]));
        $r = $this->svc->armar(['tipo' => 'FC', 'presupuesto' => (string) $pr['id']]);
        $this->assertSame([], $r['errores']);
        $this->svc->emitir($r['cbte'], bin2hex(random_bytes(16)), 0);

        $hoy = date('Y-m-d');
        $v = Tablero::ventas($hoy, $hoy);
        $this->assertSame(363.0, $v['neto'], 'total con IVA como la caja diaria: FC 242 - NC 121 + PR 242; la factura del presupuesto no suma');
        $this->assertSame(2, $v['ventas'], 'ventas: la FC y el PR (la NC no es una venta)');
        $this->assertSame(0.0, Tablero::ventas($hoy, $hoy, 7)['neto'], 'otra sucursal: nada');
        $this->assertSame(363.0, Tablero::ventas($hoy, $hoy, 99)['neto']);
        $this->assertSame([$hoy => 363.0], Tablero::porDia($hoy, $hoy));

        $meses = Tablero::porMes($hoy, 0, $hoy);
        $this->assertCount(12, $meses);
        $this->assertSame(363.0, $meses[11]['neto']);
        $this->assertTrue($meses[11]['parcial'], 'el mes en curso está incompleto');

        $ac = Tablero::acumulado($hoy, $hoy);
        $this->assertCount((int) date('j'), $ac['actual'], 'un valor por día transcurrido');
        $this->assertSame(363.0, end($ac['actual']));
        $this->assertCount((int) date('t', strtotime('first day of last month')), $ac['anterior'], 'el mes anterior, completo');
    }

    public function testAdministradorVeImportesGraficosYMargen(): void
    {
        $this->emitir($this->pedido());
        $this->loginComo($this->crearUsuario('t_tab_admin', 'admin'));
        $html = $this->get('/', ['periodo' => 'hoy'])->body;
        $this->assertStringContainsString('Cómo viene el negocio', $html);
        $this->assertStringContainsString('Ventas de hoy', $html);
        $this->assertStringContainsString('$ 242', $html);
        $this->assertStringContainsString('Margen bruto', $html);
        $this->assertStringContainsString('Venta acumulada del mes', $html);
        $this->assertStringContainsString('<svg class="g-svg"', $html);
        $this->assertStringNotContainsString('NaN', $html);
        $this->assertStringContainsString('Lo más vendido', $html);
        $this->assertStringContainsString('ARTICULO DE PRUEBA', $html);
        $this->assertStringContainsString('assets/tablero.js', $html);
    }

    public function testOperadorSinListadosNoVeImportes(): void
    {
        $this->emitir($this->pedido());
        $this->loginComo($this->crearUsuario('t_tab_oper', 'operador'));
        $res = $this->get('/');
        $this->assertSame(200, $res->status);
        $this->assertStringNotContainsString('Cómo viene el negocio', $res->body);
        $this->assertStringNotContainsString('Ticket promedio', $res->body);
        $this->assertStringNotContainsString('Margen bruto', $res->body);
        $this->assertStringNotContainsString('Lo más vendido', $res->body);
        $this->assertStringNotContainsString('Ventas de', $res->body, 'ningún importe de ventas');
        $this->assertStringContainsString('Te deben los clientes', $res->body, 'la deuda sí: tiene ctacte.ver');
        $this->assertStringContainsString('Comprobantes', $res->body, 've cuántos comprobantes hay, sin importes');
        $this->assertStringContainsString('Todo el menú', $res->body);

        // con listados.ver ve las ventas, pero sin listados.costos no ve el margen
        Db::pdo()->exec("INSERT IGNORE INTO rol_permisos (rol_id, permiso_id) SELECT r.id, p.id FROM roles r, permisos p WHERE r.codigo = 'operador' AND p.codigo = 'listados.ver'");
        \App\Modules\Auth\Auth::reset();
        $html = $this->get('/')->body;
        $this->assertStringContainsString('Ticket promedio', $html);
        $this->assertStringNotContainsString('Margen bruto', $html);
    }

    public function testAvisosDeStockBajoYChequesPorVencer(): void
    {
        $pdo = Db::pdo();
        $pdo->exec("UPDATE articulos SET controla_stock = 1, stock_minimo_unidades = 5 WHERE codigo = 'T-ART'");
        $pdo->exec("INSERT INTO caja (fecha, tipo, letra, sucursal, prefijo, comprobante, cliente, p_fpago, p_nro, p_fecvto, p_importe, cheque_estado)
            VALUES (CURDATE(), 'RC', 'X', 99, 9, 1, 990001, 'CH', '123', CURDATE() + INTERVAL 3 DAY, 5000, '')");
        $this->loginComo($this->crearUsuario('t_tab_admin2', 'admin'));
        $tb = Tablero::datos([]);
        $titulos = array_column($tb['alertas'], 'titulo');
        $this->assertContains('1 artículo bajo el stock mínimo', $titulos);
        $this->assertContains('1 cheque vence en los próximos 7 días', $titulos);
        $pdo->exec("UPDATE articulos SET stock_minimo_unidades = 0 WHERE codigo = 'T-ART'");
        $this->assertNotContains('1 artículo bajo el stock mínimo', array_column(Tablero::datos([])['alertas'], 'titulo'), 'sin mínimo cargado no avisa');
    }

    public function testEscalaYFormatos(): void
    {
        $this->assertSame([15000000.0, 5000000.0], Graficos::escala(12400000));
        $this->assertSame([1000.0, 500.0], Graficos::escala(0));
        $this->assertSame('$ 18,7 M', Tablero::corto(18720000));
        $this->assertSame('$ 845 mil', Tablero::corto(845000));
        $this->assertSame('$ 1.234', Tablero::pesos(1234.4));
        $this->assertStringContainsString('>7,5 mil<', Graficos::columnas([['mes' => '2026-11', 'neto' => 6356.0, 'parcial' => true]]), 'el eje no redondea 2,5 mil a 3 mil');
        $this->assertSame('+12,9 %', Graficos::signo(12.94));
        $this->assertSame('−3,0 %', Graficos::signo(-3));
        $svg = Graficos::columnas([['mes' => '2026-10', 'neto' => 0.0, 'parcial' => false], ['mes' => '2026-11', 'neto' => 0.0, 'parcial' => true]]);
        $this->assertStringNotContainsString('NaN', $svg, 'sin ventas no se rompe');
        $this->assertStringNotContainsString('INF', Graficos::lineas([], [], 30, 'noviembre', 'octubre'));
    }
}
