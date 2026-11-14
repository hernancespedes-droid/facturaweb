<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\Listados\LibroIvaDigital;

/** Libro IVA Digital (ventas): posiciones del diseño de ARCA, qué comprobantes entran, alícuotas, consumidor final, ANSI y permisos. */
final class LibroIvaDigitalTest extends ComprobantesTestCase
{
    /** Hace fiscal un comprobante interno (CAE y número de ARCA) para poder informarlo. */
    private function fiscal(int $id, string $cae = '71000000000001'): void
    {
        Db::pdo()->prepare('UPDATE subdiario SET fe_ccae = ?, fe_nrocbte = comprobante, es_prueba = 0 WHERE fe_clave = ?')->execute([$cae, $id]);
    }

    private function periodo(): string
    {
        return date('Y-m');
    }

    /** @return string[] */
    private function lineas(string $t): array
    {
        return $t === '' ? [] : explode("\r\n", rtrim($t, "\r\n"));
    }

    public function testFacturaAOcupaLasPosicionesDelDiseñoDeArca(): void
    {
        $fc = $this->emitir($this->pedido()); // 2 x 100 + 21 % = 242
        $this->fiscal($fc['id']);
        $l = LibroIvaDigital::generar($this->periodo());
        $this->assertSame([], $l['problemas']);
        $c = $this->lineas($l['cbte']);
        $a = $this->lineas($l['alicuotas']);
        $this->assertSame([1, 1], [count($c), count($a)]);
        $this->assertSame(266, strlen($c[0]), 'el renglón del comprobante mide 266');
        $this->assertSame(62, strlen($a[0]), 'el renglón de alícuotas mide 62');
        $this->assertSame(date('Ymd'), substr($c[0], 0, 8));
        $this->assertSame(['001', '00009', str_pad((string) $fc['numero'], 20, '0', STR_PAD_LEFT)], [substr($c[0], 8, 3), substr($c[0], 11, 5), substr($c[0], 16, 20)]);
        $this->assertSame(substr($c[0], 16, 20), substr($c[0], 36, 20), 'número hasta = número');
        $this->assertSame(['80', '00000000030708805587'], [substr($c[0], 56, 2), substr($c[0], 58, 20)]);
        $this->assertSame('T CLIENTE RI SA' . str_repeat(' ', 15), substr($c[0], 78, 30));
        $this->assertSame('000000000024200', substr($c[0], 108, 15), 'total');
        $this->assertSame(str_repeat('0', 15), substr($c[0], 123, 15), 'no gravado');
        $this->assertSame('PES', substr($c[0], 228, 3));
        $this->assertSame('0001000000', substr($c[0], 231, 10));
        $this->assertSame(['1', ' ', str_repeat('0', 15), '00000000'], [substr($c[0], 241, 1), substr($c[0], 242, 1), substr($c[0], 243, 15), substr($c[0], 258, 8)]);
        $this->assertSame(['001', '00009', substr($c[0], 16, 20), '000000000020000', '0005', '000000000004200'],
            [substr($a[0], 0, 3), substr($a[0], 3, 5), substr($a[0], 8, 20), substr($a[0], 28, 15), substr($a[0], 43, 4), substr($a[0], 47, 15)]);
        $this->assertSame([242.0, 42.0], [$l['totales']['total'], $l['totales']['iva']]);
    }

    public function testSoloEntranLosFiscalesDelMesYElRestoSeCuenta(): void
    {
        $fiscal = $this->emitir($this->pedido());
        $this->fiscal($fiscal['id']);
        $this->emitir($this->pedido());                      // interno, sin CAE
        $prueba = $this->emitir($this->pedido());
        $this->fiscal($prueba['id'], '99000000000001');
        Db::pdo()->prepare('UPDATE subdiario SET es_prueba = 1 WHERE fe_clave = ?')->execute([$prueba['id']]);
        $viejo = $this->emitir($this->pedido());
        $this->fiscal($viejo['id']);
        Db::pdo()->prepare("UPDATE subdiario SET fecha = '2020-01-15' WHERE fe_clave = ?")->execute([$viejo['id']]);
        $l = LibroIvaDigital::generar($this->periodo());
        $this->assertSame(1, $l['cantidad']);
        $this->assertSame(['internos' => 1, 'pruebas' => 1, 'anulados' => 0], $l['omitidos']);
        $this->assertSame(1, LibroIvaDigital::generar('2020-01')['cantidad'], 'el de otro mes entra en su mes');
        $this->assertNull(LibroIvaDigital::generar('2020-13'));
        $this->assertNull(LibroIvaDigital::generar('basura'));
    }

    public function testNotaDeCreditoVaPositivaConSuTipoYResta(): void
    {
        $fc = $this->emitir($this->pedido());
        $this->fiscal($fc['id']);
        $nc = $this->emitir($this->pedido(['tipo' => 'NC', 'asociado' => (string) $fc['id'], 'sucursal' => '',
            'items' => [['articulo' => 'T-ART', 'descripcion' => 'dev', 'cantidad' => '1', 'precio' => '100', 'alicuota' => '21']]]));
        $this->fiscal($nc['id'], '71000000000002');
        $l = LibroIvaDigital::generar($this->periodo());
        $c = $this->lineas($l['cbte']);
        $this->assertCount(2, $c);
        $nota = array_values(array_filter($c, static fn ($x) => substr($x, 8, 3) === '003'));
        $this->assertCount(1, $nota, 'nota de crédito A = tipo 003');
        $this->assertSame('000000000012100', substr($nota[0], 108, 15), 'importe positivo');
        $this->assertSame([121.0, 21.0], [$l['totales']['total'], $l['totales']['iva']], '242 − 121');
    }

