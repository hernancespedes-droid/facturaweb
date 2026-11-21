<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\Articulos\ActualizacionMasiva as AM;

/** Actualización de precios por filtro (D-144…D-146): fórmulas, filtros, convivencia con «Precio automático», vista previa = lo que se graba, deshacer y permisos. */
final class ActualizacionPreciosTest extends DbTestCase
{
    private array $param;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = Db::pdo();
        $this->param = $pdo->query('SELECT iva, margen_l1, margen_l2, margen_l3, margen_oferta, precio_redondeo, precios_con_iva FROM parametros WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
        $this->limpiar();
        $pdo->exec("UPDATE parametros SET iva = 1, margen_l1 = NULL, margen_l2 = NULL, margen_l3 = NULL, margen_oferta = NULL, precio_redondeo = 0, precios_con_iva = 1 WHERE id = 1");
        $pdo->exec("INSERT INTO rubros (codigo, descripcion) VALUES ('TA', 'Rubro actualización'), ('TB', 'Otro rubro')");
        $ins = $pdo->prepare("INSERT INTO articulos (codigo, descripcion, rubro, proveedor, estado, moneda, alicuota, grabado, costo, precio_de_venta_1, precio_de_venta_2,
            precio_de_venta_3, precio_de_oferta, margen_l1, precio_automatico) VALUES (?, ?, ?, ?, ?, ?, '21', 1, ?, ?, ?, 0, 0, ?, ?)");
        $ins->execute(['T-A1', 'Manual en pesos', 'TA', 10, 'AC', '$', 100, 1000, 2000, null, 0]);
        $ins->execute(['T-A2', 'Automático', 'TA', 20, 'AC', '$', 100, 145.2, 500, 20, 1]);
        $ins->execute(['T-A3', 'Manual en dólares', 'TB', 10, 'AC', 'u$s', 10, 50, 0, null, 0]);
        $ins->execute(['T-A4', 'Dado de baja', 'TA', 10, 'BA', '$', 100, 100, 0, null, 0]);
    }

    protected function tearDown(): void
    {
        $this->limpiar();
        Db::pdo()->prepare('UPDATE parametros SET iva = ?, margen_l1 = ?, margen_l2 = ?, margen_l3 = ?, margen_oferta = ?, precio_redondeo = ?, precios_con_iva = ? WHERE id = 1')
            ->execute(array_values($this->param));
        parent::tearDown();
    }

