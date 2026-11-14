<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\CuentaCorriente\CuentaCorrienteError;
use App\Modules\CuentaCorriente\CuentaCorrienteService;

/** Cuenta corriente y recibos: cobrar, anticipo, imputación manual, ajustes, anulación, cheques, deuda y doble envío. */
final class CuentaCorrienteTest extends ComprobantesTestCase
{
    private CuentaCorrienteService $cc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cc = new CuentaCorrienteService();
        Db::pdo()->exec("INSERT IGNORE INTO bancos (codigo, descripcion) VALUES (990, 'BANCO DE PRUEBA')");
    }

    /** Factura a cuenta corriente de 242 ($200 + IVA) del cliente 990001. @return array{id:int,ctact:int} */
    private function factura(string $cliente = '990001'): array
    {
        $fc = $this->emitir($this->pedido(['cliente' => $cliente]));
        $ct = (int) $this->fila('SELECT fe_clave FROM ctact WHERE subdiario_id = ?', [$fc['id']])['fe_clave'];
        return ['id' => $fc['id'], 'ctact' => $ct];
    }

    /** @param array<string,mixed> $extra */
    private function recibo(array $imputar, string $efectivo, array $extra = []): array
    {
        $in = $extra + ['cliente' => '990001', 'fecha' => date('d/m/Y'), 'sucursal' => '99', 'imputar' => $imputar,
            'pagos' => [['fpago' => 'EF', 'importe' => $efectivo]]];
        $r = $this->cc->armarRecibo($in);
        $this->assertSame([], $r['errores'], 'el recibo no tiene errores: ' . json_encode($r['errores']));
        return $this->cc->emitirRecibo($r['recibo'], bin2hex(random_bytes(16)), 0);
    }

    private function saldo(int $ctact): float
    {
        return (float) $this->fila('SELECT saldo FROM ctact WHERE fe_clave = ?', [$ctact])['saldo'];
    }

    public function testReciboCancelaUnaFacturaYSePuedeCobrarEnPartes(): void
    {
        $f = $this->factura();
        $this->assertSame(242.0, $this->cc->saldo(990001));
        $r = $this->recibo([$f['ctact'] => '100'], '100');
        $this->assertSame(1, $r['numero']);
        $this->assertSame(142.0, $this->saldo($f['ctact']), 'cobro parcial');
        $this->recibo([$f['ctact'] => '142'], '142');
        $this->assertSame(0.0, $this->saldo($f['ctact']));
        $this->assertSame(0.0, $this->cc->saldo(990001));
        $this->assertSame(2, (int) $this->fila('SELECT COUNT(*) n FROM imputaciones')['n']);
        $this->assertSame(2, (int) $this->fila("SELECT ultimo_recibo FROM sucursales WHERE codigo = 99")['ultimo_recibo'], 'numera con la sucursal');
        $c = $this->fila("SELECT p_fpago, p_importe, tipo, comprobante FROM caja WHERE tipo = 'RC' ORDER BY fe_clave LIMIT 1");
        $this->assertSame(['EF', 100.0, 'RC', 1], [$c['p_fpago'], (float) $c['p_importe'], $c['tipo'], (int) $c['comprobante']]);
        $rc = $this->fila("SELECT neto, descuento, saldo, tico FROM ctact WHERE tipo = 'RC' ORDER BY fe_clave LIMIT 1");
        $this->assertSame([100.0, 0.0, 0.0, 7], [(float) $rc['neto'], (float) $rc['descuento'], (float) $rc['saldo'], (int) $rc['tico']]);
    }

    public function testDescuentoYRetencionesBajanLoQueSeCobra(): void
    {
        $f = $this->factura();
        $r = $this->cc->armarRecibo(['cliente' => '990001', 'fecha' => date('d/m/Y'), 'sucursal' => '99', 'imputar' => [$f['ctact'] => '242'],
            'descuento' => '12', 'ret' => [1 => '20', 3 => '10'], 'pagos' => [['fpago' => 'EF', 'importe' => '200']]]);
        $this->assertSame([], $r['errores']);
        $this->assertSame([242.0, 200.0, 30.0], [$r['recibo']['bruto'], $r['recibo']['neto'], $r['recibo']['total_retenciones']]);
        $this->cc->emitirRecibo($r['recibo'], bin2hex(random_bytes(16)), 0);
        $this->assertSame(0.0, $this->saldo($f['ctact']), 'se cancela la factura completa');
        $rc = $this->fila("SELECT neto, descuento FROM ctact WHERE tipo = 'RC'");
        $this->assertSame([200.0, 42.0], [(float) $rc['neto'], (float) $rc['descuento']], 'descuento de ctact = bruto − neto');
        $co = $this->fila('SELECT bruto, neto, imp_rt1, imp_rt3, dto_bruto, dto_iva FROM cobra');
        $this->assertSame([242.0, 200.0, 20.0, 10.0], [(float) $co['bruto'], (float) $co['neto'], (float) $co['imp_rt1'], (float) $co['imp_rt3']]);
    }

    public function testValidaciones(): void
    {
        $f = $this->factura();
        $base = ['cliente' => '990001', 'fecha' => date('d/m/Y'), 'sucursal' => '99'];
        $e = fn (array $x) => $this->cc->armarRecibo($x + $base)['errores'];
        $this->assertTrue(isset($e(['imputar' => [$f['ctact'] => '300'], 'pagos' => [['fpago' => 'EF', 'importe' => '300']]])['imputar']), 'no se cobra más que el saldo');
        $this->assertTrue(isset($e(['imputar' => [$f['ctact'] => '100'], 'pagos' => [['fpago' => 'EF', 'importe' => '90']]])['pagos']), 'los medios tienen que sumar lo que se cobra');
        $this->assertTrue(isset($e(['imputar' => [], 'pagos' => []])['imputar']), 'hay que cobrar algo');
        $this->assertTrue(isset($e(['imputar' => [$f['ctact'] => '100'], 'pagos' => [['fpago' => 'CH', 'importe' => '100']]])['pagos']), 'un cheque pide número, banco y fecha');
        $this->assertTrue(isset($e(['imputar' => [$f['ctact'] => '100'], 'pagos' => [['fpago' => 'CC', 'importe' => '100']]])['pagos']), 'no se cobra con cuenta corriente');
        $this->assertTrue(isset($e(['imputar' => [$f['ctact'] => '100'], 'descuento' => '150', 'pagos' => [['fpago' => 'EF', 'importe' => '10']]])['descuento']), 'el descuento no supera lo cancelado');
        $this->assertTrue(isset($e(['fecha' => date('d/m/Y', strtotime('+3 days')), 'imputar' => [$f['ctact'] => '100'], 'pagos' => [['fpago' => 'EF', 'importe' => '100']]])['fecha']), 'sin fecha futura');
        $this->assertTrue(isset($e(['cliente' => '990002', 'imputar' => [$f['ctact'] => '100'], 'pagos' => [['fpago' => 'EF', 'importe' => '100']]])['imputar']), 'la deuda es de otro cliente');
    }

    public function testElMismoEnvioNoGrabaDosVecesYElSaldoSeVuelveAMirar(): void
    {
        $f = $this->factura();
        $r = $this->cc->armarRecibo(['cliente' => '990001', 'fecha' => date('d/m/Y'), 'sucursal' => '99', 'imputar' => [$f['ctact'] => '242'], 'pagos' => [['fpago' => 'EF', 'importe' => '242']]]);
        $op = bin2hex(random_bytes(16));
        $a = $this->cc->emitirRecibo($r['recibo'], $op, 0);
        $b = $this->cc->emitirRecibo($r['recibo'], $op, 0);
        $this->assertSame([false, true, $a['id']], [$a['repetido'], $b['repetido'], $b['id']]);
        $this->assertSame(1, (int) $this->fila('SELECT COUNT(*) n FROM cobra')['n']);
        // otro usuario armó lo mismo antes de que se grabara el primero: el segundo recibo falla y no deja nada a medias
        try {
            $this->cc->emitirRecibo($r['recibo'], bin2hex(random_bytes(16)), 0);
            $this->fail('tenía que rechazar el cobro de una factura ya cobrada');
        } catch (CuentaCorrienteError $e) {
            $this->assertTrue(true);
        }
        $this->assertSame(1, (int) $this->fila('SELECT COUNT(*) n FROM cobra')['n']);
        $this->assertSame(1, (int) $this->fila('SELECT COUNT(*) n FROM imputaciones')['n']);
        $this->assertSame(1, (int) $this->fila("SELECT ultimo_recibo FROM sucursales WHERE codigo = 99")['ultimo_recibo'], 'el número no se gasta');
    }

    public function testAnticipoQuedaACuentaYSeImputaAMano(): void
    {
        $f = $this->factura();
        $this->recibo([], '300', ['anticipo' => '300']);
        $rc = (int) $this->fila("SELECT fe_clave FROM ctact WHERE tipo = 'RC'")['fe_clave'];
        $this->assertSame(300.0, $this->saldo($rc));
        $this->assertSame(-58.0, $this->cc->saldo(990001), '242 de deuda − 300 a favor');
        $m = $this->cc->imputarManual(990001, [$rc => '242'], [$f['ctact'] => '242'], 0);
        $this->assertTrue($m['ok'], (string) ($m['error'] ?? ''));
        $this->assertSame([0.0, 58.0], [$this->saldo($f['ctact']), $this->saldo($rc)]);
        $this->assertSame(-58.0, $this->cc->saldo(990001), 'el saldo del cliente no cambia al imputar');
        $mal = $this->cc->imputarManual(990001, [$rc => '58'], [$f['ctact'] => '10'], 0);
        $this->assertFalse($mal['ok'], 'la factura ya no tiene saldo');
    }

    public function testNotaDeCreditoSeImputaYLosTotalesTienenQueCoincidir(): void
    {
        $f = $this->factura();
        $nc = $this->emitir($this->pedido(['tipo' => 'NC', 'asociado' => (string) $f['id'], 'sucursal' => '', 'forma_pago' => '2',
            'items' => [['articulo' => 'T-ART', 'descripcion' => 'dev', 'cantidad' => '1', 'precio' => '100', 'alicuota' => '21']]]));
        $ncCt = (int) $this->fila('SELECT fe_clave FROM ctact WHERE subdiario_id = ?', [$nc['id']])['fe_clave'];
        $this->assertSame(121.0, $this->saldo($ncCt));
        $this->assertFalse($this->cc->imputarManual(990001, [$ncCt => '121'], [$f['ctact'] => '100'], 0)['ok'], 'créditos y débitos tienen que ser iguales');
        $this->assertFalse($this->cc->imputarManual(990001, [$ncCt => '200'], [$f['ctact'] => '200'], 0)['ok'], 'no se aplica más que el saldo a favor');
        $this->assertTrue($this->cc->imputarManual(990001, [$ncCt => '121'], [$f['ctact'] => '121'], 0)['ok']);
        $this->assertSame([121.0, 0.0], [$this->saldo($f['ctact']), $this->saldo($ncCt)]);
        $this->assertSame(121.0, $this->cc->saldo(990001));
        $this->assertSame(1, count($this->cc->imputacionesDe($f['ctact'])));
    }

    public function testAjustesSumanYRestanDeuda(): void
    {
        $this->assertTrue($this->cc->ajuste('AD', 990001, date('d/m/Y'), '50', 99, 'diferencia de cambio', 0)['ok']);
        $this->assertSame(50.0, $this->cc->saldo(990001));
        $ac = $this->cc->ajuste('AC', 990001, date('d/m/Y'), '80', 99, 'bonificación', 0);
        $this->assertTrue($ac['ok']);
        $this->assertSame(-30.0, $this->cc->saldo(990001));
        $this->assertFalse($this->cc->ajuste('AD', 990001, date('d/m/Y'), '0', 99, 'x', 0)['ok'], 'importe en cero');
        $this->assertFalse($this->cc->ajuste('AD', 990001, date('d/m/Y'), '10', 99, '', 0)['ok'], 'pide el motivo');
        $this->assertSame(2, (int) $this->cc->ajuste('AD', 990001, date('d/m/Y'), '1', 99, 'otro', 0)['numero'], 'numera por tipo y sucursal');
    }

    public function testAnularReciboDevuelveLaDeuda(): void
    {
        $f = $this->factura();
        $r = $this->recibo([$f['ctact'] => '242'], '242');
        $this->assertSame(0.0, $this->cc->saldo(990001));
        $this->assertTrue($this->cc->anularRecibo($r['id'], 0)['ok']);
        $this->assertSame(242.0, $this->saldo($f['ctact']));
        $this->assertSame(242.0, $this->cc->saldo(990001));
        $this->assertSame(0, (int) $this->fila('SELECT COUNT(*) n FROM imputaciones')['n']);
        $this->assertSame(0, (int) $this->fila("SELECT COUNT(*) n FROM caja WHERE tipo = 'RC'")['n']);
        $this->assertSame('A', $this->fila('SELECT estado FROM cobra')['estado']);
        $this->assertFalse($this->cc->anularRecibo($r['id'], 0)['ok'], 'ya está anulado');
        // se puede volver a cobrar
        $this->recibo([$f['ctact'] => '242'], '242');
        $this->assertSame(0.0, $this->cc->saldo(990001));
    }

    public function testNoSeAnulaConAnticipoImputadoNiConChequeDepositado(): void
    {
        $f = $this->factura();
        $r = $this->recibo([], '300', ['anticipo' => '300']);
        $rc = (int) $this->fila("SELECT fe_clave FROM ctact WHERE tipo = 'RC'")['fe_clave'];
        $this->cc->imputarManual(990001, [$rc => '242'], [$f['ctact'] => '242'], 0);
        $this->assertFalse($this->cc->anularRecibo($r['id'], 0)['ok'], 'el anticipo ya se usó');

        $g = $this->factura();
        $vto = date('d/m/Y', strtotime('+30 days'));
        $r2 = $this->cc->armarRecibo(['cliente' => '990001', 'fecha' => date('d/m/Y'), 'sucursal' => '99', 'imputar' => [$g['ctact'] => '242'],
            'pagos' => [['fpago' => 'CH', 'importe' => '242', 'nro' => '12345', 'banco' => '990', 'vto' => $vto]]]);
        $this->assertSame([], $r2['errores']);
        $e = $this->cc->emitirRecibo($r2['recibo'], bin2hex(random_bytes(16)), 0);
        $cheque = (int) $this->fila("SELECT fe_clave FROM caja WHERE p_nro = '12345'")['fe_clave'];
        $this->assertTrue($this->cc->estadoCheque($cheque, 'D', date('d/m/Y'), 'depositado')['ok']);
        $this->assertFalse($this->cc->anularRecibo($e['id'], 0)['ok'], 'el cheque ya salió de cartera');
        $this->assertTrue($this->cc->estadoCheque($cheque, '', date('d/m/Y'), 'vuelve')['ok']);
        $this->assertTrue($this->cc->anularRecibo($e['id'], 0)['ok'], 'de vuelta en cartera se puede anular');
    }

    public function testCarteraDeCheques(): void
    {
        $f = $this->factura();
        $vto = date('d/m/Y', strtotime('+30 days'));
        $r = $this->cc->armarRecibo(['cliente' => '990001', 'fecha' => date('d/m/Y'), 'sucursal' => '99', 'imputar' => [$f['ctact'] => '242'],
            'pagos' => [['fpago' => 'CH', 'importe' => '100', 'nro' => '777', 'banco' => '990', 'vto' => $vto], ['fpago' => 'CH', 'importe' => '42', 'nro' => '778', 'banco' => '990', 'vto' => date('d/m/Y')],
                ['fpago' => 'EF', 'importe' => '100']]]);
        $this->assertSame([], $r['errores']);
        $this->cc->emitirRecibo($r['recibo'], bin2hex(random_bytes(16)), 0);
        $c = $this->cc->cheques([], 1, 50);
        $this->assertSame([2, 142.0], [$c['total'], $c['suma']], 'dos cheques en cartera (el efectivo no)');
        $this->assertSame(1, $this->cc->cheques(['diferidos' => '1'], 1, 50)['total'], 'uno es diferido');
        $this->assertSame(1, $this->cc->cheques(['q' => '778'], 1, 50)['total']);
        $dup = $this->cc->armarRecibo(['cliente' => '990001', 'fecha' => date('d/m/Y'), 'sucursal' => '99', 'imputar' => [$f['ctact'] => '100'],
            'pagos' => [['fpago' => 'CH', 'importe' => '50', 'nro' => '9', 'banco' => '990', 'vto' => $vto], ['fpago' => 'CH', 'importe' => '50', 'nro' => '9', 'banco' => '990', 'vto' => $vto]]]);
        $this->assertTrue(isset($dup['errores']['pagos']), 'el mismo cheque dos veces');
    }

    public function testResumenDeMovimientosYDeudaPorCliente(): void
    {
        $f = $this->factura();
        $this->factura();
        $this->recibo([$f['ctact'] => '100'], '100');
        $m = $this->cc->movimientos(990001, null, null);
        $this->assertSame([484.0, 100.0, 384.0], [$m['debe'], $m['haber'], $m['final']]);
        $this->assertCount(3, $m['filas']);
        $this->assertSame(384.0, $m['filas'][2]['acumulado']);
        $futuro = $this->cc->movimientos(990001, date('Y-m-d', strtotime('+1 day')), null);
        $this->assertSame([384.0, 384.0], [$futuro['inicial'], $futuro['final']], 'el saldo inicial arrastra lo anterior');
        $d = $this->cc->resumenDeuda(['q' => 'T CLIENTE RI'], 1, 50);
        $this->assertSame(1, $d['total']);
        $this->assertSame([384.0, 0.0, 384.0], [(float) $d['filas'][0]['deuda'], (float) $d['filas'][0]['a_favor'], (float) $d['filas'][0]['saldo']]);
        $p = $this->cc->pendientes(990001);
        $this->assertSame([2, 0], [count($p['debitos']), count($p['creditos'])]);
    }
}
