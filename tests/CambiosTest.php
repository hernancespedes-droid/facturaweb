<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\Cambios\CambioInvalido;
use App\Modules\Cambios\CambiosService;
use App\Modules\Stock\StockService;

/** Ticket de cambio y cambio de mercadería: devuelve stock, lleva otro producto y cobra solo la diferencia. */
final class CambiosTest extends ComprobantesTestCase
{
    private CambiosService $cambios;
    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = Db::pdo();
        $pdo->exec('DELETE FROM stock_movimientos');
        $pdo->exec('DELETE FROM stock_saldos');
        $pdo->exec("UPDATE configuracion SET valor = '1' WHERE clave = 'stock.controlar'");
        \App\Modules\Configuracion\Configuracion::reset();
        $pdo->exec('UPDATE parametros SET ticket_cambio = 1 WHERE id = 1');
        CambiosService::reset();
        $pdo->exec("INSERT INTO articulos (codigo, descripcion, alicuota, precio_unitario) VALUES ('T-ART2', 'OTRO ARTICULO DE PRUEBA', '21', 150)");
        $pdo->exec("INSERT IGNORE INTO forma_de_pagos (codigo, descripcion, tipo) VALUES ('EF', 'EFECTIVO', 1)");
        $pdo->exec("UPDATE forma_de_pagos SET acepta_vuelto = 1 WHERE codigo = 'EF'");
        $this->cambios = new CambiosService();
        $this->stock = new StockService();
        $this->stock->cargar('INGRESO', 'T-ART', $this->stock->depositoPrincipal(), '10', 'unidades', 'inicial', null);
        $this->stock->cargar('INGRESO', 'T-ART2', $this->stock->depositoPrincipal(), '10', 'unidades', 'inicial', null);
    }

    protected function tearDown(): void
    {
        $pdo = Db::pdo();
        $pdo->exec('DELETE FROM stock_movimientos');
        $pdo->exec('DELETE FROM stock_saldos');
        $pdo->exec("UPDATE configuracion SET valor = '0' WHERE clave = 'stock.controlar'");
        $pdo->exec('UPDATE parametros SET ticket_cambio = 0 WHERE id = 1');
        $pdo->exec("DELETE FROM articulos WHERE codigo = 'T-ART2'");
        \App\Modules\Configuracion\Configuracion::reset();
        CambiosService::reset();
        parent::tearDown();
    }

    /** Presupuesto de 2 x T-ART a 100 (con IVA) = 200, a cuenta corriente. @return array{id:int,linea:int} */
    private function presupuesto(): array
    {
        $pr = $this->emitir($this->pedido(['tipo' => 'PR', 'items' => [['articulo' => 'T-ART', 'descripcion' => '', 'cantidad' => '2', 'precio' => '100', 'alicuota' => '21']]]));
        return ['id' => $pr['id'], 'linea' => (int) $this->fila('SELECT fe_clave FROM estadistica WHERE subdiario_id = ?', [$pr['id']])['fe_clave']];
    }

    private function cambio(array $pr, string $devuelve, array $nuevos, array $extra = []): array
    {
        return $extra + ['original' => (string) $pr['id'], 'devolver' => [$pr['linea'] => $devuelve], 'nuevos' => $nuevos];
    }

    public function testBuscaPorElCodigoDelTicketOPorElNumero(): void
    {
        $pr = $this->presupuesto();
        $h = $this->svc->obtener($pr['id']);
        $this->assertSame('PR-X-0009-00000001', CambiosService::codigo($h));
        foreach (['PR-X-0009-00000001', 'TC:PR-X-0009-00000001', '0009-1', '1', 'presupuesto 1', ' pr 0009-00000001 '] as $q) {
            $r = $this->cambios->buscar($q);
            $this->assertSame([$pr['id']], array_map(static fn (array $f) => (int) $f['fe_clave'], $r), "se encuentra con «{$q}»");
        }
        $this->assertSame([], $this->cambios->buscar('FC-A-0009-00000001'), 'no mezcla tipos');
        $this->assertSame([], $this->cambios->buscar('99999'));
        $this->assertSame([], $this->cambios->buscar(''));
        // el mismo número en una factura y un presupuesto: se muestran los dos para que elija el usuario
        $this->emitir($this->pedido());
        $this->assertCount(2, $this->cambios->buscar('1'));
    }

    public function testCambioConDiferenciaCobraSoloLaDiferenciaYMueveElStock(): void
    {
        $pr = $this->presupuesto();                       // 2 x T-ART a 100; stock T-ART 10 → 8
        $this->assertSame(8.0, $this->stock->saldo('T-ART'));
        // devuelve 1 (100) y se lleva 1 de T-ART2 (150): diferencia 50, paga 100 en efectivo → vuelto 50
        $in = $this->cambio($pr, '1', [['articulo' => 'T-ART2', 'cantidad' => '1', 'precio' => '']], ['forma_pago' => '1', 'fpago' => 'EF', 'recibido' => '100']);
        $r = $this->cambios->armar($in);
        $this->assertSame([], $r['errores']);
        $c = $r['cambio'];
        $this->assertSame([100.0, 150.0, 50.0], [$c['devuelto_total'], $c['nuevo_total'], $c['diferencia']]);
        $e = $this->cambios->emitir($c, bin2hex(random_bytes(16)), 0);
        $this->assertSame(50.0, $e['vuelto']);
        $h = $this->svc->obtener($e['id']);
        $this->assertSame(['CM', 'X', 1, '50.00', 0], [$h['tipo'], $h['letra'], (int) $h['comprobante'], $h['neto'], (int) $h['es_prueba']]);
        $this->assertSame($pr['id'], (int) $h['cambio_de']);
        $this->assertSame([-1.0, 1.0], array_map(static fn (array $i) => (float) $i['unidades'], $h['items']), 'lo devuelto va en negativo');
        $this->assertSame(9.0, $this->stock->saldo('T-ART'), 'el producto devuelto vuelve al stock');
        $this->assertSame(9.0, $this->stock->saldo('T-ART2'), 'el que se lleva sale');
        $caja = $this->fila('SELECT p_importe, p_recibido, p_vuelto, p_fpago FROM caja WHERE subdiario_id = ?', [$e['id']]);
        $this->assertSame(['50.00', '100.00', '50.00', 'EF'], [$caja['p_importe'], $caja['p_recibido'], $caja['p_vuelto'], $caja['p_fpago']], 'solo se cobra la diferencia');
        $this->assertSame(0, (int) $this->fila('SELECT COUNT(*) AS n FROM ctact WHERE subdiario_id = ?', [$e['id']])['n']);
        $this->assertSame(1, (int) $this->fila('SELECT ultimo_cambio AS n FROM sucursales WHERE codigo = 99')['n']);
        // el original sabe de su cambio
        $orig = $this->svc->obtener($pr['id']);
        $this->assertSame($e['id'], (int) $orig['cambios'][0]['fe_clave']);
        $this->assertSame($pr['id'], (int) $this->svc->obtener($e['id'])['cambio_origen']['fe_clave']);
        // no es fiscal: ni subdiario de IVA ni portal
        $this->assertSame(0, \App\Modules\Listados\IvaVentas::listar(['desde' => date('d/m/Y'), 'hasta' => date('d/m/Y'), 'pruebas' => '1'], 1, 50)['total']);
    }

    public function testCambioDeIgualImporteNoCobraNada(): void
    {
        $pr = $this->presupuesto();
        $r = $this->cambios->armar($this->cambio($pr, '1', [['articulo' => 'T-ART2', 'cantidad' => '1', 'precio' => '100']]));   // nuevo a 100 = lo devuelto
        $this->assertSame([], $r['errores'], 'sin diferencia no se pide forma de pago');
        $this->assertSame(0.0, $r['cambio']['diferencia']);
        $e = $this->cambios->emitir($r['cambio'], bin2hex(random_bytes(16)), 0);
        $this->assertSame(0, (int) $this->fila('SELECT COUNT(*) AS n FROM caja WHERE subdiario_id = ?', [$e['id']])['n']);
        $this->assertSame(0, (int) $this->fila('SELECT COUNT(*) AS n FROM ctact WHERE subdiario_id = ?', [$e['id']])['n']);
        $this->assertSame(9.0, $this->stock->saldo('T-ART'));
    }

    public function testDiferenciaACuentaCorrienteYValidaciones(): void
    {
        $pr = $this->presupuesto();
        $falta = $this->cambios->armar($this->cambio($pr, '1', [['articulo' => 'T-ART2', 'cantidad' => '1', 'precio' => '150']]));
        $this->assertTrue(isset($falta['errores']['forma_pago']), 'hay diferencia: pide la forma de pago');
        $cc = $this->cambios->armar($this->cambio($pr, '1', [['articulo' => 'T-ART2', 'cantidad' => '1', 'precio' => '150']], ['forma_pago' => '2']));
        $this->assertSame([], $cc['errores']);
        $e = $this->cambios->emitir($cc['cambio'], bin2hex(random_bytes(16)), 0);
        $ct = $this->fila('SELECT tico, tipo, saldo FROM ctact WHERE subdiario_id = ?', [$e['id']]);
        $this->assertSame([5, 'CM', '50.00'], [(int) $ct['tico'], $ct['tipo'], $ct['saldo']], 'la diferencia queda como deuda del cliente');
        // validaciones
        $this->assertStringContainsString('Elegí qué producto devuelve', $this->cambios->armar(['original' => (string) $pr['id'], 'nuevos' => [['articulo' => 'T-ART2']]])['errores']['devolver']);
        $this->assertStringContainsString('como máximo', $this->cambios->armar($this->cambio($pr, '5', [['articulo' => 'T-ART2', 'cantidad' => '1']]))['errores']['devolver'], 'no devuelve más de lo comprado');
        $this->assertStringContainsString('vale menos', $this->cambios->armar($this->cambio($pr, '1', [['articulo' => 'T-ART2', 'cantidad' => '1', 'precio' => '50']]))['errores']['nuevos'], 'lo nuevo no puede valer menos');
        $this->assertTrue(isset($this->cambios->armar($this->cambio($pr, '1', []))['errores']['nuevos']), 'hay que llevarse algo');
        $this->assertTrue(isset($this->cambios->armar($this->cambio($pr, '1', [['articulo' => 'NOEXISTE', 'cantidad' => '1']]))['errores']['nuevos']));
        $this->assertTrue(isset($this->cambios->armar(['original' => '999999'])['errores']['original']));
    }

    public function testLaDiferenciaSePuedePagarConVariasFormas(): void
    {
        $pr = $this->presupuesto();
        Db::pdo()->exec("INSERT IGNORE INTO forma_de_pagos (codigo, descripcion, tipo) VALUES ('TJ', 'TARJETA', 3)");
        // devuelve 1 (100) y se lleva 1 de T-ART2 (150): diferencia 50 = 20 en efectivo (entrega 50: vuelto 30) + 30 con tarjeta
        $r = $this->cambios->armar($this->cambio($pr, '1', [['articulo' => 'T-ART2', 'cantidad' => '1', 'precio' => '150']],
            ['pagos' => [['fpago' => 'EF', 'importe' => '20', 'recibido' => '50'], ['fpago' => 'TJ', 'importe' => '30']]]));
        $this->assertSame([], $r['errores']);
        $e = $this->cambios->emitir($r['cambio'], bin2hex(random_bytes(16)), 0);
        $this->assertSame(30.0, $e['vuelto']);
        $this->assertSame(2, (int) $this->fila('SELECT COUNT(*) AS n FROM caja WHERE subdiario_id = ?', [$e['id']])['n']);
        $mal = $this->cambios->armar($this->cambio($pr, '1', [['articulo' => 'T-ART2', 'cantidad' => '1', 'precio' => '150']],
            ['pagos' => [['fpago' => 'EF', 'importe' => '20'], ['fpago' => 'TJ', 'importe' => '10']]]));
        $this->assertStringContainsString('Faltan', $mal['errores']['pagos']);
    }

    public function testNoSeDevuelveDosVecesLoMismoNiSeRepiteElCambio(): void
    {
        $pr = $this->presupuesto();                                   // 2 unidades
        $op = bin2hex(random_bytes(16));
        $r = $this->cambios->armar($this->cambio($pr, '1', [['articulo' => 'T-ART2', 'cantidad' => '1', 'precio' => '100']]));
        $e = $this->cambios->emitir($r['cambio'], $op, 0);
        $this->assertTrue($this->cambios->emitir($r['cambio'], $op, 0)['repetido'], 'doble envío: no hace otro');
        $this->assertSame(1, (int) Db::pdo()->query("SELECT COUNT(*) FROM subdiario WHERE tipo = 'CM'")->fetchColumn());
        $o = $this->cambios->original($pr['id']);
        $this->assertSame([2.0, 1.0, 1.0], [$o['items'][0]['unidades'], $o['items'][0]['devuelto'], $o['items'][0]['disponible']]);
        $otro = $this->cambios->armar($this->cambio($pr, '2', [['articulo' => 'T-ART2', 'cantidad' => '2', 'precio' => '100']]));
        $this->assertStringContainsString('como máximo', $otro['errores']['devolver'], 'ya devolvió una de las dos');
        // dos cambios armados a la vez sobre lo mismo: el segundo se rechaza al grabar
        $a = $this->cambios->armar($this->cambio($pr, '1', [['articulo' => 'T-ART2', 'cantidad' => '1', 'precio' => '100']]));
        $b = $this->cambios->armar($this->cambio($pr, '1', [['articulo' => 'T-ART2', 'cantidad' => '1', 'precio' => '100']]));
        $this->cambios->emitir($a['cambio'], bin2hex(random_bytes(16)), 0);
        try {
            $this->cambios->emitir($b['cambio'], bin2hex(random_bytes(16)), 0);
            $this->assertTrue(false, 'no debía devolver lo mismo otra vez');
        } catch (CambioInvalido $ex) {
            $this->assertSame(10.0, $this->stock->saldo('T-ART'), 'el stock quedó bien: 8 + 2 devueltas');
        }
        $this->assertTrue($e['id'] > 0);
    }

    public function testFacturaConDescuentoYNoSeCambiaLoQueSalioDeUnPresupuesto(): void
    {
        // factura A con 10 % de descuento: se devuelve al precio que pagó el cliente (con IVA y con el descuento)
        $fc = $this->emitir($this->pedido(['descuento' => '10', 'items' => [['articulo' => 'T-ART', 'descripcion' => '', 'cantidad' => '2', 'precio' => '100', 'alicuota' => '21']]]));
        $o = $this->cambios->original($fc['id']);
        $this->assertSame(108.9, $o['items'][0]['unitario'], '100 neto − 10 % + 21 % de IVA = 108,90');
        // la factura que sale de un presupuesto no se cambia (no movió stock)
        $pr = $this->presupuesto();
        $fac = $this->svc->emitir($this->svc->armar(['tipo' => 'FC', 'presupuesto' => (string) $pr['id']])['cbte'], bin2hex(random_bytes(16)), 0);
        $this->assertStringContainsString('sobre el presupuesto', $this->cambios->original($fac['id']));
    }

    public function testPantallasTicketPermisosYModoApagado(): void
    {
        $pr = $this->presupuesto();
        $this->loginComo($this->crearUsuario('t_op_cm', 'operador'));
        $this->assertSame(200, $this->get('/cambios')->status);
        $b = $this->get('/cambios', ['q' => 'PR-X-0009-00000001']);
        $this->assertStringContainsString('Hacer el cambio', $b->body);
        $this->assertStringContainsString('Cliente', $b->body);
        $n = $this->get('/cambios/nuevo', ['cbte' => (string) $pr['id']]);
        $this->assertSame(200, $n->status);
        $this->assertStringContainsString('Lo que devuelve el cliente', $n->body);
        $this->assertStringContainsString('Lo que se lleva', $n->body);
        $ver = $this->get('/comprobantes/' . $pr['id']);
        $this->assertStringContainsString('Ticket de cambio', $ver->body);
        $this->assertStringContainsString('Hacer un cambio', $ver->body);
        $pdf = $this->get('/cambios/ticket/' . $pr['id']);
        $this->assertSame(200, $pdf->status);
        $this->assertStringContainsString('%PDF', substr($pdf->body, 0, 8));
        // el POST completo (como lo manda la pantalla)
        $post = $this->post('/cambios', ['original' => (string) $pr['id'], 'operacion' => bin2hex(random_bytes(16)), 'devolver' => [$pr['linea'] => '1'],
            'nuevos' => [['articulo' => 'T-ART2', 'cantidad' => '1', 'precio' => '100']]]);
        $this->assertSame(302, $post->status);
        $id = (int) $this->fila("SELECT fe_clave FROM subdiario WHERE tipo = 'CM'")['fe_clave'];
        $this->assertStringContainsString('DEVUELVE: ', $this->get('/comprobantes/' . $id)->body);
        $this->assertSame(422, $this->post('/cambios', ['original' => (string) $pr['id'], 'operacion' => bin2hex(random_bytes(16)), 'devolver' => [$pr['linea'] => '1']])->status, 'sin producto nuevo');
        // sin permiso de cambios: el operador no entra, pero sí imprime el ticket (comprobantes.ver)
        Db::pdo()->exec("DELETE rp FROM rol_permisos rp JOIN permisos p ON p.id = rp.permiso_id JOIN roles r ON r.id = rp.rol_id WHERE r.codigo = 'operador' AND p.codigo = 'cambios.hacer'");
        \App\Modules\Auth\Auth::reset();
        try {
            $this->assertSame(403, $this->get('/cambios')->status);
            $this->assertSame(403, $this->get('/cambios/nuevo', ['cbte' => (string) $pr['id']])->status);
            $this->assertSame(403, $this->post('/cambios', ['original' => (string) $pr['id']])->status);
            $this->assertSame(403, $this->get('/cambios/buscar-articulo', ['q' => 'T-ART'])->status);
            $this->assertSame(200, $this->get('/cambios/ticket/' . $pr['id'])->status);
        } finally {
            Db::pdo()->exec("INSERT IGNORE INTO rol_permisos (rol_id, permiso_id) SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'cambios.hacer' WHERE r.codigo = 'operador'");
        }
        // modo apagado en Parámetros: nada funciona y el menú no lo muestra
        Db::pdo()->exec('UPDATE parametros SET ticket_cambio = 0 WHERE id = 1');
        CambiosService::reset();
        \App\Modules\Auth\Auth::reset();
        $this->assertSame(404, $this->get('/cambios')->status);
        $this->assertSame(404, $this->get('/cambios/ticket/' . $pr['id'])->status);
        $this->assertStringNotContainsString('Ticket de cambio', $this->get('/comprobantes/' . $pr['id'])->body);
        $this->assertFalse(\App\Core\Menu::visible('cambios'));
    }
}
