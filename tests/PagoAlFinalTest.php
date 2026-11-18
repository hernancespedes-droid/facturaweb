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
        $this->assertSame(58.0, $r['cbte']['pago']['vuelto']);
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

    public function testRecibidoMenorOFormaQueNoAceptaVuelto(): void
    {
        $menos = $this->svc->armar($this->conPago(['forma_pago' => '1', 'fpago' => 'EF', 'recibido' => '200']));
        $this->assertStringContainsString('entregó menos', $menos['errores']['recibido']);
        $basura = $this->svc->armar($this->conPago(['forma_pago' => '1', 'fpago' => 'EF', 'recibido' => 'abc']));
        $this->assertTrue(isset($basura['errores']['recibido']));
        $tj = $this->svc->armar($this->conPago(['forma_pago' => '1', 'fpago' => 'TJ', 'recibido' => '300']));
        $this->assertStringContainsString('no acepta vuelto', $tj['errores']['recibido']);
        $this->assertSame([], $this->svc->armar($this->conPago(['forma_pago' => '1', 'fpago' => 'TJ', 'recibido' => '242']))['errores'], 'justo sí');
        // el ABM: si se tilda «acepta vuelto» en la forma de pago, se da vuelto
        Db::pdo()->exec("UPDATE forma_de_pagos SET acepta_vuelto = 1 WHERE codigo = 'TJ'");
        $this->assertSame(58.0, $this->svc->armar($this->conPago(['forma_pago' => '1', 'fpago' => 'TJ', 'recibido' => '300']))['cbte']['pago']['vuelto']);
        Db::pdo()->exec("UPDATE forma_de_pagos SET acepta_vuelto = 0 WHERE codigo = 'EF'");
        $this->assertStringContainsString('no acepta vuelto', $this->svc->armar($this->conPago(['forma_pago' => '1', 'fpago' => 'EF', 'recibido' => '300']))['errores']['recibido']);
    }

    public function testBilleteraVirtualEsUnMedioDeCobro(): void
    {
        $e = $this->emitir($this->conPago(['forma_pago' => '1', 'fpago' => 'BV']));
        $this->assertSame('BV', $this->fila('SELECT p_fpago FROM caja WHERE subdiario_id = ?', [$e['id']])['p_fpago']);
        $this->assertSame('Billetera virtual', \App\Modules\Tablas\Definiciones::TIPOS_PAGO[10]);
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
        $this->assertStringContainsString('entregó menos', $mal->body);
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
