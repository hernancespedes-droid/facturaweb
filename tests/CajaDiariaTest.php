<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\CuentaCorriente\CuentaCorrienteService;
use App\Modules\Listados\CajaDiaria;

/** Caja diaria: lo vendido, lo facturado a cuenta corriente y lo cobrado por forma de pago (efectivo con vuelto, tarjeta, billetera, recibos). */
final class CajaDiariaTest extends ComprobantesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Db::pdo()->exec("INSERT IGNORE INTO forma_de_pagos (codigo, descripcion, tipo) VALUES ('TJ', 'TARJETA', 3), ('BV', 'BILLETERA VIRTUAL', 10)");
        Db::pdo()->exec("UPDATE forma_de_pagos SET acepta_vuelto = IF(codigo = 'EF', 1, 0) WHERE codigo IN ('EF', 'TJ', 'BV')");
    }

    private function venta(array $pago, array $extra = []): array
    {
        $p = $this->pedido($extra); // 242
        unset($p['forma_pago']);
        return $this->emitir($pago + $p);
    }

    private function hoy(): array
    {
        return ['desde' => date('d/m/Y'), 'hasta' => date('d/m/Y')];
    }

    /** @return int ctact de la factura a cuenta corriente */
    private function cargarDia(): int
    {
        $this->venta(['forma_pago' => '1', 'fpago' => 'EF', 'recibido' => '300']);       // factura, efectivo 242, vuelto 58
        $this->venta(['forma_pago' => '1', 'fpago' => 'TJ']);                            // factura, tarjeta 242
        $this->venta(['forma_pago' => '1', 'fpago' => 'BV'], ['tipo' => 'PR', 'items' => [['descripcion' => 'x', 'cantidad' => '2', 'precio' => '121', 'alicuota' => '21']]]); // presupuesto, billetera 242
        $cc = $this->venta(['forma_pago' => '2']);                                       // factura a cuenta corriente 242
        $ct = (int) $this->fila('SELECT fe_clave FROM ctact WHERE subdiario_id = ?', [$cc['id']])['fe_clave'];
        $this->emitir($this->pedido(['tipo' => 'NC', 'asociado' => (string) $cc['id'], 'forma_pago' => '2', 'items' => [['descripcion' => 'dev', 'cantidad' => '1', 'precio' => '50', 'alicuota' => '21']]])); // NC 60,50
        // un recibo del día cobrando parte de la cuenta corriente, en efectivo
        $cobro = new CuentaCorrienteService();
        $r = $cobro->armarRecibo(['cliente' => '990001', 'fecha' => date('d/m/Y'), 'sucursal' => '99', 'imputar' => [$ct => '100'], 'pagos' => [['fpago' => 'EF', 'importe' => '100']]]);
        $this->assertSame([], $r['errores']);
        $cobro->emitirRecibo($r['recibo'], bin2hex(random_bytes(16)), 0);
        return $ct;
    }

    public function testResumenDelDia(): void
    {
        $this->cargarDia();
        $res = CajaDiaria::resumen($this->hoy());
        $v = $res['ventas'];
        $this->assertSame([726.0, 242.0, 60.5, 907.5], [$v['facturas'], $v['presupuestos'], $v['notas_credito'], $v['ventas_netas']], 'lo vendido: 3 facturas, 1 presupuesto, menos la NC');
        $this->assertSame([242.0, 726.0], [$v['cuenta_corriente'], $v['contado']], 'lo facturado en cuenta corriente y lo de contado');
        $this->assertSame([1 => 342.0, 3 => 242.0, 10 => 242.0], $res['por_tipo'], 'efectivo (ventas + recibo), tarjeta y billetera');
        $this->assertSame(826.0, $res['total_cobrado']);
        $this->assertSame(['recibido' => 400.0, 'vuelto' => 58.0, 'neto' => 342.0], array_intersect_key($res['efectivo'], array_flip(['recibido', 'vuelto', 'neto'])), 'efectivo: recibido, vuelto y neto en caja');
        $this->assertSame(342.0, $res['efectivo']['en_caja'], 'sin movimientos manuales, el efectivo en caja es el neto');
        $ef = array_values(array_filter($res['cobrado'], static fn (array $c) => $c['codigo'] === 'EF'))[0];
        $this->assertSame([242.0, 100.0], [(float) $ef['ventas'], (float) $ef['recibos']], 'se separan ventas de contado y recibos');
        // otro día: nada
        $this->assertSame(0.0, CajaDiaria::resumen(['desde' => '01/01/2000', 'hasta' => '01/01/2000'])['total_cobrado']);
        // la cuenta corriente no es dinero: no está entre los movimientos
        $mov = CajaDiaria::movimientos($this->hoy(), 1, 50);
        $this->assertSame(4, $mov['total']);
        $this->assertSame(1, count(array_filter($mov['filas'], static fn (array $x) => $x['cobra_id'] !== null)), 'un movimiento es el recibo');
    }

    public function testPantallaYPermisos(): void
    {
        $this->cargarDia();
        $this->loginComo($this->crearUsuario('t_op_cd', 'operador'));
        $this->assertSame(403, $this->get('/listados/caja-diaria')->status, 'el operador no ve la caja');
        $this->loginComo($this->crearUsuario('t_adm_cd', 'admin'));
        $r = $this->get('/listados/caja-diaria');
        $this->assertSame(200, $r->status);
        foreach (['Caja diaria', 'Billeteras virtuales', 'Facturado en cuenta corriente', '$ 826,00', '$ 907,50', 'se dieron <b>$ 58,00</b>', 'Recibo X 0009-00000001'] as $txt) {
            $this->assertStringContainsString($txt, $r->body, $txt);
        }
        $this->assertSame(200, $this->get('/listados/caja-diaria', ['excel' => 'grilla'])->status);
    }

    public function testReporteParaImprimir(): void
    {
        $this->cargarDia();
        $admin = $this->crearUsuario('t_adm_rc', 'admin');
        Db::pdo()->prepare("INSERT INTO caja_turnos (usuario_id, abierto_en, fondo_inicial) VALUES (?, NOW(), 1000)")->execute([$admin]);
        Db::pdo()->prepare("INSERT INTO caja_movimientos (creado_en, usuario_id, tipo, importe, motivo) VALUES (NOW(), ?, 'E', 150, 'Pago al flete'), (NOW(), ?, 'I', 50, 'Cambio para el cajón')")->execute([$admin, $admin]);
        $d = \App\Modules\Listados\ReporteCaja::datos($this->hoy());
        $this->assertSame(1242.0, $d['r']['efectivo']['en_caja'], 'fondo 1000 + efectivo 342 + ingreso 50 - egreso 150');
        $this->assertSame([876.0, 150.0], [$d['entro'], $d['salio']], 'entró: lo cobrado + ingresos (sin la cuenta corriente); salió: los egresos');
        $this->assertSame(1, count($d['fiado']), 'la venta a cuenta corriente');
        $this->assertSame(242.0, (float) $d['fiado'][0]['p_importe']);
        $this->assertSame(['EFECTIVO', 'TARJETA', 'BILLETERA VIRTUAL'], array_map('strtoupper', array_keys($d['cobros'])), 'el detalle agrupa por forma de pago');
        $this->assertSame('Recibo X 0009-00000001', array_values(array_filter($d['cobros'][array_key_first($d['cobros'])], static fn ($x) => $x['cobra_id'] !== null))[0]['comprobante']);
        $destinos = array_column($d['medios'], 'destino');
        $this->assertContains(\App\Modules\Listados\ReporteCaja::DESTINO[3], $destinos, 'la tarjeta dice a dónde va');

        $this->loginComo($this->crearUsuario('t_op_rc', 'operador'));
        $this->assertSame(403, $this->get('/listados/caja-diaria/imprimir')->status, 'el operador no ve el reporte');
        $this->loginComo($admin);
        $r = $this->get('/listados/caja-diaria/imprimir', $this->hoy());
        $this->assertSame(200, $r->status);
        foreach (['Reporte de caja del ' . date('d/m/Y'), 'Tiene que haber en el cajón', '$ 1.242,00', 'Pago al flete', 'Cambio para el cajón', 'Lo deposita la tarjeta en el banco',
            'Lo que quedó debiendo cada cliente', 'Recibo X 0009-00000001', 'Entregó (cajero)'] as $txt) {
            $this->assertStringContainsString($txt, $r->body, $txt);
        }
        $this->assertStringNotContainsString('class="topbar', $r->body, 'hoja suelta, sin el menú');
        $this->assertStringContainsString('/listados/caja-diaria/imprimir', $this->get('/listados/caja-diaria')->body, 'el botón está en la caja diaria');
    }
}
