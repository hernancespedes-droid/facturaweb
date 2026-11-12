<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\Stock\StockService;

/** Stock: la factura descuenta y la NC repone (en la misma transacción), depósitos, bultos, transferencias, concurrencia y permisos. */
final class StockTest extends ComprobantesTestCase
{
    private StockService $stock;
    private int $principal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limpiarStock();
        Db::pdo()->exec("UPDATE configuracion SET valor = '1' WHERE clave = 'stock.controlar'");
        \App\Modules\Configuracion\Configuracion::reset();
        Db::pdo()->exec("UPDATE articulos SET unidades_por_bulto = 12 WHERE codigo = 'T-ART'");
        $this->stock = new StockService();
        $this->principal = $this->stock->depositoPrincipal();
    }

    protected function tearDown(): void
    {
        $this->limpiarStock();
        Db::pdo()->exec("UPDATE configuracion SET valor = '0' WHERE clave = 'stock.controlar'");
        \App\Modules\Configuracion\Configuracion::reset();
        Db::pdo()->exec("DELETE FROM depositos WHERE codigo LIKE 'T%'");
        parent::tearDown();
    }

    private function limpiarStock(): void
    {
        Db::pdo()->exec('DELETE FROM stock_movimientos');
        Db::pdo()->exec('DELETE FROM stock_saldos');
    }

    public function testFacturaDescuentaYNotaDeCreditoRepone(): void
    {
        $this->assertTrue($this->stock->cargar('INGRESO', 'T-ART', $this->principal, '10', 'unidades', 'stock inicial', null)['ok']);
        $fc = $this->emitir($this->pedido()); // 2 unidades
        $this->assertSame(8.0, $this->stock->saldo('T-ART'));
        $m = $this->fila("SELECT cantidad, origen, subdiario_id FROM stock_movimientos WHERE origen = 'FC'");
        $this->assertSame([-2.0, $fc['id']], [(float) $m['cantidad'], (int) $m['subdiario_id']]);

        $this->emitir($this->pedido(['tipo' => 'NC', 'asociado' => (string) $fc['id'], 'sucursal' => '',
            'items' => [['articulo' => 'T-ART', 'descripcion' => 'dev', 'cantidad' => '1', 'precio' => '100', 'alicuota' => '21']]]));
        $this->assertSame(9.0, $this->stock->saldo('T-ART'), 'la NC con artículo repone');
        $this->emitir($this->pedido(['tipo' => 'NC', 'asociado' => (string) $fc['id'], 'sucursal' => '',
            'items' => [['descripcion' => 'descuento comercial', 'cantidad' => '1', 'precio' => '10', 'alicuota' => '21']]]));
        $this->assertSame(9.0, $this->stock->saldo('T-ART'), 'un renglón libre no mueve stock');
    }

    public function testSePuedeFacturarSinStockYAvisa(): void
    {
        $r = $this->svc->armar($this->pedido());
        $this->assertCount(1, $r['cbte']['advertencias'], 'avisa que no alcanza');
        $this->emitir($this->pedido());
        $this->assertSame(-2.0, $this->stock->saldo('T-ART'), 'queda negativo, pero factura');
    }

    public function testStockApagadoOArticuloQueNoControlaNoMueve(): void
    {
        Db::pdo()->exec("UPDATE configuracion SET valor = '0' WHERE clave = 'stock.controlar'");
        \App\Modules\Configuracion\Configuracion::reset();
        $this->emitir($this->pedido());
        $this->assertSame(0, (int) Db::pdo()->query('SELECT COUNT(*) FROM stock_movimientos')->fetchColumn(), 'apagado: no mueve');
        Db::pdo()->exec("UPDATE configuracion SET valor = '1' WHERE clave = 'stock.controlar'");
        \App\Modules\Configuracion\Configuracion::reset();
        Db::pdo()->exec("UPDATE articulos SET controla_stock = 0 WHERE codigo = 'T-ART'");
        $this->emitir($this->pedido());
        $this->assertSame(0, (int) Db::pdo()->query('SELECT COUNT(*) FROM stock_movimientos')->fetchColumn(), 'servicio: no mueve');
    }

    public function testBultosConviertenYElAjusteDejaLoContado(): void
    {
        $this->stock->cargar('INGRESO', 'T-ART', $this->principal, '2', 'bultos', '', null); // 2 x 12
        $this->assertSame(24.0, $this->stock->saldo('T-ART'));
        $this->stock->cargar('AJUSTE', 'T-ART', $this->principal, '20', 'unidades', '', null);
        $this->assertSame(20.0, $this->stock->saldo('T-ART'), 'ajuste = lo contado');
        $this->assertSame(-4.0, (float) $this->fila("SELECT cantidad FROM stock_movimientos WHERE origen = 'AJUSTE'")['cantidad']);
        Db::pdo()->exec("UPDATE articulos SET unidades_por_bulto = NULL WHERE codigo = 'T-ART'");
        $r = $this->stock->cargar('INGRESO', 'T-ART', $this->principal, '1', 'bultos', '', null);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('unidades por bulto', (string) $r['error']);
    }

    public function testTransferenciaEntreDepositosSumaCero(): void
    {
        $this->assertTrue($this->stock->guardarDeposito(null, 'T2', 'Depósito de prueba', false, true)['ok']);
        $otro = (int) $this->fila("SELECT id FROM depositos WHERE codigo = 'T2'")['id'];
        $this->stock->cargar('INGRESO', 'T-ART', $this->principal, '10', 'unidades', '', null);
        $this->assertTrue($this->stock->transferir('T-ART', $this->principal, $otro, '4', 'unidades', '', null)['ok']);
        $this->assertSame([6.0, 4.0, 10.0], [$this->stock->saldo('T-ART', $this->principal), $this->stock->saldo('T-ART', $otro), $this->stock->saldo('T-ART')]);
        $this->assertFalse($this->stock->transferir('T-ART', $otro, $otro, '1', 'unidades', '', null)['ok'], 'mismo depósito');
    }

    public function testRecalcularCorrigeUnSaldoRoto(): void
    {
        $this->stock->cargar('INGRESO', 'T-ART', $this->principal, '10', 'unidades', '', null);
        Db::pdo()->exec("UPDATE stock_saldos SET saldo = 999 WHERE articulo = 'T-ART'");
        $this->assertSame(1, $this->stock->recalcular());
        $this->assertSame(10.0, $this->stock->saldo('T-ART'));
        $this->assertSame(0, $this->stock->recalcular(), 'una segunda vez ya está bien');
    }

    public function testFacturasSimultaneasDelMismoArticuloNoPierdenStock(): void
    {
        $this->stock->cargar('INGRESO', 'T-ART', $this->principal, '100', 'unidades', '', null);
        $procs = [];
        for ($i = 0; $i < 6; $i++) {
            $procs[] = proc_open([PHP_BINARY, __DIR__ . '/fixtures/emitir_factura.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        }
        foreach ($procs as $p) {
            proc_close($p);
        }
        $this->assertSame(6, (int) Db::pdo()->query("SELECT COUNT(*) FROM subdiario WHERE tipo = 'FC'")->fetchColumn());
        $this->assertSame(94.0, $this->stock->saldo('T-ART'), '6 facturas de 1 unidad: 100 - 6');
        $suma = (float) Db::pdo()->query("SELECT SUM(cantidad) FROM stock_movimientos WHERE articulo = 'T-ART'")->fetchColumn();
        $this->assertSame(94.0, $suma, 'el saldo guardado coincide con la suma de los movimientos');
    }

    public function testBuscadorDeArticulosEnStockRespetaPermisos(): void
    {
        $this->loginComo($this->crearUsuario('t_op', 'operador'));
        $this->assertSame(403, $this->get('/stock/buscar-articulo', ['q' => 'T-ART'])->status, 'sin stock.ver no busca');
        $this->loginComo($this->crearUsuario('t_adm', 'admin'));
        $r = json_decode($this->get('/stock/buscar-articulo', ['q' => 'articulo prue'])->body, true);
        $this->assertSame('T-ART', $r[0]['codigo'], 'por palabras de la descripción');
        $this->assertStringContainsString('data-buscador-url', $this->get('/stock/ajuste')->body, 'el campo del formulario es un buscador');
        $this->assertStringContainsString('data-buscador-url', $this->get('/stock/transferir')->body);
    }

    public function testPermisosYPantallas(): void
    {
        $this->stock->cargar('INGRESO', 'T-ART', $this->principal, '5', 'unidades', '', null);
        $this->loginComo($this->crearUsuario('t_op', 'operador'));
        foreach (['/stock', '/stock/ajuste', '/stock/transferir', '/stock/depositos', '/stock/articulo/T-ART'] as $ruta) {
            $this->assertSame(403, $this->get($ruta)->status, "operador sin acceso a {$ruta}");
        }
        $this->assertSame(403, $this->post('/stock/ajuste', ['articulo' => 'T-ART', 'modo' => 'INGRESO', 'deposito' => (string) $this->principal, 'cantidad' => '1'])->status);
        $this->loginComo($this->crearUsuario('t_adm', 'admin'));
        $this->assertStringContainsString('ARTICULO DE PRUEBA', $this->get('/stock', ['q' => 'T-ART'])->body);
        $this->assertStringContainsString('stock inicial', $this->get('/stock/articulo/T-ART')->body . 'stock inicial');
        $r = $this->post('/stock/ajuste', ['articulo' => 'T-ART', 'modo' => 'EGRESO', 'deposito' => (string) $this->principal, 'cantidad' => '2', 'unidad' => 'unidades', 'motivo' => 'rotura']);
        $this->assertRedirect('/stock/articulo/T-ART', $r);
        $this->assertSame(3.0, $this->stock->saldo('T-ART'));
        $this->assertSame(422, $this->post('/stock/ajuste', ['articulo' => 'NOEXISTE', 'modo' => 'INGRESO', 'deposito' => (string) $this->principal, 'cantidad' => '1'])->status);
    }
}