    public function testConsumidorFinalSinCuitYVariasAlicuotas(): void
    {
        $b = $this->emitir($this->pedido(['cliente' => '990002', 'forma_pago' => '1', 'fpago' => 'EF',
            'items' => [['articulo' => '', 'descripcion' => 'uno', 'cantidad' => '1', 'precio' => '121', 'alicuota' => '21'], ['articulo' => '', 'descripcion' => 'dos', 'cantidad' => '1', 'precio' => '110', 'alicuota' => '10']]]));
        $this->fiscal($b['id']);
        $l = LibroIvaDigital::generar($this->periodo());
        $this->assertSame([], $l['problemas']);
        $c = $this->lineas($l['cbte']);
        $a = $this->lineas($l['alicuotas']);
        $this->assertSame(['006', '99', str_repeat('0', 20), 'CONSUMIDOR FINAL' . str_repeat(' ', 14)], [substr($c[0], 8, 3), substr($c[0], 56, 2), substr($c[0], 58, 20), substr($c[0], 78, 30)]);
        $this->assertSame('2', substr($c[0], 241, 1), 'cantidad de alícuotas');
        $this->assertCount(2, $a);
        $this->assertSame(['0004', '0005'], [substr($a[0], 43, 4), substr($a[1], 43, 4)], 'una fila por alícuota');
        $this->assertSame(substr($c[0], 8, 33), substr($a[1], 0, 33), 'mismo comprobante en los dos archivos');
    }

    public function testSinIvaSeInformaConAlicuotaCeroYCodigoDeOperacion(): void
    {
        $fc = $this->emitir($this->pedido(['items' => [['articulo' => '', 'descripcion' => 'exento', 'cantidad' => '1', 'precio' => '500', 'alicuota' => '0']]]));
        $this->fiscal($fc['id']);
        $l = LibroIvaDigital::generar($this->periodo());
        $c = $this->lineas($l['cbte']);
        $a = $this->lineas($l['alicuotas']);
        $this->assertSame([], $l['problemas']);
        $this->assertSame('1', substr($c[0], 241, 1));
        $this->assertTrue(in_array(substr($c[0], 242, 1), ['E', 'N'], true), 'lleva código de operación E o N');
        $this->assertSame(['0003', str_repeat('0', 15), str_repeat('0', 15)], [substr($a[0], 43, 4), substr($a[0], 28, 15), substr($a[0], 47, 15)]);
        $this->assertSame('000000000050000', substr($c[0], 108, 15));
        // lo que no es gravado ni IVA ni exento cierra el total: exento + no gravado = total
        $this->assertSame(500.0, (float) (((int) substr($c[0], 123, 15) + (int) substr($c[0], 153, 15)) / 100));
    }

    public function testAnsiMayusculasYLargosDeTexto(): void
    {
        $this->assertSame("\xD1AND\xDA   ", LibroIvaDigital::texto('ñandú', 8), 'Ñ y Ú salen en mayúscula y en ISO 8859-1');
        $this->assertSame(8, strlen(LibroIvaDigital::texto('ñandú', 8)), 'un byte por letra');
        $this->assertSame(5, strlen(LibroIvaDigital::texto('abcdefghij', 5)));
        $this->assertSame('000000000012345', LibroIvaDigital::importe(123.45));
        $this->assertSame('000000000012346', LibroIvaDigital::importe(123.455), 'redondea al centavo');
        $this->assertSame('0000000000000', LibroIvaDigital::importe(0, 13));
    }

    public function testPantallaDescargasYPermisos(): void
    {
        $fc = $this->emitir($this->pedido());
        $this->fiscal($fc['id']);
        $this->loginComo($this->crearUsuario('t_adm', 'admin'));
        $p = $this->get('/listados/libro-iva-digital', ['periodo' => $this->periodo()]);
        $this->assertSame(200, $p->status);
        $this->assertStringContainsString('T CLIENTE RI SA', $p->body);
        $this->assertStringContainsString('Portal IVA', $p->body);
        $d = $this->get('/listados/libro-iva-digital/descargar', ['periodo' => $this->periodo(), 'archivo' => 'cbte']);
        $this->assertSame(200, $d->status);
        $this->assertStringContainsString('attachment', $d->headers['Content-Disposition'] ?? '');
        $this->assertStringContainsString('LIBRO_IVA_DIGITAL_VENTAS_CBTE', $d->headers['Content-Disposition'] ?? '');
        $this->assertSame(266 + 2, strlen($d->body), 'un renglón con CRLF');
        $this->assertSame(62 + 2, strlen($this->get('/listados/libro-iva-digital/descargar', ['periodo' => $this->periodo(), 'archivo' => 'alicuotas'])->body));
        $z = $this->get('/listados/libro-iva-digital/descargar', ['periodo' => $this->periodo(), 'archivo' => 'zip']);
        $this->assertSame(200, $z->status);
        $this->assertStringStartsWith('PK', $z->body);
        $this->assertSame(404, $this->get('/listados/libro-iva-digital/descargar', ['periodo' => $this->periodo(), 'archivo' => 'otro'])->status);
        $this->assertSame(404, $this->get('/listados/libro-iva-digital/descargar', ['periodo' => '2026-99', 'archivo' => 'cbte'])->status);
        $this->assertStringContainsString('no es válido', $this->get('/listados/libro-iva-digital', ['periodo' => 'x'])->body);

        // El Operador no tiene listados.ver de fábrica
        $this->loginComo($this->crearUsuario('t_sin', 'operador'));
        $this->assertSame(403, $this->get('/listados/libro-iva-digital')->status);
        $this->assertSame(403, $this->get('/listados/libro-iva-digital/descargar', ['periodo' => $this->periodo(), 'archivo' => 'cbte'])->status);
    }
}
