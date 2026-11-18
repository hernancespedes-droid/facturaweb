<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\Kiosco\KioscoService;
use App\Modules\Stock\StockService;

/** Modo Kiosco: tarjetas con stock, carrito que genera un presupuesto con «Cómo se paga», y permiso por rol. */
final class KioscoTest extends ComprobantesTestCase
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
        $pdo->exec('UPDATE parametros SET kiosco = 1 WHERE id = 1');
        KioscoService::reset();
        $pdo->exec("INSERT IGNORE INTO forma_de_pagos (codigo, descripcion, tipo) VALUES ('EF', 'EFECTIVO', 1)");
        $pdo->exec("UPDATE forma_de_pagos SET acepta_vuelto = 1 WHERE codigo = 'EF'");
        $pdo->exec("INSERT IGNORE INTO rubros (codigo, descripcion) VALUES ('T1', 'RUBRO DE PRUEBA')");
        $pdo->exec("UPDATE articulos SET rubro = 'T1', precio_unitario = 100 WHERE codigo = 'T-ART'");
        $pdo->exec("INSERT INTO articulos (codigo, descripcion, alicuota, precio_unitario) VALUES ('T-ART2', 'OTRO ARTICULO DE PRUEBA', '21', 150)");
        $this->stock = new StockService();
        $this->stock->cargar('INGRESO', 'T-ART', $this->stock->depositoPrincipal(), '5', 'unidades', 'inicial', null);
        $this->stock->cargar('INGRESO', 'T-ART2', $this->stock->depositoPrincipal(), '3', 'unidades', 'inicial', null);
    }

    protected function tearDown(): void
    {
        $pdo = Db::pdo();
        $pdo->exec('DELETE FROM stock_movimientos');
        $pdo->exec('DELETE FROM stock_saldos');
        $pdo->exec("UPDATE configuracion SET valor = '0' WHERE clave = 'stock.controlar'");
        $pdo->exec('UPDATE parametros SET kiosco = 0 WHERE id = 1');
        $pdo->exec("DELETE FROM articulos WHERE codigo = 'T-ART2'");
        $pdo->exec("DELETE FROM rubros WHERE codigo = 'T1'");
        \App\Modules\Configuracion\Configuracion::reset();
        KioscoService::reset();
        parent::tearDown();
    }

    private function carrito(array $extra = []): array
    {
        return $extra + ['cliente' => '990002', 'items' => [
            ['articulo' => 'T-ART', 'cantidad' => '2', 'precio' => '100', 'alicuota' => '21'],
            ['articulo' => 'T-ART2', 'cantidad' => '1', 'precio' => '150', 'alicuota' => '21'],
        ]];
    }

    public function testTarjetasConStockYRubros(): void
    {
        $this->loginComo($this->crearUsuario('t_op_k', 'operador'));
        $pag = $this->get('/kiosco');
        $this->assertSame(200, $pag->status);
        foreach (['data-kiosco', 'Carrito', 'data-cobrar', 'RUBRO DE PRUEBA', 'kiosco.js'] as $txt) {
            $this->assertStringContainsString($txt, $pag->body, $txt);
        }
        $j = json_decode($this->get('/kiosco/articulos', ['q' => 'T-ART'])->body, true);
        $codigos = array_column($j['items'], 'codigo');
        $this->assertTrue(in_array('T-ART', $codigos, true) && in_array('T-ART2', $codigos, true));
        $art = array_values(array_filter($j['items'], static fn (array $a) => $a['codigo'] === 'T-ART'))[0];
        $this->assertSame([true, 5.0], [$art['stock']['controla'], (float) $art['stock']['total']], 'cada tarjeta trae su stock');
        $this->assertSame(['T-ART'], array_column(json_decode($this->get('/kiosco/articulos', ['q' => 'T-ART', 'rubro' => 'T1'])->body, true)['items'], 'codigo'), 'filtra por rubro');
        $ex = json_decode($this->get('/kiosco/articulos', ['q' => 'T-ART2', 'exacto' => '1'])->body, true);
        $this->assertSame(['T-ART2'], array_column($ex['items'], 'codigo'), 'un código exacto (pistola) se resuelve directo');
        $cli = json_decode($this->get('/kiosco/buscar-cliente', ['q' => 'CONSUMIDOR'])->body, true);
        $this->assertSame(990002, (int) $cli[0]['codigo']);
    }

    public function testCobrarArmaElPresupuestoYPideComoSePagaYEmite(): void
    {
        $this->loginComo($this->crearUsuario('t_op_k2', 'operador'));
        $rev = $this->post('/kiosco/cobrar', $this->carrito());
        $this->assertSame(200, $rev->status);
        foreach (['Cómo se paga', 'Total a cobrar', 'action="' . url('/kiosco/emitir') . '"', 'Volver al kiosco', 'Presupuesto X', 'Agregar otra forma de pago'] as $txt) {
            $this->assertStringContainsString($txt, $rev->body, $txt);
        }
        $this->assertSame(0, (int) Db::pdo()->query('SELECT COUNT(*) FROM subdiario')->fetchColumn(), 'cobrar no graba: falta elegir cómo se paga');
        preg_match('/name="datos" value="([^"]*)"/', $rev->body, $m);
        $datos = html_entity_decode($m[1], ENT_QUOTES);
        $op = bin2hex(random_bytes(16));
        // total 350 (2 × 100 + 150): paga 200 en efectivo (entrega 300 → vuelto 100) y 150 a cuenta corriente
        $ok = $this->post('/kiosco/emitir', ['datos' => $datos, 'operacion' => $op, 'forma_pago' => '1', 'pagos' => [['fpago' => 'EF', 'importe' => '200', 'recibido' => '300'], ['fpago' => 'CC', 'importe' => '150']]]);
        $id = (int) Db::pdo()->query("SELECT fe_clave FROM subdiario WHERE tipo = 'PR'")->fetchColumn();
        $this->assertSame(302, $ok->status);
        $this->assertSame(url('/kiosco') . '?hecho=' . $id, $ok->headers['Location'], 'vuelve al kiosco con el aviso');
        $h = $this->svc->obtener($id);
        $this->assertSame(['PR', 'X', '350.00', 990002], [$h['tipo'], $h['letra'], $h['neto'], (int) $h['cliente']]);
        $this->assertSame(3.0, $this->stock->saldo('T-ART'), 'descuenta el stock: 5 − 2');
        $this->assertSame(150.0, (float) Db::pdo()->query('SELECT SUM(saldo) FROM ctact WHERE subdiario_id = ' . $id)->fetchColumn());
        // el aviso de la venta hecha
        $pag = $this->get('/kiosco', ['hecho' => (string) $id]);
        $this->assertStringContainsString('Venta registrada', $pag->body);
        $this->assertStringContainsString('Dar vuelto: $ 100,00', $pag->body);
        $this->assertStringContainsString('data-hecho="1"', $pag->body);
        // doble envío: no hace otro
        $this->post('/kiosco/emitir', ['datos' => $datos, 'operacion' => $op, 'forma_pago' => '1', 'pagos' => [['fpago' => 'EF', 'importe' => '200', 'recibido' => '300'], ['fpago' => 'CC', 'importe' => '150']]]);
        $this->assertSame(1, (int) Db::pdo()->query("SELECT COUNT(*) FROM subdiario WHERE tipo = 'PR'")->fetchColumn());
    }

    public function testCarritoConProblemasVuelveAlKioscoConElMotivo(): void
    {
        $this->loginComo($this->crearUsuario('t_op_k3', 'operador'));
        $r = $this->post('/kiosco/cobrar', ['cliente' => '990002', 'items' => [['articulo' => 'NOEXISTE', 'cantidad' => '1', 'precio' => '10', 'alicuota' => '21']]]);
        $this->assertRedirect('/kiosco', $r);
        $vacio = $this->post('/kiosco/cobrar', ['cliente' => '990002', 'items' => []]);
        $this->assertRedirect('/kiosco', $vacio);
        $sinCli = $this->post('/kiosco/cobrar', ['cliente' => '', 'items' => $this->carrito()['items']]);
        $this->assertRedirect('/kiosco', $sinCli);
        $this->assertSame(0, (int) Db::pdo()->query('SELECT COUNT(*) FROM subdiario')->fetchColumn());
    }

    public function testElKioscoSoloEmitePresupuestosYRespetaElPermisoDelRol(): void
    {
        $this->loginComo($this->crearUsuario('t_op_k4', 'operador'));
        // con el permiso del kiosco no se emite cualquier otro comprobante
        $d = json_encode(['tipo' => 'FC', 'origen' => 'kiosco', 'cliente' => '990002', 'items' => $this->carrito()['items'], 'forma_pago' => '2'], JSON_UNESCAPED_UNICODE);
        $r = $this->post('/kiosco/emitir', ['datos' => $d, 'operacion' => bin2hex(random_bytes(16)), 'forma_pago' => '2']);
        $this->assertRedirect('/kiosco', $r);
        $d2 = json_encode(['tipo' => 'PR', 'cliente' => '990002', 'items' => $this->carrito()['items']], JSON_UNESCAPED_UNICODE); // sin origen kiosco
        $this->assertRedirect('/kiosco', $this->post('/kiosco/emitir', ['datos' => $d2, 'operacion' => bin2hex(random_bytes(16)), 'forma_pago' => '2']));
        $this->assertSame(0, (int) Db::pdo()->query('SELECT COUNT(*) FROM subdiario')->fetchColumn());
        $this->assertTrue(\App\Core\Menu::visible('kiosco'));
        // sin kiosco.usar: ninguna de las rutas, aunque pueda emitir comprobantes
        Db::pdo()->exec("DELETE rp FROM rol_permisos rp JOIN permisos p ON p.id = rp.permiso_id JOIN roles r ON r.id = rp.rol_id WHERE r.codigo = 'operador' AND p.codigo = 'kiosco.usar'");
        \App\Modules\Auth\Auth::reset();
        try {
            foreach (['/kiosco', '/kiosco/articulos', '/kiosco/buscar-cliente'] as $ruta) {
                $this->assertSame(403, $this->get($ruta, ['q' => 'x'])->status, $ruta);
            }
            $this->assertSame(403, $this->post('/kiosco/cobrar', $this->carrito())->status);
            $this->assertSame(403, $this->post('/kiosco/emitir', ['datos' => '{}'])->status);
            $this->assertFalse(\App\Core\Menu::visible('kiosco'));
            $this->assertSame(200, $this->get('/comprobantes/nuevo', ['tipo' => 'PR'])->status, 'el presupuesto normal sigue con su permiso');
        } finally {
            Db::pdo()->exec("INSERT IGNORE INTO rol_permisos (rol_id, permiso_id) SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'kiosco.usar' WHERE r.codigo = 'operador'");
        }
    }

    public function testModoApagadoYPermisoEnLosRoles(): void
    {
        $this->loginComo($this->crearUsuario('t_adm_k', 'admin'));
        $this->assertSame(200, $this->get('/kiosco')->status);
        Db::pdo()->exec('UPDATE parametros SET kiosco = 0 WHERE id = 1');
        KioscoService::reset();
        \App\Modules\Auth\Auth::reset();
        $this->assertSame(404, $this->get('/kiosco')->status);
        $this->assertSame(404, $this->get('/kiosco/articulos')->status);
        $this->assertSame(404, $this->post('/kiosco/cobrar', $this->carrito())->status);
        $this->assertFalse(\App\Core\Menu::visible('kiosco'), 'el menú no lo muestra con el modo apagado');
        // el permiso se da o se quita en Roles (opción «Kiosco»)
        $this->assertTrue(isset(\App\Modules\Roles\RolesService::MENU['kiosco']));
        $this->assertSame('kiosco.usar', \App\Modules\Roles\RolesService::MENU['kiosco'][0]);
        $this->assertStringContainsString('Kiosco', $this->get('/roles/nuevo')->body, 'Roles lo ofrece como opción para tildar');
        // la marca está en Parámetros
        $this->assertTrue(isset(\App\Modules\Tablas\Definiciones::de('parametros')['campos']['kiosco']));
    }
}
