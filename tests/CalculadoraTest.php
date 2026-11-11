<?php
declare(strict_types=1);

namespace Tests;

use App\Modules\Comprobantes\Calculadora;
use App\Modules\Comprobantes\Tipos;

final class CalculadoraTest extends TestCase
{
    private function item(float $cant, float $precio, float $alic = 21.0): array
    {
        return ['cantidad' => $cant, 'precio' => $precio, 'alicuota' => $alic];
    }

    public function testFacturaAConNetoYUnaAlicuota(): void
    {
        // Igual que la última factura A de OYS: neto 7.000, IVA 1.470, total 8.470.
        $c = Calculadora::calcular('A', [$this->item(1, 7000)]);
        $this->assertSame(7000.0, $c['neto_grabado']);
        $this->assertSame(1470.0, $c['iva_total']);
        $this->assertSame(8470.0, $c['total']);
        $this->assertSame(7000.0, $c['bruto']);
        $this->assertSame(0.0, $c['bonificacion']);
        $this->assertSame(['21' => ['alicuota' => 21.0, 'base' => 7000.0, 'importe' => 1470.0]], $c['iva']);
    }

    public function testFacturaBConIvaIncluidoCierraConElTotalDeLosRenglones(): void
    {
        // La B de OYS: total 15.456,00 = neto 12.773,55 + IVA 2.682,45.
        $c = Calculadora::calcular('B', [$this->item(1, 15456)]);
        $this->assertSame(12773.55, $c['neto_grabado']);
        $this->assertSame(2682.45, $c['iva_total']);
        $this->assertSame(15456.0, $c['total']);
        // Varios renglones: el total es exactamente la suma de lo que se ve (3 x 100,00 + 2 x 33,33).
        $c = Calculadora::calcular('B', [$this->item(3, 100), $this->item(2, 33.33, 10.5)]);
        $this->assertSame(366.66, $c['total']);
        $this->assertSame(round($c['neto_grabado'] + $c['iva_total'], 2), $c['total']);
        $this->assertSame(2, count($c['iva']));
    }

    public function testFacturaCNoDiscriminaIva(): void
    {
        $c = Calculadora::calcular('C', [$this->item(2, 500, 21), $this->item(1, 100, 10.5)]);
        $this->assertSame(1100.0, $c['total']);
        $this->assertSame(1100.0, $c['neto_grabado']);
        $this->assertSame(0.0, $c['iva_total']);
        $this->assertSame([], $c['iva']);
        $this->assertSame(0.0, $c['neto_exento']);
    }

    public function testVariasAlicuotasYExento(): void
    {
        $c = Calculadora::calcular('A', [$this->item(1, 1000, 21), $this->item(2, 500, 10.5), $this->item(1, 300, 0)]);
        $this->assertSame(2000.0, $c['neto_grabado']);
        $this->assertSame(300.0, $c['neto_exento']);
        $this->assertSame(210.0 + 105.0, $c['iva_total']);
        $this->assertSame(2000.0 + 300.0 + 315.0, $c['total']);
        $this->assertSame(['10.5', '21'], array_map('strval', array_keys($c['iva'])), 'una fila de IVA por alícuota, sin la del 0 %');
        $this->assertTrue($c['items'][2]['exento']);
        $this->assertFalse($c['items'][0]['exento']);
    }

    public function testDescuentoGeneralSeAplicaACadaRenglon(): void
    {
        $c = Calculadora::calcular('A', [$this->item(1, 1000), $this->item(1, 500, 10.5)], 10.0);
        $this->assertSame(1500.0, $c['bruto']);
        $this->assertSame(150.0, $c['bonificacion']);
        $this->assertSame(1350.0, $c['neto_grabado']);
        $this->assertSame(189.0 + 47.25, $c['iva_total']);
        $this->assertSame(round(1350 + 236.25, 2), $c['total']);
        $this->assertSame(900.0, $c['items'][0]['neto']);
        // B con descuento: bruto - bonificación = neto grabado
        $b = Calculadora::calcular('B', [$this->item(1, 1210)], 50.0);
        $this->assertSame(1000.0, $b['bruto']);
        $this->assertSame(500.0, $b['bonificacion']);
        $this->assertSame(500.0, $b['neto_grabado']);
        $this->assertSame(605.0, $b['total']);
        $this->assertSame(1210.0, $b['subtotal'], 'en la B el subtotal y el descuento se ven con IVA');
        $this->assertSame(605.0, $b['descuento_importe']);
        $a = Calculadora::calcular('A', [$this->item(1, 1000)], 10.0);
        $this->assertSame([1000.0, 100.0], [$a['subtotal'], $a['descuento_importe']]);
        $this->assertSame(0.0, Calculadora::calcular('A', [$this->item(1, 100)], -5.0)['bonificacion'], 'el descuento no es negativo');
    }

    public function testNoHayDiferenciasDeCentavosAlAgruparNiAlRedondear(): void
    {
        // 7 renglones con centavos: bruto - bonificación = neto grabado + exento; total = neto + IVA, siempre a 2 decimales.
        $items = [];
        for ($i = 1; $i <= 7; $i++) {
            $items[] = $this->item($i / 3, 19.99 * $i, $i % 2 === 0 ? 21 : 10.5);
        }
        foreach (['A', 'B'] as $letra) {
            $c = Calculadora::calcular($letra, $items, 7.5);
            $this->assertSame(Calculadora::r2($c['bruto'] - $c['bonificacion']), Calculadora::r2($c['neto_grabado'] + $c['neto_exento']), $letra);
            $this->assertSame(Calculadora::r2($c['neto_grabado'] + $c['neto_exento'] + $c['iva_total']), $c['total'], $letra);
            $ivas = array_sum(array_column($c['iva'], 'importe'));
            $this->assertSame(Calculadora::r2($ivas), $c['iva_total'], $letra);
            foreach ($c['iva'] as $g) {
                $this->assertTrue(abs($g['importe'] - $g['base'] * $g['alicuota'] / 100) <= 0.005 + 1e-9, "IVA {$g['alicuota']} dentro del medio centavo");
            }
        }
    }

    public function testCodigosDeTipoYLetra(): void
    {
        $this->assertSame('A', Tipos::letra('RI', 1));
        $this->assertSame('A', Tipos::letra('RI', 6), 'a un monotributista, A (regla actual)');
        $this->assertSame('B', Tipos::letra('RI', 5));
        $this->assertSame('B', Tipos::letra('RI', 4), 'un exento recibe B');
        $this->assertSame('C', Tipos::letra('MT', 1));
        $this->assertSame('C', Tipos::letra('EX', 5));
        $this->assertSame('RI', Tipos::condicionEmisor(1));
        $this->assertSame(null, Tipos::condicionEmisor(3));
        $this->assertSame(1, Tipos::tipoAfip('FC', 'A'));
        $this->assertSame(8, Tipos::tipoAfip('NC', 'B'));
        $this->assertSame(12, Tipos::tipoAfip('ND', 'C'));
        $this->assertSame('ultima_nc_b', Tipos::columnaNumerador('NC', 'B'));
        $this->assertSame(null, Tipos::columnaNumerador('XX', 'A'));
        // Los códigos coinciden con los del módulo de factura electrónica.
        require_once dirname(__DIR__) . '/lib/afip_fe/afip.php';
        foreach (['FC', 'NC', 'ND'] as $t) {
            foreach (['A', 'B', 'C'] as $l) {
                $this->assertSame(\OysAfip\Comprobante::tipoAfip($t, $l), Tipos::tipoAfip($t, $l), "$t $l");
            }
        }
    }
}
