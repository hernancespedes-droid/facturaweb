<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\CuentaCorriente\CuentaCorrienteService;

/** Vuelto en los recibos (D-134): en efectivo se anota lo que entrega el cliente; de menos no vale y en una forma sin vuelto no se cobra de más. */
final class VueltoReciboTest extends ComprobantesTestCase
{
    private CuentaCorrienteService $cc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cc = new CuentaCorrienteService();
        Db::pdo()->exec("UPDATE forma_de_pagos SET acepta_vuelto = IF(codigo = 'EF', 1, 0) WHERE codigo IN ('EF', 'TJ')");
    }

    /** @return array<string,mixed> */
    private function armar(string $fpago, string $recibido): array
    {
        $fc = $this->emitir($this->pedido(['cliente' => '990001']));
        $ct = (int) $this->fila('SELECT fe_clave FROM ctact WHERE subdiario_id = ?', [$fc['id']])['fe_clave'];
        return $this->cc->armarRecibo(['cliente' => '990001', 'fecha' => date('d/m/Y'), 'sucursal' => '99', 'imputar' => [$ct => '242'],
            'pagos' => [['fpago' => $fpago, 'importe' => '242', 'recibido' => $recibido]]]);
    }

    public function testEnEfectivoGrabaLoRecibidoYElVuelto(): void
    {
        $r = $this->armar('EF', '1.000');
        $this->assertSame([], $r['errores']);
        $this->assertSame([1000.0, 758.0], [$r['recibo']['pagos'][0]['recibido'], $r['recibo']['pagos'][0]['vuelto']]);
        $this->cc->emitirRecibo($r['recibo'], bin2hex(random_bytes(16)), 0);
        $c = $this->fila("SELECT p_importe, p_recibido, p_vuelto FROM caja WHERE tipo = 'RC' ORDER BY fe_clave DESC LIMIT 1");
        $this->assertSame([242.0, 1000.0, 758.0], [(float) $c['p_importe'], (float) $c['p_recibido'], (float) $c['p_vuelto']], 'en caja queda lo cobrado; el vuelto aparte');
    }

    public function testSinRecibidoEsElImporteJusto(): void
    {
        $r = $this->armar('EF', '');
        $this->assertSame([], $r['errores']);
        $this->assertSame([null, 0.0], [$r['recibido'] ?? $r['recibo']['pagos'][0]['recibido'], $r['recibo']['pagos'][0]['vuelto']]);
    }

    public function testDeMenosOSinVueltoNoSeGraba(): void
    {
        $this->assertStringContainsString('no alcanza', $this->armar('EF', '200')['errores']['pagos'] ?? '');
        $this->assertStringContainsString('no acepta vuelto', $this->armar('TJ', '300')['errores']['pagos'] ?? '');
    }
}
