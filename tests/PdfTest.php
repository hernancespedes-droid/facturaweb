<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Pdf;
use App\Core\Qr;

final class PdfTest extends TestCase
{
    public function testEstructuraValidaYTablaDeReferencias(): void
    {
        $pdf = new Pdf();
        $pdf->texto(10, 20, 'Hola (mundo) \\ ñandú Á', 12, true);
        $pdf->linea(10, 30, 100, 30);
        $pdf->rect(10, 40, 50, 20, 0.3, 0.9);
        $pdf->pagina();
        $pdf->texto(10, 20, 'Segunda página');
        $pdf->qr(Qr::matriz('https://www.arca.gob.ar/fe/qr/?p=eyJ2ZXIiOjF9'), 150, 20, 30);
        $bin = $pdf->salida('Prueba (título)');
        $this->assertStringStartsWith('%PDF-1.4', $bin);
        $this->assertStringContainsString("%%EOF\n", $bin);
        // startxref apunta a la tabla y cada entrada apunta al inicio de su objeto
        preg_match('/startxref\n(\d+)\n%%EOF/', $bin, $m);
        $this->assertSame('xref', substr($bin, (int) $m[1], 4));
        preg_match('/xref\n0 (\d+)\n/', $bin, $n);
        $cant = (int) $n[1];
        $offsets = [];
        preg_match_all('/(\d{10}) 00000 n /', $bin, $o);
        $offsets = $o[1];
        $this->assertCount($cant - 1, $offsets);
        foreach ($offsets as $i => $off) {
            $this->assertStringStartsWith(($i + 1) . ' 0 obj', substr($bin, (int) $off, 12), 'objeto ' . ($i + 1));
        }
        $this->assertStringContainsString('/Count 2', $bin);
    }

    public function testElTextoSeLeConTildesYParentesis(): void
    {
        if (trim((string) shell_exec('command -v pdftotext')) === '') {
            return; // sin poppler no se puede leer el PDF (la prueba de estructura ya cubre lo demás)
        }
        $pdf = new Pdf();
        $pdf->texto(10, 20, 'Facturación (año 2026) ñandú Ñ', 11);
        $f = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($f, $pdf->salida('x'));
        $txt = (string) shell_exec('pdftotext -layout ' . escapeshellarg($f) . ' - 2>/dev/null');
        unlink($f);
        $this->assertStringContainsString('Facturación (año 2026) ñandú Ñ', $txt);
    }

    public function testAnchosYAjusteDeTexto(): void
    {
        $pdf = new Pdf();
        $this->assertTrue($pdf->ancho('WWWW', 10) > $pdf->ancho('iiii', 10));
        $this->assertTrue($pdf->ancho('Total', 10, true) > $pdf->ancho('Total', 10, false));
        $largo = str_repeat('palabra ', 30);
        $lineas = $pdf->partir($largo, 60, 9);
        $this->assertTrue(count($lineas) > 2);
        foreach ($lineas as $l) {
            $this->assertTrue($pdf->ancho($l, 9) <= 60.5, 'cada renglón entra en el ancho');
        }
        $corto = $pdf->ajustar($largo, 30, 9);
        $this->assertStringContainsString('...', $corto);
        $this->assertTrue($pdf->ancho($corto, 9) <= 30.5);
    }
}