    private function limpiar(): void
    {
        $pdo = Db::pdo();
        $ids = $pdo->query("SELECT DISTINCT actualizacion_id FROM articulos_precios_historial WHERE articulo LIKE 'T-A%' AND actualizacion_id IS NOT NULL")->fetchAll(\PDO::FETCH_COLUMN);
        $pdo->exec("DELETE FROM articulos_precios_historial WHERE articulo LIKE 'T-A%'");
        if ($ids !== []) {
            $pdo->exec('DELETE FROM precios_actualizaciones WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
        }
        $pdo->exec("DELETE FROM articulos WHERE codigo LIKE 'T-A%'");
        $pdo->exec("DELETE FROM rubros WHERE codigo IN ('TA', 'TB')");
    }

    /** @return array<string,mixed> */
    private function datos(array $x): array
    {
        $l = AM::leer($x + ['codigo_desde' => 'T-A1', 'codigo_hasta' => 'T-A9', 'enviado' => '1', 'redondear' => '1']);
        $this->assertSame([], $l['errores']);
        return $l['d'];
    }

    private function art(string $c): array
    {
        return Db::pdo()->query("SELECT * FROM articulos WHERE codigo = '{$c}'")->fetch(\PDO::FETCH_ASSOC);
    }

    private function confirmar(array $d): array
    {
        $svc = new AM();
        return $svc->confirmar($d, $svc->simular($d)['firma'], null);
    }

    public function testFormulasValidacionYDescripcion(): void
    {
        $this->assertSame(110.0, round(AM::formula(100, 'coeficiente', 1.1), 4));
        $this->assertSame(108.0, round(AM::formula(100, 'porcentaje', 8), 4));
        $this->assertSame(95.0, round(AM::formula(100, 'porcentaje', -5), 4));
        $this->assertSame(150.0, round(AM::formula(100, 'monto', 50), 4));
        $this->assertTrue(isset(AM::leer(['forma' => 'coeficiente', 'valor' => '1'])['errores']['valor']), 'coeficiente 1 no cambia nada');
        $this->assertTrue(isset(AM::leer(['forma' => 'porcentaje', 'valor' => 'abc'])['errores']['valor']));
        $this->assertTrue(isset(AM::leer(['forma' => 'porcentaje', 'valor' => '-100'])['errores']['valor']));
        $this->assertTrue(isset(AM::leer(['valor' => '5', 'codigo_desde' => 'B', 'codigo_hasta' => 'A'])['errores']['codigo_hasta']));
        $this->assertTrue(isset(AM::leer(['valor' => '5', 'proveedor_desde' => 'x'])['errores']['proveedor_desde']));
        $d = AM::leer(['forma' => 'coeficiente', 'valor' => '1,25'])['d'];
        $this->assertSame(1.25, $d['valor'], 'coma decimal');
        $this->assertSame('Lista 1 +8 % · Código T-A1 a T-A9, Rubro TA', AM::descripcion($this->datos(['valor' => '8', 'rubro_desde' => 'TA', 'rubro_hasta' => 'TA'])));
    }

    public function testUnaListaConPorcentajeFiltrosYRedondeo(): void
    {
        $d = $this->datos(['que' => 'lista', 'lista' => 'l1', 'forma' => 'porcentaje', 'valor' => '10']);
        $p = (new AM())->simular($d);
        $this->assertSame(3, $p['cambian'], 'el dado de baja no entra');
        $this->assertSame(1, $p['a_manual'], 'el automático pasa a manual (D-145)');
        $r = $this->confirmar($d);
        $this->assertTrue($r['ok']);
        $this->assertSame(['1100.0000', '2000.0000', '100.0000'], [$this->art('T-A1')['precio_de_venta_1'], $this->art('T-A1')['precio_de_venta_2'], $this->art('T-A1')['costo']]);
        $this->assertSame(['159.7200', 0], [$this->art('T-A2')['precio_de_venta_1'], (int) $this->art('T-A2')['precio_automatico']]);
        $this->assertSame('55.0000', $this->art('T-A3')['precio_de_venta_1'], 'el porcentaje sí va en dólares');
        $this->assertSame('100.0000', $this->art('T-A4')['precio_de_venta_1']);

        // proveedor y rubro desde/hasta, redondeo de Parámetros hacia arriba
        Db::pdo()->exec('UPDATE parametros SET precio_redondeo = 100 WHERE id = 1');
        $d = $this->datos(['que' => 'listas', 'forma' => 'coeficiente', 'valor' => '1,01', 'proveedor_desde' => '10', 'proveedor_hasta' => '10', 'rubro_desde' => 'TA', 'rubro_hasta' => 'TA']);
        $this->assertTrue($this->confirmar($d)['ok']);
        $this->assertSame(['1200.0000', '2100.0000', '0.0000'], [$this->art('T-A1')['precio_de_venta_1'], $this->art('T-A1')['precio_de_venta_2'], $this->art('T-A1')['precio_de_venta_3']],
            '1111 → 1200, 2020 → 2100; el precio en 0 sigue en 0');
        $this->assertSame('55.0000', $this->art('T-A3')['precio_de_venta_1'], 'otro rubro: no se toca');
        $this->assertSame('159.7200', $this->art('T-A2')['precio_de_venta_1'], 'otro proveedor: no se toca');
    }

    public function testAutomaticosSeDejanAfueraOSeRecalculanConElCosto(): void
    {
        $d = $this->datos(['que' => 'listas', 'forma' => 'monto', 'valor' => '100', 'automaticos' => 'excluir']);
        $p = (new AM())->simular($d);
        $this->assertSame(1, $p['cambian']);
        $this->assertSame(['T-A3'], $p['salteados']['En dólares: el monto fijo es en pesos']);
        $this->assertSame(['T-A2'], $p['salteados']['Precio automático (se dejó afuera)']);

        // costo: el manual cambia solo el costo; el automático recalcula las listas con margen y sigue automático
        $this->assertTrue($this->confirmar($this->datos(['que' => 'costo', 'forma' => 'porcentaje', 'valor' => '50']))['ok']);
        $a1 = $this->art('T-A1');
        $this->assertSame(['150.0000', '1000.0000'], [$a1['costo'], $a1['precio_de_venta_1']]);
        $a2 = $this->art('T-A2');
        $this->assertSame(['150.0000', '217.8000', '500.0000', 1], [$a2['costo'], $a2['precio_de_venta_1'], $a2['precio_de_venta_2'], (int) $a2['precio_automatico']],
            '150 × 1,2 × 1,21; la lista 2 sin margen queda');

        // listas y costo: en el manual a todo; en el automático la lista sin margen sigue la fórmula
        $this->assertTrue($this->confirmar($this->datos(['que' => 'todo', 'forma' => 'porcentaje', 'valor' => '10']))['ok']);
        $a1 = $this->art('T-A1');
        $this->assertSame(['165.0000', '1100.0000', '2200.0000'], [$a1['costo'], $a1['precio_de_venta_1'], $a1['precio_de_venta_2']]);
        $a2 = $this->art('T-A2');
        $this->assertSame(['165.0000', '239.5800', '550.0000', 1], [$a2['costo'], $a2['precio_de_venta_1'], $a2['precio_de_venta_2'], (int) $a2['precio_automatico']]);
    }

    public function testConfirmarSoloSiCoincideConLaVistaPreviaYDeshacerLaUltima(): void
    {
        $svc = new AM();
        $d = $this->datos(['que' => 'lista', 'lista' => 'l1', 'forma' => 'porcentaje', 'valor' => '10']);
        $firma = $svc->simular($d)['firma'];
        Db::pdo()->exec("UPDATE articulos SET precio_de_venta_1 = 1001 WHERE codigo = 'T-A1'"); // alguien lo cambió entre la vista previa y el confirmar
        $r = $svc->confirmar($d, $firma, null);
        $this->assertFalse($r['ok']);
        $this->assertSame('1001.0000', $this->art('T-A1')['precio_de_venta_1'], 'no se grabó nada');
        Db::pdo()->exec("UPDATE articulos SET precio_de_venta_1 = 1000 WHERE codigo = 'T-A1'");

        $r1 = $this->confirmar($d);
        $r2 = $this->confirmar($this->datos(['que' => 'lista', 'lista' => 'l2', 'forma' => 'monto', 'valor' => '5']));
        $this->assertTrue($r1['ok'] && $r2['ok']);
        $this->assertSame($r2['id'], $svc->deshacible());
        $this->assertSame(3, (int) Db::pdo()->query('SELECT COUNT(*) FROM articulos_precios_historial WHERE actualizacion_id = ' . (int) $r1['id'])->fetchColumn());
        $this->assertFalse($svc->deshacer((int) $r1['id'], null)['ok'], 'solo la última');

        $this->assertTrue($svc->deshacer((int) $r2['id'], null)['ok']);
        $this->assertSame('2000.0000', $this->art('T-A1')['precio_de_venta_2']);
        $this->assertSame($r1['id'], $svc->deshacible(), 'queda disponible la anterior');

        Db::pdo()->exec("UPDATE articulos SET precio_de_venta_1 = 1234 WHERE codigo = 'T-A3'"); // cambió después: no se pisa
        $u = $svc->deshacer((int) $r1['id'], null);
        $this->assertSame([true, 2, ['T-A3']], [$u['ok'], $u['deshechos'], $u['no_deshechos']]);
        $this->assertSame('1000.0000', $this->art('T-A1')['precio_de_venta_1']);
        $this->assertSame(['145.2000', 1], [$this->art('T-A2')['precio_de_venta_1'], (int) $this->art('T-A2')['precio_automatico']], 'vuelve a automático');
        $this->assertSame('1234.0000', $this->art('T-A3')['precio_de_venta_1']);
        $this->assertTrue($svc->deshacible() !== $r1['id']);
    }

    public function testPantallaYPermisos(): void
    {
        $this->loginComo($this->crearUsuario('t_op', 'operador'));
        $this->assertSame(403, $this->get('/articulos/actualizacion')->status);
        $this->assertSame(403, $this->post('/articulos/actualizacion', ['valor' => '10'])->status);
        $this->assertSame(403, $this->post('/articulos/actualizacion/deshacer', ['id' => '1'])->status);

        $this->loginComo($this->crearUsuario('t_adm', 'admin'));
        $q = ['vista' => '1', 'enviado' => '1', 'codigo_desde' => 'T-A1', 'codigo_hasta' => 'T-A9', 'que' => 'lista', 'lista' => 'l1', 'forma' => 'porcentaje', 'valor' => '10', 'redondear' => '1'];
        $html = $this->get('/articulos/actualizacion', $q)->body;
        $this->assertStringContainsString('1.100,00', $html);
        $this->assertStringContainsString('pasa a manual', $html);
        preg_match('/name="firma" value="([0-9a-f]+)"/', $html, $mm);
        $this->assertTrue(isset($mm[1]));
        unset($q['vista']);
        $res = $this->post('/articulos/actualizacion', $q + ['firma' => $mm[1]]);
        $this->assertSame(302, $res->status);
        $this->assertSame('1100.0000', $this->art('T-A1')['precio_de_venta_1']);
        $id = (new AM())->deshacible();
        $this->assertStringContainsString('Deshacer', $this->get('/articulos/actualizacion')->body);
        $this->assertSame(302, $this->post('/articulos/actualizacion/deshacer', ['id' => (string) $id])->status);
        $this->assertSame('1000.0000', $this->art('T-A1')['precio_de_venta_1']);
        $this->assertSame(['AUMENTO', 'DESHACER'], Db::pdo()->query("SELECT origen FROM articulos_precios_historial WHERE articulo = 'T-A1' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
    }
}
