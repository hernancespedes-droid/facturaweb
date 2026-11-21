<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Csrf;
use App\Core\Db;
use App\Http\Request;
use App\Modules\Articulos\Precios;

/** Precios con margen sobre el costo (D-130…D-133): herencia de márgenes, IVA, redondeo, ficha, actualización masiva, ingreso de stock con costo y permisos. */
final class PreciosTest extends DbTestCase
{
    private array $param;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = Db::pdo();
        $this->param = $pdo->query('SELECT iva, margen_l1, margen_l2, margen_l3, margen_oferta, precio_redondeo, precios_con_iva FROM parametros WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
        $this->limpiar();
        $pdo->exec("UPDATE parametros SET iva = 1, margen_l1 = 50, margen_l2 = 40, margen_l3 = NULL, margen_oferta = NULL, precio_redondeo = 0, precios_con_iva = 1 WHERE id = 1");
        $pdo->exec("INSERT INTO rubros (codigo, descripcion, margen_l2) VALUES ('TP', 'Rubro precios', 30)");
        $pdo->exec("INSERT INTO articulos (codigo, descripcion, rubro, alicuota, grabado, costo, precio_de_venta_1, precio_de_venta_2, margen_l1)
            VALUES ('T-P1', 'Con margen propio', 'TP', '21', 1, 100, 1, 2, 20)");
        $pdo->exec("INSERT INTO articulos (codigo, descripcion, alicuota, grabado, costo, precio_de_venta_1) VALUES ('T-P2', 'Sin rubro', '10', 1, 200, 5)");
        \App\Core\Validador::olvidar();
    }

    protected function tearDown(): void
    {
        $this->limpiar();
        $p = $this->param;
        Db::pdo()->prepare('UPDATE parametros SET iva = ?, margen_l1 = ?, margen_l2 = ?, margen_l3 = ?, margen_oferta = ?, precio_redondeo = ?, precios_con_iva = ? WHERE id = 1')
            ->execute(array_values($p));
        parent::tearDown();
    }

    private function limpiar(): void
    {
        $pdo = Db::pdo();
        $pdo->exec("DELETE FROM articulos_precios_historial WHERE articulo LIKE 'T-P%'");
        $pdo->exec("DELETE FROM stock_movimientos WHERE articulo LIKE 'T-P%'");
        $pdo->exec("DELETE FROM stock_saldos WHERE articulo LIKE 'T-P%'");
        $pdo->exec("DELETE FROM articulos WHERE codigo LIKE 'T-P%'");
        $pdo->exec("DELETE FROM rubros WHERE codigo = 'TP'");
    }

    private function art(string $codigo): array
    {
        return Db::pdo()->query("SELECT * FROM articulos WHERE codigo = '{$codigo}'")->fetch(\PDO::FETCH_ASSOC);
    }

    public function testMargenDelArticuloDelRubroODelGeneralConIvaYRedondeo(): void
    {
        $c = (new Precios())->calcular($this->art('T-P1'));
        $this->assertSame([20.0, 'artículo', 145.2], [$c['listas']['l1']['margen'], $c['listas']['l1']['origen'], $c['listas']['l1']['nuevo']], '100 × 1,20 × 1,21');
        $this->assertSame([30.0, 'rubro', 157.3], [$c['listas']['l2']['margen'], $c['listas']['l2']['origen'], $c['listas']['l2']['nuevo']]);
        $this->assertSame([null, null], [$c['listas']['l3']['margen'], $c['listas']['l3']['nuevo']], 'sin margen en ningún nivel no se calcula');
        $this->assertTrue($c['cambia']);
        $c2 = (new Precios())->calcular($this->art('T-P2'));
        $this->assertSame([50.0, 'general', 331.5], [$c2['listas']['l1']['margen'], $c2['listas']['l1']['origen'], $c2['listas']['l1']['nuevo']], '200 × 1,5 × 1,105');
        $this->assertSame(50.0, $c2['listas']['l1']['real'], 'margen real sin IVA');

        Db::pdo()->exec('UPDATE parametros SET precio_redondeo = 10 WHERE id = 1');
        $this->assertSame(340.0, (new Precios())->calcular($this->art('T-P2'))['listas']['l1']['nuevo'], 'redondeo hacia arriba');
        $this->assertSame(150.0, Precios::precio(100, 50, 0, 10), 'un precio redondo no sube');
        Db::pdo()->exec('UPDATE parametros SET precio_redondeo = 0, precios_con_iva = 0 WHERE id = 1');
        $this->assertSame(300.0, (new Precios())->calcular($this->art('T-P2'))['listas']['l1']['nuevo'], 'precios netos');
        Db::pdo()->exec('UPDATE parametros SET iva = 7, precios_con_iva = 1 WHERE id = 1');
        $this->assertSame(300.0, (new Precios())->calcular($this->art('T-P2'))['listas']['l1']['nuevo'], 'monotributo: el costo ya es final, no se suma IVA');
    }

    public function testFichaConPrecioAutomaticoRecalculaYElOperadorNoTocaCostoNiMargenes(): void
    {
        $this->loginComo($this->crearUsuario('t_adm', 'admin'));
        $datos = ['descripcion' => 'Con margen propio', 'estado' => 'AC', 'moneda' => '$', 'alicuota' => '21', 'grabado' => '1', 'rubro' => 'TP',
            'costo' => '1.000', 'margen_l1' => '20', 'precio_automatico' => '1', 'precio_de_venta_1' => '1', 'precio_de_venta_3' => '77', Csrf::CAMPO => Csrf::token()];
        $res = $this->app->handle(Request::make('POST', '/articulos/ficha', $datos, ['codigo' => 'T-P1']));
        $this->assertSame(302, $res->status);
        $a = $this->art('T-P1');
        $this->assertSame(['1000.0000', '1452.0000', '1573.0000', '77.0000', 1], [$a['costo'], $a['precio_de_venta_1'], $a['precio_de_venta_2'], $a['precio_de_venta_3'], (int) $a['precio_automatico']],
            'las listas con margen se pisan; la que no tiene queda como la escribió');
        $h = Db::pdo()->query("SELECT origen, costo_anterior, costo, l1 FROM articulos_precios_historial WHERE articulo = 'T-P1'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['FICHA', '100.0000', '1000.0000', '1452.0000'], array_values($h));
        $this->assertStringContainsString('Cambios de costo y precios', $this->get('/articulos/ficha', ['codigo' => 'T-P1'])->body);

        $this->loginComo($this->crearUsuario('t_op', 'operador'));
        $datos = ['costo' => '1', 'margen_l1' => '900', 'precio_automatico' => '0', 'descripcion' => 'Cambiada por el operador'] + $datos;
        $datos[Csrf::CAMPO] = Csrf::token();
        $this->app->handle(Request::make('POST', '/articulos/ficha', $datos, ['codigo' => 'T-P1']));
        $a = $this->art('T-P1');
        $this->assertSame(['Cambiada por el operador', '1000.0000', '20.00', 1, '1452.0000'], [$a['descripcion'], $a['costo'], $a['margen_l1'], (int) $a['precio_automatico'], $a['precio_de_venta_1']],
            'sin articulos.precios no cambia costo, márgenes ni el automático, y el precio sigue calculado');
        $this->assertSame(403, $this->get('/articulos/precios')->status);
        $this->assertSame(403, $this->post('/articulos/precios', ['codigos' => ['T-P1']])->status);
    }

    public function testActualizarPreciosMuestraLoQueCambiaYGrabaSoloLoTildado(): void
    {
        $this->loginComo($this->crearUsuario('t_adm', 'admin'));
        $html = $this->get('/articulos/precios', ['ver' => 'cambian', 'q' => 'T-P'])->body;
        $this->assertStringContainsString('T-P1', $html);
        $this->assertStringContainsString('145,20', $html);
        $this->assertStringContainsString('331,50', $html);
        $this->assertStringNotContainsString('T-P1', $this->get('/articulos/precios', ['q' => 'T-P'])->body, 'por omisión solo los automáticos');
        $res = $this->post('/articulos/precios', ['codigos' => ['T-P2'], 'automatico' => '1', 'accion' => 'si']);
        $this->assertSame(302, $res->status);
        $this->assertSame(['331.5000', 1], [$this->art('T-P2')['precio_de_venta_1'], (int) $this->art('T-P2')['precio_automatico']]);
        $this->assertSame('1.0000', $this->art('T-P1')['precio_de_venta_1'], 'lo no tildado queda');
        $this->assertSame('MASIVO', Db::pdo()->query("SELECT origen FROM articulos_precios_historial WHERE articulo = 'T-P2'")->fetchColumn());
    }

    public function testIngresoDeStockConCostoPreguntaSiActualizaLosPrecios(): void
    {
        $this->loginComo($this->crearUsuario('t_adm', 'admin'));
        $dep = (new \App\Modules\Stock\StockService())->depositoPrincipal();
        $base = ['articulo' => 'T-P2', 'modo' => 'INGRESO', 'deposito' => (string) $dep, 'cantidad' => '5', 'unidad' => 'unidades', 'motivo' => 'compra', 'costo' => '400'];
        $res = $this->post('/stock/ajuste', $base);
        $this->assertSame(200, $res->status, 'primero pregunta');
        $this->assertStringContainsString('¿Actualizo también los precios', $res->body);
        $this->assertStringContainsString('663,00', $res->body, '400 × 1,5 × 1,105');
        $this->assertSame(0, (int) Db::pdo()->query("SELECT COUNT(*) FROM stock_movimientos WHERE articulo = 'T-P2'")->fetchColumn(), 'todavía no grabó');

        $this->post('/stock/ajuste', $base + ['precios' => 'costo']);
        $a = $this->art('T-P2');
        $this->assertSame(['400.0000', '5.0000'], [$a['costo'], $a['precio_de_venta_1']], 'solo el costo');
        $this->post('/stock/ajuste', ['costo' => '500', 'precios' => 'precios'] + $base);
        $a = $this->art('T-P2');
        $this->assertSame(['500.0000', '828.7500'], [$a['costo'], $a['precio_de_venta_1']], 'costo y precios');
        $this->post('/stock/ajuste', ['costo' => '999', 'precios' => 'nada'] + $base);
        $this->assertSame('500.0000', $this->art('T-P2')['costo'], 'no cambiar el costo');
        $this->assertSame(3, (int) Db::pdo()->query("SELECT COUNT(*) FROM stock_movimientos WHERE articulo = 'T-P2'")->fetchColumn(), 'tres ingresos grabados');
        $this->assertSame(['STOCK', 'STOCK'], Db::pdo()->query("SELECT origen FROM articulos_precios_historial WHERE articulo = 'T-P2' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));

        // el operador puede mover stock pero el costo que mande se ignora
        $this->loginComo($this->crearUsuario('t_op', 'operador'));
        Db::pdo()->exec("INSERT IGNORE INTO rol_permisos (rol_id, permiso_id) SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'stock.mover' WHERE r.codigo = 'operador'");
        \App\Modules\Auth\Auth::reset();
        try {
            $this->assertSame(302, $this->post('/stock/ajuste', ['costo' => '1', 'precios' => 'precios'] + $base)->status);
            $this->assertSame('500.0000', $this->art('T-P2')['costo']);
        } finally {
            Db::pdo()->exec("DELETE rp FROM rol_permisos rp JOIN roles r ON r.id = rp.rol_id JOIN permisos p ON p.id = rp.permiso_id WHERE r.codigo = 'operador' AND p.codigo = 'stock.mover'");
        }
    }

    public function testCambiosDeCostoDeUnaImportacionQuedanParaPreguntar(): void
    {
        $this->loginComo($this->crearUsuario('t_adm', 'admin'));
        $url = \App\Modules\Articulos\ArticulosController::preguntarPrecios(['T-P1', 'T-P2'], 'La importación cambió el costo de 2 artículos.', url('/importar'));
        $this->assertSame(url('/articulos/precios') . '?pendientes=1', $url);
        $html = $this->get('/articulos/precios', ['pendientes' => '1'])->body;
        $this->assertStringContainsString('La importación cambió el costo de 2 artículos.', $html);
        $this->assertStringContainsString('T-P1', $html, 'aunque no sea automático');
        $this->assertRedirect('/importar', $this->post('/articulos/precios', ['pendientes' => '1', 'accion' => 'no']));
        $this->assertSame('1.0000', $this->art('T-P1')['precio_de_venta_1']);
        $this->assertRedirect('/articulos/precios', $this->get('/articulos/precios', ['pendientes' => '1']), 'ya no hay pendientes');
        Db::pdo()->exec('UPDATE parametros SET margen_l1 = NULL, margen_l2 = NULL WHERE id = 1');
        $this->assertNull(\App\Modules\Articulos\ArticulosController::preguntarPrecios(['T-P2'], 'x', url('/importar')), 'sin margen no hay nada que preguntar');
    }
}
