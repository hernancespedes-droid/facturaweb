<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\Articulos\BuscadorArticulos;
use App\Modules\Stock\StockService;

/** El buscador de artículos informa el stock (por depósito) para ver dónde hay. */
final class BuscadorStockTest extends ComprobantesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Db::pdo()->exec('DELETE FROM stock_movimientos');
        Db::pdo()->exec('DELETE FROM stock_saldos');
        Db::pdo()->exec("DELETE FROM depositos WHERE codigo LIKE 'T%'");
        $this->activar(true);
    }

    protected function tearDown(): void
    {
        Db::pdo()->exec('DELETE FROM stock_movimientos');
        Db::pdo()->exec('DELETE FROM stock_saldos');
        Db::pdo()->exec("DELETE FROM depositos WHERE codigo LIKE 'T%'");
        $this->activar(false);
        parent::tearDown();
    }

    private function activar(bool $si): void
    {
        Db::pdo()->exec("UPDATE configuracion SET valor = '" . ($si ? '1' : '0') . "' WHERE clave = 'stock.controlar'");
        \App\Modules\Configuracion\Configuracion::reset();
    }

    public function testMuestraElStockPorDeposito(): void
    {
        $s = new StockService();
        $this->assertTrue($s->guardarDeposito(null, 'T2', 'Local Centro', false, true)['ok']);
        $dep2 = (int) $this->fila("SELECT id FROM depositos WHERE codigo = 'T2'")['id'];
        $s->cargar('INGRESO', 'T-ART', $s->depositoPrincipal(), '10', 'unidades', 'inicial', null);
        $s->cargar('INGRESO', 'T-ART', $dep2, '4', 'unidades', 'inicial', null);

        $a = BuscadorArticulos::buscar('T-ART', true)[0];
        $this->assertTrue($a['stock']['controla']);
        $this->assertSame(14.0, $a['stock']['total']);
        $this->assertSame([10.0, 4.0], array_map(static fn (array $d) => $d['s'], array_values(array_filter($a['stock']['depositos'], static fn (array $d) => in_array($d['s'], [10.0, 4.0], true)))));
        $this->assertTrue(in_array('Local Centro', array_column($a['stock']['depositos'], 'n'), true), 'trae el nombre de cada depósito');
        // la ventana «ver lista» trae los nombres de los depósitos para armar las columnas
        $l = BuscadorArticulos::listar('T-ART', 1);
        $this->assertTrue(in_array('Local Centro', $l['depositos'], true));
        $this->assertSame(14.0, $l['items'][0]['stock']['total']);
        // por el buscador de la pantalla (con permiso)
        $this->loginComo($this->crearUsuario('t_op_st', 'operador'));
        $r = $this->get('/comprobantes/buscar-articulo', ['q' => 'T-ART']);
        $this->assertSame(200, $r->status);
        $j = json_decode($r->body, true);
        $this->assertSame(14.0, (float) $j[0]['stock']['total']);
    }

    public function testSinControlDeStockOArticuloQueNoControla(): void
    {
        Db::pdo()->exec("UPDATE articulos SET controla_stock = 0 WHERE codigo = 'T-ART'");
        $a = BuscadorArticulos::buscar('T-ART', true)[0];
        $this->assertSame(false, $a['stock']['controla'], 'se marca que no controla stock');
        $this->activar(false);
        $b = BuscadorArticulos::buscar('T-ART', true)[0];
        $this->assertNull($b['stock'], 'con el stock apagado no se muestra nada');
        $this->assertNull(BuscadorArticulos::listar('T-ART', 1)['depositos']);
    }
}
