<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\Caja\CajaService;
use App\Modules\Compras\ComprasService;
use App\Modules\Compras\OrdenesPagoService;
use App\Modules\Compras\OrdenPagoPdf;
use App\Modules\Compras\ProveedoresService;
use App\Modules\Configuracion\Configuracion;
use App\Modules\Stock\StockService;

/** Órdenes de pago, cuenta corriente de proveedores y compras con artículos (0.46.0, entrega B de compras). */
final class OrdenesPagoTest extends ComprobantesTestCase
{
    private ComprasService $c;
    private OrdenesPagoService $op;
    private int $admin;
    private int $prov;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limpiar();
        $this->c = new ComprasService();
        $this->op = new OrdenesPagoService();
        $this->admin = $this->crearUsuario('t_op_admin', 'admin');
        $r = (new ProveedoresService())->guardar(null, ['codigo' => '99011', 'razon_social' => 'T PROVEEDOR PAGOS SA', 'cuit' => '30-70880558-7', 'iva' => '1']);
        $this->assertTrue($r['ok'], json_encode($r['errores'] ?? []));
        $this->prov = $r['id'];
    }

    protected function tearDown(): void
    {
        $this->limpiar();
        Db::pdo()->exec("UPDATE configuracion SET valor = '0' WHERE clave = 'stock.controlar'");
        Configuracion::reset();
        parent::tearDown();
    }

    private function limpiar(): void
    {
        $pdo = Db::pdo();
        foreach (['compras', 'compras_iva', 'compras_tributos', 'compras_reparto', 'compras_adjuntos', 'compras_pagos', 'compras_items', 'ordenes_pago',
            'ordenes_pago_imputaciones', 'ordenes_pago_medios', 'stock_movimientos', 'stock_saldos'] as $t) {
            $pdo->exec("DELETE FROM {$t}");
        }
        $pdo->exec("DELETE FROM proveedores WHERE razon_social LIKE 'T %'");
    }

    private function compra(array $extra = []): int
    {
        $a = $this->c->armar($extra + ['proveedor' => (string) $this->prov, 'tipo' => 'FC', 'letra' => 'A', 'punto_venta' => '3', 'numero' => (string) random_int(1, 99999999),
            'fecha' => date('d/m/Y'), 'neto' => ['21' => '1.000,00'], 'forma' => 'C']);
        $this->assertSame([], $a['errores'], 'sin errores');
        $g = $this->c->grabar($a['compra'], $this->admin, bin2hex(random_bytes(16)));
        $this->assertTrue($g['ok'], $g['error'] ?? '');
        return $g['id'];
    }

    /** @return array<string,mixed> */
    private function emitirOp(array $in, ?string $error = null): array
    {
        $a = $this->op->armar($in + ['proveedor' => (string) $this->prov, 'fecha' => date('d/m/Y')]);
        if ($error !== null && !$a['ok']) {
            $this->assertStringContainsString($error, implode(' | ', $a['errores']));
            return $a;
        }
        $this->assertSame([], $a['errores']);
        $r = $this->op->emitir($a['orden'], $this->admin, bin2hex(random_bytes(16)));
        if ($error !== null) {
            $this->assertFalse($r['ok']);
            $this->assertStringContainsString($error, $r['error']);
        } else {
            $this->assertTrue($r['ok'], $r['error'] ?? '');
        }
        return $r;
    }

    private function saldo(int $compra): float
    {
        return (float) Db::pdo()->query('SELECT saldo FROM compras WHERE id = ' . $compra)->fetchColumn();
    }

    public function testPagoParcialConNotaDeCreditoYRetencionDejaElResto(): void
    {
        $fc = $this->compra();                                       // 1.210
        $nc = $this->compra(['tipo' => 'NC', 'neto' => ['21' => '100']]); // 121
        $p = $this->op->pendientes($this->prov);
        $this->assertSame([1, 1, 1089.0], [count($p['debitos']), count($p['creditos']), $p['saldo']]);
        $r = $this->emitirOp(['deb' => [$fc => '1.000,00'], 'cre' => [$nc => '121'], 'medios' => [
            ['medio' => 'T', 'importe' => '829', 'detalle' => 'Banco Nación'],
            ['medio' => 'R', 'importe' => '50', 'retencion' => 'GAN', 'numero' => 'C-0001'],
        ]]);
        $this->assertSame([210.0, 0.0, 210.0], [$this->saldo($fc), $this->saldo($nc), (new ProveedoresService())->saldo($this->prov)]);
        $o = $this->op->obtener($r['id']);
        $this->assertSame(['1000.00', '121.00', '50.00', '879.00', '0.00'], [$o['debitos'], $o['creditos'], $o['retenciones'], $o['pagado'], $o['anticipo']]);
        $this->assertSame('%PDF', substr(OrdenPagoPdf::generar($o), 0, 4));
        $this->assertCount(1, $this->c->obtener($fc)['ordenes'], 'la compra muestra su orden de pago');
        $this->assertStringContainsString('orden de pago', $this->c->anular($fc, $this->admin, 'x')['error'], 'una compra con orden de pago no se anula');
        // falta plata
        $this->emitirOp(['deb' => [$fc => '210'], 'medios' => [['medio' => 'T', 'importe' => '100']]], 'Falta pagar');
        // más que el saldo
        $this->emitirOp(['deb' => [$fc => '211'], 'medios' => [['medio' => 'T', 'importe' => '211']]], 'no más que su saldo');
        // anular devuelve los saldos y entonces la compra se puede anular
        $this->assertTrue($this->op->anular($r['id'], $this->admin, 'mal cargada')['ok']);
        $this->assertSame([1210.0, 121.0], [$this->saldo($fc), $this->saldo($nc)]);
        $this->assertFalse($this->op->anular($r['id'], $this->admin, 'otra vez')['ok']);
        $this->assertTrue($this->c->anular($fc, $this->admin, 'x')['ok']);
    }

    public function testAnticipoQuedaAFavorYSeUsaEnLaSiguiente(): void
    {
        $fc = $this->compra();
        $r1 = $this->emitirOp(['deb' => [$fc => '1210'], 'medios' => [['medio' => 'T', 'importe' => '1.500']]]);
        $this->assertSame([0.0, -290.0], [$this->saldo($fc), (new ProveedoresService())->saldo($this->prov)], 'lo de más queda como anticipo');
        $ant = $this->op->pendientes($this->prov)['anticipos'];
        $this->assertSame([290.0], array_map(static fn ($a) => (float) $a['anticipo_saldo'], $ant));
        $fc2 = $this->compra(['neto' => ['21' => '500']]); // 605
        $r2 = $this->emitirOp(['deb' => [$fc2 => '605'], 'ant' => [(int) $ant[0]['id'] => '290'], 'medios' => [['medio' => 'O', 'importe' => '315', 'detalle' => 'Mercado Pago']]]);
        $this->assertSame(0.0, (new ProveedoresService())->saldo($this->prov));
        $this->assertStringContainsString('anticipo', $this->op->anular($r1['id'], $this->admin, 'x')['error'], 'no se anula una orden cuyo anticipo se usó');
        $this->assertTrue($this->op->anular($r2['id'], $this->admin, 'x')['ok']);
        $this->assertTrue($this->op->anular($r1['id'], $this->admin, 'x')['ok'], 'anulada la que lo usó, ya se puede');
        // anticipo sin comprobantes
        $this->emitirOp(['medios' => [['medio' => 'T', 'importe' => '100']]]);
        $this->assertSame(1210.0 + 605.0 - 100.0, (new ProveedoresService())->saldo($this->prov));
        $cta = $this->op->cuentaCorriente($this->prov, null, null);
        $this->assertSame(1715.0, round((float) end($cta['filas'])['saldo'], 2), 'la cuenta corriente termina en el saldo');
    }

    public function testChequeDeLaCarteraYEfectivoDeLaCaja(): void
    {
        $pdo = Db::pdo();
        $pdo->exec("INSERT INTO caja (fecha, tipo, letra, sucursal, prefijo, comprobante, cliente, p_fpago, p_nro, p_fecvto, p_importe, cheque_estado)
            VALUES (CURDATE(), 'RC', 'X', 99, 9, 1, 990001, 'CH', '4455', CURDATE() + INTERVAL 20 DAY, 700, '')");
        $ch = (int) $pdo->lastInsertId();
        $fc = $this->compra();
        $this->assertSame([$ch], array_map(static fn ($x) => (int) $x['id'], $this->op->chequesEnCartera()));
        $in = ['deb' => [$fc => '1210'], 'medios' => [['medio' => 'Q', 'cheque' => (string) $ch, 'importe' => '1'], ['medio' => 'E', 'importe' => '510']]];
        $this->emitirOp($in, 'turno');
        $caja = new CajaService();
        $t = $caja->abrir($this->admin, '1.000')['id'];
        $a = $this->op->armar($in + ['proveedor' => (string) $this->prov]);
        $this->assertSame(700.0, $a['orden']['medios'][0]['importe'], 'el cheque va por su importe');
        // otro usuario entrega el cheque mientras tanto
        $pdo->exec("UPDATE caja SET cheque_estado = 'E' WHERE fe_clave = {$ch}");
        $this->assertStringContainsString('cartera', $this->op->emitir($a['orden'], $this->admin, null)['error']);
        $pdo->exec("UPDATE caja SET cheque_estado = '' WHERE fe_clave = {$ch}");
        $r = $this->op->emitir($a['orden'], $this->admin, $op = bin2hex(random_bytes(16)));
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertSame([true, $r['id']], [$this->op->emitir($a['orden'], $this->admin, $op)['repetida'] ?? false, $this->op->emitir($a['orden'], $this->admin, $op)['id']], 'doble envío');
        $this->assertSame('E', $pdo->query("SELECT cheque_estado FROM caja WHERE fe_clave = {$ch}")->fetchColumn(), 'el cheque queda entregado');
        $this->assertSame([], $this->op->chequesEnCartera());
        $this->assertSame(510.0, $caja->resumenTurno($t)['egresos'], 'el efectivo sale del turno');
        $this->assertTrue($this->op->anular($r['id'], $this->admin, 'otro cheque')['ok']);
        $this->assertSame('', $pdo->query("SELECT cheque_estado FROM caja WHERE fe_clave = {$ch}")->fetchColumn(), 'anulada, el cheque vuelve a la cartera');
        $this->assertSame(0.0, $caja->resumenTurno($t)['egresos'] - $caja->resumenTurno($t)['ingresos']);
        $this->assertTrue($caja->verificar()['ok']);
    }

    public function testSaldoQueCambiaMientrasSeArma(): void
    {
        $fc = $this->compra();
        $a = $this->op->armar(['proveedor' => (string) $this->prov, 'deb' => [$fc => '1210'], 'medios' => [['medio' => 'T', 'importe' => '1210']]]);
        $this->assertTrue($a['ok']);
        $this->emitirOp(['deb' => [$fc => '1000'], 'medios' => [['medio' => 'T', 'importe' => '1000']]]);
        $r = $this->op->emitir($a['orden'], $this->admin, null);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('cambió', $r['error']);
        $this->assertSame(210.0, $this->saldo($fc));
    }

    public function testCompraConArticulosSumaStockYActualizaElCosto(): void
    {
        $pdo = Db::pdo();
        $pdo->exec("UPDATE configuracion SET valor = '1' WHERE clave = 'stock.controlar'");
        Configuracion::reset();
        $pdo->exec("UPDATE articulos SET controla_stock = 1, costo = 50, moneda = '$' WHERE codigo = 'T-ART'");
        $st = new StockService();
        $base = ['con_articulos' => '1', 'actualizar_costo' => '1', 'items' => [['articulo' => 't-art', 'cantidad' => '10', 'costo' => '80']]];
        $this->assertStringContainsString('suman', implode(' ', $this->c->armar($base + ['proveedor' => (string) $this->prov, 'tipo' => 'FC', 'letra' => 'A', 'punto_venta' => '1',
            'numero' => '5', 'fecha' => date('d/m/Y'), 'neto' => ['21' => '500'], 'forma' => 'C'])['errores']), 'los artículos no pueden superar el neto');
        $id = $this->compra($base);
        $this->assertSame([10.0, 80.0], [$st->saldo('T-ART'), (float) $pdo->query("SELECT costo FROM articulos WHERE codigo = 'T-ART'")->fetchColumn()]);
        $c = $this->c->obtener($id);
        $this->assertSame(['T-ART', '50.0000'], [$c['items'][0]['articulo'], $c['items'][0]['costo_anterior']]);
        // nota de crédito: devuelve stock sin tocar el costo
        $this->compra(['tipo' => 'NC', 'neto' => ['21' => '100'], 'con_articulos' => '1', 'actualizar_costo' => '1', 'items' => [['articulo' => 'T-ART', 'cantidad' => '2', 'costo' => '40']]]);
        $this->assertSame([8.0, 80.0], [$st->saldo('T-ART'), (float) $pdo->query("SELECT costo FROM articulos WHERE codigo = 'T-ART'")->fetchColumn()]);
        // sin actualizar costo
        $this->compra(['con_articulos' => '1', 'items' => [['articulo' => 'T-ART', 'cantidad' => '1', 'costo' => '999']]]);
        $this->assertSame([9.0, 80.0], [$st->saldo('T-ART'), (float) $pdo->query("SELECT costo FROM articulos WHERE codigo = 'T-ART'")->fetchColumn()]);
        // anular la compra saca lo que había entrado
        $this->assertTrue($this->c->anular($id, $this->admin, 'era de otro')['ok']);
        $this->assertSame(-1.0, $st->saldo('T-ART'));
        $this->assertStringContainsString('no existe', implode(' ', $this->c->armar(['items' => [['articulo' => 'NO-HAY', 'cantidad' => '1', 'costo' => '1']]] + $base + [
            'proveedor' => (string) $this->prov, 'tipo' => 'FC', 'letra' => 'A', 'punto_venta' => '1', 'numero' => '6', 'fecha' => date('d/m/Y'), 'neto' => ['21' => '500'], 'forma' => 'C'])['errores']));
    }

    public function testPantallasYPermisos(): void
    {
        $fc = $this->compra();
        $oper = $this->crearUsuario('t_op_oper', 'operador');
        $this->loginComo($oper);
        foreach (['/compras/pagos', '/compras/pagos/nueva', '/compras/proveedores/cuenta'] as $ruta) {
            $this->assertSame(403, $this->get($ruta)->status, "el operador no entra a {$ruta}");
        }
        $this->assertSame(403, $this->post('/compras/pagos/nueva', ['proveedor' => (string) $this->prov])->status);
        $this->loginComo($this->admin);
        $this->assertStringContainsString('1.210,00', $this->get('/compras/pagos/nueva', ['compra' => (string) $fc])->body, 'desde la compra queda tildada con su saldo');
        $r = $this->post('/compras/pagos/nueva', ['proveedor' => (string) $this->prov, 'fecha' => date('d/m/Y'), 'operacion' => bin2hex(random_bytes(16)),
            'deb' => [$fc => '1.210,00'], 'medios' => [['medio' => 'T', 'importe' => '1.210,00']]]);
        $this->assertSame(302, $r->status, $r->body);
        $id = (int) Db::pdo()->query('SELECT MAX(id) FROM ordenes_pago')->fetchColumn();
        foreach (['/compras/pagos', '/compras/pagos/ver/' . $id, '/compras/proveedores/cuenta'] as $ruta) {
            $this->assertSame(200, $this->get($ruta, ['proveedor' => (string) $this->prov])->status, $ruta);
        }
        $this->assertStringContainsString('application/pdf', $this->get('/compras/pagos/pdf/' . $id)->headers['Content-Type'] ?? '');
        foreach (['/compras/pagos', '/compras/proveedores/cuenta'] as $ruta) {
            $this->assertStringContainsString('spreadsheetml', $this->get($ruta, ['excel' => '1', 'proveedor' => (string) $this->prov])->headers['Content-Type'] ?? '', "Excel de {$ruta}");
        }
        $this->assertSame(422, $this->post('/compras/pagos/mail', ['orden' => (string) $id, 'para' => 'no-es-mail'])->status);
        $this->assertSame(302, $this->post('/compras/pagos/anular', ['orden' => (string) $id, 'motivo' => 'prueba'])->status);
        $this->assertSame(1210.0, $this->saldo($fc));
    }
}
