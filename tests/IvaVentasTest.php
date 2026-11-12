<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\Listados\IvaVentas;

/** Subdiario de IVA ventas: totales por alícuota, notas de crédito que restan, pruebas excluidas, permisos y Excel. */
final class IvaVentasTest extends ComprobantesTestCase
{
    private function f(array $extra = []): array
    {
        return $extra + ['desde' => date('01/m/Y'), 'hasta' => date('t/m/Y')];
    }

    private function emitirDosAlicuotasYNc(): void
    {
        // FC A: 200 al 21 % (42) + 100 al 10,5 % (10,50) + 50 exento = neto gravado 300 + exento 50, total 402,50
        $fc = $this->emitir($this->pedido(['items' => [
            ['descripcion' => 'a', 'cantidad' => '2', 'precio' => '100', 'alicuota' => '21'],
            ['descripcion' => 'b', 'cantidad' => '1', 'precio' => '100', 'alicuota' => '10'],
            ['descripcion' => 'c', 'cantidad' => '1', 'precio' => '50', 'alicuota' => '0'],
        ]]));
        // NC A por 100 + 21 % = 121
        $this->emitir($this->pedido(['tipo' => 'NC', 'asociado' => (string) $fc['id'], 'sucursal' => '',
            'items' => [['descripcion' => 'dev', 'cantidad' => '1', 'precio' => '100', 'alicuota' => '21']]]));
    }

    public function testTotalesPorAlicuotaYNotaDeCreditoResta(): void
    {
        $this->emitirDosAlicuotasYNc();
        $r = IvaVentas::listar($this->f(), 1, 50);
        $this->assertSame(0, $r['total'], 'los de prueba (internos) no se listan por omisión');
        $r = IvaVentas::listar($this->f(['pruebas' => '1']), 1, 50);
        $this->assertSame(2, $r['total']);
        $t = $r['totales'];
        $i21 = array_search(21.0, IvaVentas::ALICUOTAS, true);
        $i105 = array_search(10.5, IvaVentas::ALICUOTAS, true);
        $this->assertSame([42.0 - 21.0, 10.5], [$t["iva_{$i21}"], $t["iva_{$i105}"]], 'IVA 21 % neto de la NC; 10,5 % solo de la factura');
        $this->assertSame([300.0 - 100.0, 50.0], [$t['gravado'], $t['exento']]);
        $this->assertSame(402.5 - 121.0, $t['total']);
        $this->assertSame(31.5, $t['iva_total'], 'IVA total: 42 + 10,50 - 21');
        $this->assertSame(0.0, $t['iva_otras']);
        $nc = array_values(array_filter($r['filas'], static fn ($x) => $x['tipo'] === 'NC'))[0];
        $this->assertSame([-100.0, -121.0], [(float) $nc['gravado'], (float) $nc['total']], 'la NC sale en negativo');
    }

    public function testFiltrosPorFechaPuntoDeVentaYTipo(): void
    {
        $this->emitirDosAlicuotasYNc();
        $this->assertSame(1, IvaVentas::listar($this->f(['pruebas' => '1', 'tipo' => 'NC']), 1, 50)['total']);
        $this->assertSame(0, IvaVentas::listar($this->f(['pruebas' => '1', 'pv' => '77']), 1, 50)['total']);
        $this->assertSame(2, IvaVentas::listar($this->f(['pruebas' => '1', 'pv' => '9']), 1, 50)['total']);
        $this->assertSame(0, IvaVentas::listar(['desde' => '01/01/2000', 'hasta' => '31/01/2000', 'pruebas' => '1'], 1, 50)['total']);
    }

    public function testPantallaExcelYPermisos(): void
    {
        $this->emitirDosAlicuotasYNc();
        $this->loginComo($this->crearUsuario('t_op', 'operador'));
        $this->assertSame(403, $this->get('/listados/iva-ventas')->status, 'el operador no tiene listados.ver');
        $this->loginComo($this->crearUsuario('t_adm', 'admin'));
        $r = $this->get('/listados/iva-ventas', ['pruebas' => '1'] + $this->f());
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Subdiario de IVA ventas', $r->body);
        $this->assertStringContainsString('T CLIENTE RI SA', $r->body);
        $x = $this->get('/listados/iva-ventas', ['excel' => '1', 'pruebas' => '1'] + $this->f());
        $this->assertSame(200, $x->status);
        $this->assertStringStartsWith('PK', $x->body, 'es un .xlsx (zip)');
    }
}
