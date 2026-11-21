<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;

/** La forma de pago se pide al terminar la venta (revisión): contado o cuenta corriente, vuelto en efectivo y billeteras virtuales. */
final class PagoAlFinalTest extends ComprobantesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Db::pdo()->exec("INSERT IGNORE INTO forma_de_pagos (codigo, descripcion, tipo) VALUES ('TJ', 'TARJETA', 3), ('BV', 'BILLETERA VIRTUAL', 10)");
        Db::pdo()->exec("UPDATE forma_de_pagos SET acepta_vuelto = IF(codigo = 'EF', 1, 0) WHERE codigo IN ('EF', 'TJ', 'BV')");
    }

    /** Factura de 242 sin elegir todavía cómo se paga (como sale del formulario). */
    private function sinPago(array $extra = []): array
    {
        return $this->pedido($extra) + [];
    }

    private function conPago(array $pago, array $extra = []): array
    {
        $p = $this->pedido($extra);
        unset($p['forma_pago']);
        return $pago + $p;
    }

    public function testSinFormaDePagoSeValidaTodoPeroNoSeEmite(): void
    {
        $p = $this->pedido();
        unset($p['forma_pago']);
        $r = $this->svc->armar($p);
        $this->assertSame([], $r['errores']);
        $this->assertTrue($r['cbte']['pago_pendiente']);
        $this->assertNull($r['cbte']['forma_pago']);
        $this->assertSame(242.0, $r['cbte']['calculo']['total']);
        try {
            $this->svc->emitir($r['cbte'], bin2hex(random_bytes(16)), 0);
            $this->assertTrue(false, 'no debía emitir sin forma de pago');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(0, (int) Db::pdo()->query('SELECT COUNT(*) FROM subdiario')->fetchColumn());
        }
        // el presupuesto también la pide al final; la nota de crédito la sigue pidiendo en el formulario
        $pr = $this->pedido(['tipo' => 'PR', 'items' => [['descripcion' => 'x', 'cantidad' => '1', 'precio' => '121', 'alicuota' => '21']]]);
        unset($pr['forma_pago']);
        $this->assertTrue($this->svc->armar($pr)['cbte']['pago_pendiente']);
        $fc = $this->emitir($this->pedido());
        $nc = $this->pedido(['tipo' => 'NC', 'asociado' => (string) $fc['id'], 'items' => [['descripcion' => 'x', 'cantidad' => '1', 'precio' => '10', 'alicuota' => '21']]]);
        unset($nc['forma_pago']);
        $this->assertTrue(isset($this->svc->armar($nc)['errores']['forma_pago']), 'la NC exige elegir');
    }

    public function testEfectivoConVueltoGuardaRecibidoYVuelto(): void
    {
        $r = $this->svc->armar($this->conPago(['forma_pago' => '1', 'fpago' => 'EF', 'recibido' => '300']));
        $this->assertSame([], $r['errores']);
        $this->assertSame(58.0, $r['cbte']['pagos'][0]['vuelto']);
        $e = $this->svc->emitir($r['cbte'], bin2hex(random_bytes(16)), 0);
        $this->assertSame(58.0, $e['vuelto']);
        $c = $this->fila('SELECT p_importe, p_recibido, p_vuelto FROM caja WHERE subdiario_id = ?', [$e['id']]);
        $this->assertSame(['242.00', '300.00', '58.00'], [$c['p_importe'], $c['p_recibido'], $c['p_vuelto']], 'en caja queda el total; se anota lo recibido y el vuelto');
        // sin importe recibido: el total justo, sin vuelto
        $e2 = $this->emitir($this->conPago(['forma_pago' => '1', 'fpago' => 'EF']));
        $this->assertSame(0.0, $e2['vuelto']);
        $c2 = $this->fila('SELECT p_recibido, p_vuelto FROM caja WHERE subdiario_id = ?', [$e2['id']]);
        $this->assertSame(['242.00', '0.00'], [$c2['p_recibido'], $c2['p_vuelto']]);
        // cuenta corriente: nada de recibido
        $e3 = $this->emitir($this->conPago(['forma_pago' => '2']));
        $this->assertNull($this->fila('SELECT p_recibido FROM caja WHERE subdiario_id = ?', [$e3['id']])['p_recibido']);
    }

    public function testRecibidoDeMenosFaltaYDeMasEsVueltoInformativo(): void
    {
        $menos = $this->svc->armar($this->conPago(['forma_pago' => '1', 'fpago' => 'EF', 'recibido' => '200']));
        $this->assertStringContainsString('Faltan $ 42,00', $menos['errores']['recibido']);
        $basura = $this->svc->armar($this->conPago(['forma_pago' => '1', 'fpago' => 'EF', 'recibido' => 'abc']));
        $this->assertTrue(isset($basura['errores']['recibido']));
        // D-136: de más con una forma que no acepta vuelto (p. ej. el recargo de la tarjeta) no frena: la diferencia queda como vuelto informativo
        $tj = $this->svc->armar($this->conPago(['forma_pago' => '1', 'fpago' => 'TJ', 'recibido' => '300']));
        $this->assertSame([], $tj['errores']);
        $this->assertSame([242.0, 300.0, 58.0], [$tj['cbte']['pagos'][0]['importe'], $tj['cbte']['pagos'][0]['recibido'], $tj['cbte']['pagos'][0]['vuelto']]);
        $this->assertSame([], $this->svc->armar($this->conPago(['forma_pago' => '1', 'fpago' => 'TJ', 'recibido' => '242']))['errores'], 'justo sí');
    }

    public function testMediosMezcladosElVueltoSaleDelEfectivo(): void
    {
        // venta de 242: efectivo 200 + tarjeta 100 = 300 → vuelto 58, que sale del efectivo (en caja queda 142 + 100)
        $r = $this->svc->armar($this->conPago(['pagos' => [['fpago' => 'TJ', 'importe' => '100'], ['fpago' => 'EF', 'importe' => '200']]]));
        $this->assertSame([], $r['errores']);
        $this->assertSame(58.0, $r['cbte']['vuelto']);
        $l = array_column($r['cbte']['pagos'], null, 'codigo');
        $this->assertSame([100.0, 0.0], [$l['TJ']['importe'], $l['TJ']['vuelto']]);
        $this->assertSame([142.0, 200.0, 58.0], [$l['EF']['importe'], $l['EF']['recibido'], $l['EF']['vuelto']]);
        $this->assertSame(242.0, $r['cbte']['pago_contado'], 'en caja queda el total de la venta');
        // sin efectivo, la diferencia queda en la última forma (informativa: puede ser un recargo)
        $r2 = $this->svc->armar($this->conPago(['pagos' => [['fpago' => 'BV', 'importe' => '100'], ['fpago' => 'TJ', 'importe' => '160']]]));
        $this->assertSame([], $r2['errores']);
        $this->assertSame([18.0, 142.0], [$r2['cbte']['vuelto'], array_column($r2['cbte']['pagos'], null, 'codigo')['TJ']['importe']]);
        // con una parte a cuenta corriente, lo de más achica primero la deuda (no es plata)
        $r3 = $this->svc->armar($this->conPago(['pagos' => [['fpago' => 'EF', 'importe' => '200'], ['fpago' => 'CC', 'importe' => '100']]]));
        $this->assertSame([], $r3['errores']);
        $this->assertSame([200.0, 42.0, 0.0], [$r3['cbte']['pago_contado'], $r3['cbte']['pago_cuenta'], $r3['cbte']['vuelto']]);
        // todo lo que da en efectivo es vuelto: esa forma no va a la caja
        $r4 = $this->svc->armar($this->conPago(['pagos' => [['fpago' => 'TJ', 'importe' => '242'], ['fpago' => 'EF', 'importe' => '50']]]));
        $this->assertSame([], $r4['errores']);
        $this->assertSame([['TJ'], 50.0], [array_column($r4['cbte']['pagos'], 'codigo'), $r4['cbte']['vuelto']]);
    }

    public function testBilleteraVirtualEsUnMedioDeCobro(): void
    {
        $e = $this->emitir($this->conPago(['forma_pago' => '1', 'fpago' => 'BV']));
        $this->assertSame('BV', $this->fila('SELECT p_fpago FROM caja WHERE subdiario_id = ?', [$e['id']])['p_fpago']);
        $this->assertSame('Billetera virtual', \App\Modules\Tablas\Definiciones::TIPOS_PAGO[10]);
    }

    public function testVariasFormasDePagoSumanElTotalYCadaUnaVaAUnaFilaDeCaja(): void
    {
        // 242 = 100 en efectivo (entrega 150: vuelto 50) + 142 con tarjeta
        $r = $this->svc->armar($this->conPago(['pagos' => [
            ['fpago' => 'EF', 'importe' => '100', 'recibido' => '150'], ['fpago' => 'TJ', 'importe' => '142']]]));
        $this->assertSame([], $r['errores']);
        $this->assertSame([1, 242.0, 0.0, 50.0], [$r['cbte']['forma_pago'], $r['cbte']['pago_contado'], $r['cbte']['pago_cuenta'], $r['cbte']['vuelto']]);
        $e = $this->svc->emitir($r['cbte'], bin2hex(random_bytes(16)), 0);
        $this->assertSame(50.0, $e['vuelto']);
        $filas = Db::pdo()->prepare('SELECT p_fpago, p_importe, p_recibido, p_vuelto FROM caja WHERE subdiario_id = ? ORDER BY fe_clave');
        $filas->execute([$e['id']]);
        $caja = $filas->fetchAll();
        $this->assertSame([['EF', '100.00', '150.00', '50.00'], ['TJ', '142.00', '142.00', '0.00']], array_map('array_values', $caja), 'una fila de caja por forma de pago');
        $this->assertSame(0, (int) Db::pdo()->query('SELECT COUNT(*) FROM ctact')->fetchColumn(), 'todo cobrado: nada a cuenta corriente');
        $this->assertSame('Mixto: EFECTIVO $ 100,00 (recibido $ 150,00, vuelto $ 50,00) · TARJETA $ 142,00', forma_pago_texto($this->svc->obtener($e['id'])));
    }

    public function testParteAlContadoYParteACuentaCorriente(): void
    {
        $r = $this->svc->armar($this->conPago(['pagos' => [['fpago' => 'EF', 'importe' => '100'], ['fpago' => 'CC', 'importe' => '142']]]));
        $this->assertSame([], $r['errores']);
        $this->assertSame([2, 100.0, 142.0], [$r['cbte']['forma_pago'], $r['cbte']['pago_contado'], $r['cbte']['pago_cuenta']]);
        $e = $this->svc->emitir($r['cbte'], bin2hex(random_bytes(16)), 0);
        $ct = $this->fila('SELECT neto, saldo, tico FROM ctact WHERE subdiario_id = ?', [$e['id']]);
        $this->assertSame(['142.00', '142.00', 1], [$ct['neto'], $ct['saldo'], (int) $ct['tico']], 'a cuenta corriente va solo la parte que quedó debiendo');
        $this->assertSame(142.0, $this->svc->deudaCliente(990001));
        $this->assertSame(2, (int) $this->fila('SELECT COUNT(*) AS n FROM caja WHERE subdiario_id = ?', [$e['id']])['n']);
        // la caja diaria separa lo cobrado de lo facturado a cuenta corriente
        $res = \App\Modules\Listados\CajaDiaria::resumen(['desde' => date('d/m/Y'), 'hasta' => date('d/m/Y')]);
        $this->assertSame([142.0, 100.0, 100.0], [$res['ventas']['cuenta_corriente'], $res['ventas']['contado'], $res['total_cobrado']]);
        // y Ventas diarias la muestra como mixta, con sus partes
        $vd = \App\Modules\Listados\VentasDiarias::listar(['desde' => date('d/m/Y'), 'hasta' => date('d/m/Y')], 1, 50);
        $this->assertSame([100.0, 142.0], [$vd['totales']['contado'], $vd['totales']['cuenta']]);
        $this->assertSame(1, \App\Modules\Listados\VentasDiarias::listar(['desde' => date('d/m/Y'), 'hasta' => date('d/m/Y'), 'forma' => 'cuenta'], 1, 50)['total']);
        $this->assertSame(0, \App\Modules\Listados\VentasDiarias::listar(['desde' => date('d/m/Y'), 'hasta' => date('d/m/Y'), 'forma' => 'contado'], 1, 50)['total']);
        // el crédito se controla sobre la parte a cuenta corriente
        Db::pdo()->exec('UPDATE clientes SET credito = 200 WHERE codigo = 990001');
        $sobra = $this->svc->armar($this->conPago(['pagos' => [['fpago' => 'EF', 'importe' => '100'], ['fpago' => 'CC', 'importe' => '142']]]));
        $this->assertTrue(isset($sobra['errores']['credito']), '142 + la deuda de 142 pasan el límite de 200');
        $this->assertSame([], $this->svc->armar($this->conPago(['pagos' => [['fpago' => 'EF', 'importe' => '200'], ['fpago' => 'CC', 'importe' => '42']]]))['errores'], 'la parte a cuenta es de 42 (142 + 42 = 184): no pasa el límite');
    }

    public function testErroresDeVariasFormasDePago(): void
    {
        $e = fn (array $pagos, array $extra = []) => $this->svc->armar($this->conPago(['pagos' => $pagos] + $extra))['errores'];
        $this->assertStringContainsString('Faltan $ 42,00', $e([['fpago' => 'EF', 'importe' => '100'], ['fpago' => 'TJ', 'importe' => '100']])['pagos']);
        $this->assertSame([], $e([['fpago' => 'EF', 'importe' => '200'], ['fpago' => 'TJ', 'importe' => '100']]), 'de más no es error (D-136)');
        $this->assertStringContainsString('Cargá el importe de cada forma de pago', $e([['fpago' => 'EF', 'importe' => ''], ['fpago' => 'TJ', 'importe' => '100']])['pagos']);
        $this->assertStringContainsString('número del cheque', $e([['fpago' => 'CH', 'importe' => '142'], ['fpago' => 'EF', 'importe' => '100']])['pagos']);
        $this->assertStringContainsString('Elegí cómo se paga', $e([['fpago' => 'ZZ', 'importe' => '242'], ['fpago' => 'EF', 'importe' => '0']])['pagos']);
        $nueve = array_fill(0, 9, ['fpago' => 'EF', 'importe' => '10']);
        $this->assertStringContainsString('Hasta 8', $e($nueve)['pagos']);
        // con forma_pago = 2 se ignora lo demás: todo a cuenta corriente
        $todo = $this->svc->armar($this->conPago(['forma_pago' => '2', 'pagos' => [['fpago' => 'EF', 'importe' => '10']]]));
        $this->assertSame([], $todo['errores']);
        $this->assertSame([2, 0.0, 242.0], [$todo['cbte']['forma_pago'], $todo['cbte']['pago_contado'], $todo['cbte']['pago_cuenta']]);
        // la forma de pago única de antes (fpago) sigue valiendo y no acepta la cuenta corriente como forma
        $this->assertTrue(isset($this->svc->armar($this->conPago(['forma_pago' => '1', 'fpago' => 'CC']))['errores']['fpago']));
    }

    public function testRevisionYEmisionPorHttpConVariasFormasDePago(): void
    {
        $this->loginComo($this->crearUsuario('t_op_vp', 'operador'));
        $p = $this->pedido();
        unset($p['forma_pago']);
        $rev = $this->post('/comprobantes/revisar', $p);
        $this->assertStringContainsString('Agregar otro medio', $rev->body);
        $this->assertStringContainsString('name="pagos[0][fpago]"', $rev->body);
        preg_match('/name="datos" value="([^"]*)"/', $rev->body, $m);
        $datos = html_entity_decode($m[1], ENT_QUOTES);
        $op = bin2hex(random_bytes(16));
        // no suman: vuelve a la revisión con lo elegido
        $mal = $this->post('/comprobantes', ['datos' => $datos, 'operacion' => $op, 'forma_pago' => '1', 'pagos' => [['fpago' => 'EF', 'importe' => '100'], ['fpago' => 'TJ', 'importe' => '50']]]);
        $this->assertSame(422, $mal->status);
        $this->assertStringContainsString('Faltan', $mal->body);
        $this->assertStringContainsString('value="100"', $mal->body, 'conserva lo ya cargado');
        $this->assertSame(0, (int) Db::pdo()->query('SELECT COUNT(*) FROM subdiario')->fetchColumn());
        $ok = $this->post('/comprobantes', ['datos' => $datos, 'operacion' => $op, 'forma_pago' => '1', 'pagos' => [['fpago' => 'EF', 'importe' => '100', 'recibido' => '150'], ['fpago' => 'TJ', 'importe' => '142']]]);
        $this->assertSame(302, $ok->status);
        $id = (int) Db::pdo()->query('SELECT fe_clave FROM subdiario')->fetchColumn();
        $ver = $this->get('/comprobantes/' . $id);
        $this->assertStringContainsString('Mixto: EFECTIVO', $ver->body);
        $this->assertStringContainsString('DAR VUELTO AL CLIENTE: $ 50,00', $ver->body);
    }

    public function testRevisionPideElPagoYAvisaDelVuelto(): void
    {
        $this->loginComo($this->crearUsuario('t_op', 'operador'));
        // el formulario de factura y de presupuesto ya no pide la forma de pago; el de nota de crédito sí
        $this->assertStringNotContainsString('name="forma_pago"', $this->get('/comprobantes/nuevo', ['tipo' => 'FC'])->body);
        $this->assertStringNotContainsString('name="forma_pago"', $this->get('/comprobantes/nuevo', ['tipo' => 'PR'])->body);
        $this->assertStringContainsString('name="forma_pago"', $this->get('/comprobantes/nuevo', ['tipo' => 'NC'])->body);

        $p = $this->pedido();
        unset($p['forma_pago']);
        $rev = $this->post('/comprobantes/revisar', $p);
        $this->assertSame(200, $rev->status);
        $this->assertStringContainsString('Cómo se paga', $rev->body);
        $this->assertStringContainsString('Total a cobrar', $rev->body);
        $this->assertStringContainsString('BILLETERA VIRTUAL', $rev->body);
        preg_match('/name="datos" value="([^"]*)"/', $rev->body, $m);
        $datos = html_entity_decode($m[1], ENT_QUOTES);
        $op = bin2hex(random_bytes(16));
        // se entrega menos: vuelve a la revisión con el motivo y lo ya elegido
        $mal = $this->post('/comprobantes', ['datos' => $datos, 'operacion' => $op, 'forma_pago' => '1', 'fpago' => 'EF', 'recibido' => '100']);
        $this->assertSame(422, $mal->status);
        $this->assertStringContainsString('Faltan', $mal->body);
        $this->assertStringContainsString('Cómo se paga', $mal->body);
        $this->assertSame(0, (int) Db::pdo()->query('SELECT COUNT(*) FROM subdiario')->fetchColumn());
        // se entrega de más en efectivo: se emite y avisa el vuelto
        $ok = $this->post('/comprobantes', ['datos' => $datos, 'operacion' => $op, 'forma_pago' => '1', 'fpago' => 'EF', 'recibido' => '300']);
        $this->assertSame(302, $ok->status);
        $id = (int) Db::pdo()->query('SELECT fe_clave FROM subdiario')->fetchColumn();
        $ver = $this->get('/comprobantes/' . $id);
        $this->assertStringContainsString('DAR VUELTO AL CLIENTE: $ 58,00', $ver->body);
        $this->assertStringContainsString('vuelto $ 58,00', $ver->body);
    }
}
