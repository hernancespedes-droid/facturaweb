<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Core\GrillaExcel;

/** Grillas: Excel genérico (todo lo filtrado, con permisos) y orden por columna en el servidor. */
final class GrillasTest extends ComprobantesTestCase
{
    public function testValoresArgentinosANumero(): void
    {
        $this->assertSame(1234.56, GrillaExcel::valor('$ 1.234,56'));
        $this->assertSame(-12.0, GrillaExcel::valor('−12'));
        $this->assertSame(21.0, GrillaExcel::valor('21 %'));
        $this->assertSame('0004-00000012', GrillaExcel::valor('0004-00000012'), 'número de comprobante: texto');
        $this->assertSame('0004', GrillaExcel::valor('0004'), 'código con ceros: texto');
        $this->assertSame('7790895000782', GrillaExcel::valor('7790895000782'), 'código de barras: texto');
        $this->assertSame('17/11/2026', GrillaExcel::valor('17/11/2026'));
        $this->assertSame('30-70765648-0', GrillaExcel::valor('30-70765648-0'));
    }

    public function testTablaDelHtmlAXlsxSinColumnasDeBotonesNiFormularios(): void
    {
        $html = '<table class="table"><thead><tr><th>Cod</th><th>Importe</th><th></th></tr></thead><tbody>'
            . '<tr><td>A1</td><td>$ 1.000,50</td><td><form><button>Borrar</button></form></td></tr></tbody></table>'
            . '<table class="table"><tbody><tr><td><input name="x"></td></tr></tbody></table>';
        $x = GrillaExcel::desdeHtml($html, 0);
        $this->assertNotNull($x);
        $this->assertNull(GrillaExcel::desdeHtml($html, 1), 'la tabla con campos (formulario) no es grilla');
        $z = $this->leerHoja($x);
        $this->assertStringContainsString('Cod', $z);
        $this->assertStringContainsString('<v>1000.5</v>', $z, 'el importe va como número');
        $this->assertStringNotContainsString('Borrar', $z);
    }

    private function leerHoja(string $xlsx): string
    {
        $f = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($f, $xlsx);
        $z = new \ZipArchive();
        $z->open($f);
        $s = (string) $z->getFromName('xl/worksheets/sheet1.xml');
        $z->close();
        unlink($f);
        return $s;
    }

    public function testExcelDeComprobantesTraeTodoLoFiltradoYRespetaPermisos(): void
    {
        Db::pdo()->exec("REPLACE INTO configuracion (clave, valor, tipo, descripcion) VALUES ('grillas.por_pagina', '10', 'entero', 'x')");
        for ($i = 0; $i < 12; $i++) {
            $this->emitir($this->pedido());
        }
        $this->loginComo($this->crearUsuario('t_adm', 'admin'));
        $hoy = date('d/m/Y');
        $pagina = $this->get('/comprobantes', ['desde' => $hoy]);
        $this->assertSame(10, substr_count($pagina->body, 'data-label="Fecha"'), 'la pantalla muestra 10 por página');
        $r = $this->get('/comprobantes', ['desde' => $hoy, 'excel' => 'grilla']);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('spreadsheetml', $r->headers['Content-Type']);
        $this->assertSame(12, substr_count($this->leerHoja($r->body), '<row') - 1, 'el Excel trae los 12, no solo la página');
        Db::pdo()->exec("DELETE FROM configuracion WHERE clave = 'grillas.por_pagina' AND descripcion = 'x'");

        $this->loginComo($this->crearUsuario('t_sin', 'operador'));
        $this->assertSame(403, $this->get('/stock', ['excel' => 'grilla'])->status, 'sin permiso tampoco hay Excel');
    }

    public function testOrdenDelListadoDeComprobantesEnElServidor(): void
    {
        $this->emitir($this->pedido(['items' => [['descripcion' => 'x', 'cantidad' => '1', 'precio' => '500', 'alicuota' => '21']]]));
        $this->emitir($this->pedido(['items' => [['descripcion' => 'x', 'cantidad' => '1', 'precio' => '100', 'alicuota' => '21']]]));
        $this->emitir($this->pedido(['items' => [['descripcion' => 'x', 'cantidad' => '1', 'precio' => '300', 'alicuota' => '21']]]));
        $tot = fn (string $orden) => array_map(static fn ($f) => (float) $f['neto'], $this->svc->listar(['orden' => $orden], 1, 50)['filas']);
        $this->assertSame([121.0, 363.0, 605.0], $tot('total'));
        $this->assertSame([605.0, 363.0, 121.0], $tot('total_desc'));
        $this->assertSame(3, count($tot('cualquiera; DROP TABLE x')), 'una clave desconocida usa el orden por omisión');
        $this->loginComo($this->crearUsuario('t_adm', 'admin'));
        $this->assertStringContainsString('th-orden', $this->get('/comprobantes')->body, 'los títulos son clickeables');
    }
}
