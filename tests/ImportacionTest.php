<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Csrf;
use App\Core\Db;
use App\Core\XlsxWriter;
use App\Http\Request;
use App\Modules\Importacion\Destinos;
use App\Modules\Importacion\Importador;
use App\Modules\Importacion\Lector;

/**
 * Importadores desde Excel/CSV/TXT (0.38.0): lectura de los formatos, columnas sugeridas, clientes, artículos, stock y comprobantes
 * históricos, prueba sin grabar, todo o nada, re-importar sin duplicar, y permisos.
 * Los clientes de prueba van del 990001 al 990099 (los borra ComprobantesTestCase); los artículos empiezan con T-.
 */
final class ImportacionTest extends ComprobantesTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/fw_imp_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        Db::pdo()->exec('UPDATE parametros SET ultimo_cliente = 990010 WHERE id = 1');
        Db::pdo()->exec('DELETE FROM stock_movimientos');
        Db::pdo()->exec('DELETE FROM stock_saldos');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
        $pdo = Db::pdo();
        $pdo->exec('DELETE FROM stock_movimientos');
        $pdo->exec('DELETE FROM stock_saldos');
        $pdo->exec('DELETE FROM importaciones');
        $pdo->exec('DELETE FROM importacion_modelos');
        $pdo->exec("DELETE FROM clientes WHERE razon_social LIKE 'T %' OR codigo BETWEEN 990001 AND 990099");
        $pdo->exec("DELETE FROM rubros WHERE descripcion LIKE 'T RUBRO%'");
        parent::tearDown();
    }

    /** @param array<int,array<int,string>> $filas primera = títulos */
    private function csv(array $filas, string $sep = ';', string $nombre = 'a.csv'): string
    {
        $f = $this->dir . '/' . $nombre;
        file_put_contents($f, implode("\r\n", array_map(static fn ($r) => implode($sep, $r), $filas)));
        return $f;
    }

    /** @param array<int,array<int,string>> $filas */
    private function importar(string $destino, array $filas, array $op = [], bool $simular = false, ?array $mapa = null): array
    {
        $tabla = Lector::leer($this->csv($filas), 'a.csv');
        $mapa ??= Destinos::adivinar($destino, $tabla['titulos']);
        return (new Importador(Db::pdo(), $destino, $mapa, $op))->ejecutar($tabla, $simular, null, 'a.csv');
    }

    private function uno(string $sql): mixed
    {
        return Db::pdo()->query($sql)->fetchColumn();
    }

    // ------------------------------------------------------------------ lectura

    public function testCsvConPuntoYComaWindows1252ComillasYBom(): void
    {
        $texto = "Código;Razón social;Domicilio\r\n1;\"PEREZ; JUAN\";\"Calle \"\"A\"\" 12\"\r\n\r\n2;MUÑOZ;\"Dos\nrenglones\"\r\n";
        $f = $this->dir . '/w.csv';
        file_put_contents($f, mb_convert_encoding($texto, 'Windows-1252', 'UTF-8'));
        $t = Lector::leer($f, 'w.csv');
        $this->assertSame(['Código', 'Razón social', 'Domicilio'], $t['titulos']);
        $this->assertCount(2, $t['filas']);
        $this->assertSame(['1', 'PEREZ; JUAN', 'Calle "A" 12'], $t['filas'][0]['celdas']);
        $this->assertSame('MUÑOZ', $t['filas'][1]['celdas'][1]);
        $this->assertSame("Dos\nrenglones", $t['filas'][1]['celdas'][2]);
        $this->assertSame(4, $t['filas'][1]['n'], 'el número de fila es el del archivo');
        file_put_contents($f, "\xEF\xBB\xBFa,b\n1,2\n");
        $t = Lector::leer($f, 'x.txt');
        $this->assertSame(['a', 'b'], $t['titulos']);
        $this->assertSame(',', $t['separador']);
        file_put_contents($f, "a\tb\tc\n1\t2,5\t3\n");
        $t = Lector::leer($f, 'x.txt');
        $this->assertSame("\t", $t['separador']);
        $this->assertSame(['1', '2,5', '3'], $t['filas'][0]['celdas']);
    }

    public function testXlsxNumerosConComaYFechas(): void
    {
        $x = new XlsxWriter('Hoja', ['Código', 'Precio', 'Texto']);
        $x->fila(['A1', 1.234, '0001']);
        $x->fila(['A2', 1650.5, 'Ñandú']);
        $x->fila(['A3', 20123456789, '']);
        $f = $this->dir . '/a.xlsx';
        file_put_contents($f, $x->contenido());
        $t = Lector::leer($f, 'a.xlsx');
        $this->assertSame(['Código', 'Precio', 'Texto'], $t['titulos']);
        $this->assertSame(['A1', '1,234', '0001'], $t['filas'][0]['celdas'], '1.234 del Excel es uno coma dos, no mil doscientos');
        $this->assertSame('1650,5', $t['filas'][1]['celdas'][1]);
        $this->assertSame('Ñandú', $t['filas'][1]['celdas'][2]);
        $this->assertSame('20123456789', $t['filas'][2]['celdas'][1], 'sin notación científica');

        // Uno armado a mano como lo guarda Excel: textos compartidos y una celda con formato de fecha
        $z = new \ZipArchive();
        $f2 = $this->dir . '/b.xlsx';
        $z->open($f2, \ZipArchive::CREATE);
        $z->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Ventas" sheetId="1" r:id="rId7"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId7" Type="worksheet" Target="worksheets/hoja9.xml"/></Relationships>');
        $z->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Fecha</t></si><si><r><t>Tot</t></r><r><t>al</t></r></si></sst>');
        $z->addFromString('xl/styles.xml', '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts><numFmt numFmtId="164" formatCode="dd/mm/yyyy;@"/></numFmts><cellXfs><xf numFmtId="0"/><xf numFmtId="164"/></cellXfs></styleSheet>');
        $z->addFromString('xl/worksheets/hoja9.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="C1" t="s"><v>1</v></c></row>'
            . '<row r="3"><c r="A3" s="1"><v>45366</v></c><c r="C3"><v>1210.5</v></c></row></sheetData></worksheet>');
        $z->close();
        $t = Lector::leer($f2, 'b.xlsx');
        $this->assertSame(['Fecha', 'Columna B', 'Total'], $t['titulos']);
        $this->assertSame(['15/03/2024', '', '1210,5'], $t['filas'][0]['celdas']);
        $this->assertSame(3, $t['filas'][0]['n']);
    }

    public function testXlsViejoYArchivoVacioSeExplican(): void
    {
        $f = $this->dir . '/v.csv';
        file_put_contents($f, "\r\n \r\n");
        foreach ([[$f, 'viejo.xls', '.xls'], [$f, 'v.csv', 'vacío'], [$f, 'v.pdf', '.xlsx, .csv o .txt']] as [$a, $n, $esperado]) {
            try {
                Lector::leer($a, $n);
                $this->fail("{$n} tendría que fallar");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($esperado, $e->getMessage());
            }
        }
    }

    public function testColumnasSugeridasPorElTituloYLaPlanillaModeloSeReconoce(): void
    {
        $m = Destinos::adivinar('clientes', ['Nro. Cliente', 'Razón Social', 'C.U.I.T.', 'Condición IVA', 'E-mail', 'Cualquier cosa']);
        $this->assertSame(['codigo' => 0, 'razon_social' => 1, 'iva' => 3, 'cuit' => 2, 'emailfc' => 4], $m);
        foreach (array_keys(Destinos::TITULOS) as $d) {
            $campos = Destinos::campos($d);
            $m = Destinos::adivinar($d, array_map(static fn ($c) => (string) $c['label'], array_values($campos)));
            $this->assertSame(array_keys($campos), array_keys($m), "la planilla modelo de {$d} se reconoce sola");
        }
        // Planilla «Mis comprobantes» de ARCA
        $m = Destinos::adivinar('subdiario', ['Fecha', 'Tipo', 'Punto de Venta', 'Número Desde', 'Número Hasta', 'Cód. Autorización', 'Tipo Doc. Receptor',
            'Nro. Doc. Receptor', 'Denominación Receptor', 'Tipo Cambio', 'Moneda', 'Imp. Neto Gravado', 'Imp. Neto No Gravado', 'Imp. Op. Exentas', 'Otros Tributos', 'IVA', 'Imp. Total']);
        $this->assertSame(['fecha' => 0, 'tipo' => 1, 'punto_venta' => 2, 'numero' => 3, 'cuit' => 7, 'razon_social' => 8, 'neto_gravado' => 11, 'iva_total' => 15,
            'exento' => 13, 'no_gravado' => 12, 'otros_tributos' => 14, 'total' => 16, 'cae' => 5], $m);
    }

    public function testInterpretaFechasTiposYNumeros(): void
    {
        foreach (['15/03/2024', '15-03-2024', '15.03.2024', '15/3/24', '2024-03-15', '20240315', '45366', '15/03/2024 00:00:00'] as $v) {
            $this->assertSame('2024-03-15', Importador::fecha($v), $v);
        }
        $this->assertNull(Importador::fecha('31/02/2024'));
        $casos = ['FC' => ['FC', null], 'Factura A' => ['FC', 'A'], '1 - Factura A' => ['FC', 'A'], '008' => ['NC', 'B'], 'Nota de Crédito B' => ['NC', 'B'],
            'N/C' => ['NC', null], 'NCA' => ['NC', 'A'], 'ND' => ['ND', null], 'Nota de Débito Electrónica' => ['ND', null], '201 - Factura de Crédito Electrónica MiPyMEs (FCE) A' => ['FC', 'A'],
            'Remito' => [null, null], 'FAC' => ['FC', null]];
        foreach ($casos as $v => [$t, $l]) {
            [$tt, $ll] = Importador::tipoYLetra($v, '');
            $this->assertSame([$t, $l], [$tt, $ll], $v);
        }
        $this->assertSame(['FC', 'B'], array_slice(Importador::tipoYLetra('Factura', 'b'), 0, 2));
        $this->assertSame([4, 1234], Importador::puntoYNumero('', '0004-00001234'));
        $this->assertSame([7, 99], Importador::puntoYNumero('7', '99'));
        $this->assertSame([null, 5], Importador::puntoYNumero('', '5'));
        $this->assertSame(21.0, Importador::alicuotaDe(1000, 210));
        $this->assertSame(10.5, Importador::alicuotaDe(1000, 105));
        $this->assertNull(Importador::alicuotaDe(1000, 150));
    }

    // ------------------------------------------------------------------ clientes

    public function testClientesAltaConCodigoAutomaticoYTextosEnVezDeCodigos(): void
    {
        $prov = (string) $this->uno("SELECT descripcion FROM provincias ORDER BY codigo LIMIT 1");
        $codProv = (string) $this->uno("SELECT codigo FROM provincias ORDER BY codigo LIMIT 1");
        $r = $this->importar('clientes', [
            ['Razón social', 'CUIT', 'Condición de IVA', 'Provincia', 'Forma de pago', 'Mail'],
            ['T IMP ACME SA', '30-71111111-1', 'Responsable Inscripto', mb_strtolower($prov), 'contado', 'a@acme.com, b@acme.com'],
            ['T IMP JUAN', '', 'CF', '', '', ''],
            ['T IMP MONO', '27-33333333-9', 'Monotributista', '', '', ''],
        ]);
        $this->assertSame(true, $r['grabado']);
        $this->assertSame(3, $r['nuevas'], json_encode($r['errores']));
        $c = Db::pdo()->query("SELECT * FROM clientes WHERE razon_social = 'T IMP ACME SA'")->fetch();
        $this->assertSame(990011, (int) $c['codigo'], 'sigue la numeración de parámetros');
        $this->assertSame([1, 30711111111, $codProv, 1, 'a@acme.com; b@acme.com'], [(int) $c['iva'], (int) $c['cuit'], (string) $c['provincia'], (int) $c['forma_pago'], $c['emailfc']]);
        $this->assertSame(3, (int) $this->uno("SELECT iva FROM clientes WHERE razon_social = 'T IMP JUAN'"));
        $this->assertSame(7, (int) $this->uno("SELECT iva FROM clientes WHERE razon_social = 'T IMP MONO'"));
        $this->assertSame(990013, (int) $this->uno('SELECT ultimo_cliente FROM parametros WHERE id = 1'));
        $this->assertSame(1, (int) $this->uno("SELECT COUNT(*) FROM importaciones WHERE destino = 'clientes' AND nuevas = 3"));
    }

    public function testSimularNoGrabaYConUnErrorNoSeGrabaNadaSalvoQueSePidan(): void
    {
        $filas = [['Razón social', 'CUIT', 'IVA'], ['T IMP BUENO', '', '3'], ['T IMP SIN CUIT', '', 'Inscripto'], ['T IMP OTRO', '30-11111111-0', '1']];
        $r = $this->importar('clientes', $filas, [], true);
        $this->assertSame([1, 2, false], [$r['nuevas'], $r['con_error'], $r['grabado']]);
        $this->assertSame([3, 4], array_column($r['errores'], 0), 'los errores dicen la fila del archivo');
        $this->assertStringContainsString('CUIT es obligatorio', $r['errores'][0][1]);
        $this->assertSame(0, (int) $this->uno("SELECT COUNT(*) FROM clientes WHERE razon_social LIKE 'T IMP %'"));
        $this->assertSame(0, (int) $this->uno('SELECT COUNT(*) FROM importaciones'), 'la prueba no deja registro');

        $r = $this->importar('clientes', $filas);
        $this->assertSame(false, $r['grabado']);
        $this->assertSame(0, (int) $this->uno("SELECT COUNT(*) FROM clientes WHERE razon_social LIKE 'T IMP %'"), 'todo o nada');

        $r = $this->importar('clientes', $filas, ['parcial' => true]);
        $this->assertSame(true, $r['grabado']);
        $this->assertSame(1, (int) $this->uno("SELECT COUNT(*) FROM clientes WHERE razon_social LIKE 'T IMP %'"));
    }

    public function testClientesSeActualizanPorCodigoOCuitSoloEnLasColumnasElegidas(): void
    {
        $r = $this->importar('clientes', [['Código', 'Teléfono', 'Domicilio'], ['990001', '4444-5555', '']]);
        $this->assertSame(1, $r['actualizadas']);
        $c = Db::pdo()->query('SELECT * FROM clientes WHERE codigo = 990001')->fetch();
        $this->assertSame(['T CLIENTE RI SA', '4444-5555', 'CALLE 1'], [$c['razon_social'], $c['telefono'], $c['domicilio']], 'lo vacío y lo no elegido no se toca');
        $r = $this->importar('clientes', [['CUIT', 'Localidad'], ['30-70880558-7', 'MORÓN']]);
        $this->assertSame(1, $r['actualizadas']);
        $this->assertSame('MORÓN', $this->uno('SELECT localidad FROM clientes WHERE codigo = 990001'));
        $r = $this->importar('clientes', [['CUIT', 'Localidad'], ['30-70880558-7', 'ITUZAINGÓ']], ['existentes' => 'saltear']);
        $this->assertSame([0, 1], [$r['actualizadas'], $r['salteadas']]);
        $r = $this->importar('clientes', [['Código', 'Razón social'], ['990001', 'T A'], ['990001', 'T B']]);
        $this->assertStringContainsString('ya está en la fila 2', $r['errores'][0][1]);
    }

    // ------------------------------------------------------------------ artículos

    public function testArticulosAltaConValoresPorOmisionYSoloPrecios(): void
    {
        $r = $this->importar('articulos', [
            ['Código', 'Descripción', 'Precio', 'Código de barras', 'Rubro', 'IVA'],
            ['t-nuevo', 'T TORNILLO', '1.234,50', '7790001000019', 'T RUBRO FERRETERIA', ''],
            ['T-MAL', 'T CON EAN MAL', '10', '1234567890123', '', '10,5'],
        ], ['crear_faltantes' => true]);
        $this->assertSame(2, $r['nuevas'], json_encode($r['errores']));
        $a = Db::pdo()->query("SELECT * FROM articulos WHERE codigo = 'T-NUEVO'")->fetch();
        $this->assertSame(['AC', '$', '1234.5000', '7790001000019', 1], [$a['estado'], $a['moneda'], $a['precio_de_venta_1'], $a['ean13'], (int) $a['grabado']]);
        $this->assertSame(21.0, (float) $this->uno("SELECT porcentaje FROM tasas_iva WHERE codigo = " . Db::pdo()->quote((string) $a['alicuota'])));
        $this->assertSame('T RUBRO FERRETERIA', $this->uno('SELECT descripcion FROM rubros WHERE codigo = ' . Db::pdo()->quote((string) $a['rubro'])), 'se agregó el rubro');
        $this->assertNull($this->uno("SELECT ean13 FROM articulos WHERE codigo = 'T-MAL'"), 'el EAN mal no frena la fila: queda vacío');
        $this->assertSame(10.5, (float) $this->uno("SELECT t.porcentaje FROM articulos a JOIN tasas_iva t ON t.codigo = a.alicuota WHERE a.codigo = 'T-MAL'"));
        $this->assertTrue(count(array_filter($r['avisos'], static fn ($m) => str_contains($m[1], 'dígito verificador'))) === 1, json_encode($r['avisos']));

        // Actualizar solo precios: código y precio alcanzan
        $r = $this->importar('articulos', [['Código', 'Precio'], ['T-NUEVO', '2000'], ['T-ART', '150'], ['T-NO-EXISTE', '5']]);
        $this->assertSame([2, 1], [$r['actualizadas'], $r['con_error']]);
        $this->assertStringContainsString('Falta la descripción', $r['errores'][0][1]);
        $r = $this->importar('articulos', [['Código', 'Precio'], ['T-NUEVO', '2000'], ['T-ART', '150']]);
        $this->assertSame(['2000.0000', 'ARTICULO DE PRUEBA'], [$this->uno("SELECT precio_de_venta_1 FROM articulos WHERE codigo = 'T-NUEVO'"), $this->uno("SELECT descripcion FROM articulos WHERE codigo = 'T-ART'")]);
    }

    // ------------------------------------------------------------------ stock

    public function testStockDejarEnLaCantidadOSumar(): void
    {
        Db::pdo()->exec("INSERT INTO articulos (codigo, descripcion, alicuota, ean13) VALUES ('T-ART2', 'OTRO', '21', '7790001000026')");
        $r = $this->importar('stock', [['Artículo', 'Cantidad'], ['T-ART', '10'], ['7790001000026', '3,5'], ['NO-HAY', '1']], ['parcial' => true]);
        $this->assertSame([2, 1], [$r['actualizadas'], $r['con_error']]);
        $this->assertSame(10.0, (float) $this->uno("SELECT saldo FROM stock_saldos WHERE articulo = 'T-ART'"));
        $this->assertSame(3.5, (float) $this->uno("SELECT saldo FROM stock_saldos WHERE articulo = 'T-ART2'"));
        $r = $this->importar('stock', [['Artículo', 'Cantidad'], ['T-ART', '10'], ['T-ART2', '1']]);
        $this->assertSame([1, 1], [$r['actualizadas'], $r['salteadas']], 'el que ya tenía esa cantidad no se mueve');
        $this->assertSame(1.0, (float) $this->uno("SELECT saldo FROM stock_saldos WHERE articulo = 'T-ART2'"));
        $this->assertSame('AJUSTE', $this->uno("SELECT origen FROM stock_movimientos WHERE articulo = 'T-ART2' ORDER BY id DESC LIMIT 1"));
        $this->importar('stock', [['Artículo', 'Cantidad'], ['T-ART', '5'], ['T-ART', '-2']], ['modo_stock' => 'sumar']);
        $this->assertSame(13.0, (float) $this->uno("SELECT saldo FROM stock_saldos WHERE articulo = 'T-ART'"));
        $r = $this->importar('stock', [['Artículo', 'Cantidad'], ['T-ART', '5'], ['T-ART', '6']]);
        $this->assertStringContainsString('ya está en la fila', $r['errores'][0][1]);
        Db::pdo()->exec("DELETE FROM articulos WHERE codigo = 'T-ART2'");
    }

    // ------------------------------------------------------------------ comprobantes

    public function testComprobantesDeMisComprobantesDeArca(): void
    {
        $filas = [
            ['Fecha', 'Tipo', 'Punto de Venta', 'Número Desde', 'Cód. Autorización', 'Nro. Doc. Receptor', 'Denominación Receptor', 'Imp. Neto Gravado', 'IVA', 'Imp. Total'],
            ['15/03/2024', '1 - Factura A', '9', '120', '74112345678901', '30708805587', 'T CLIENTE RI SA', '1000', '210', '1210'],
            ['16/03/2024', '1 - Factura A', '3', '7', '74112345678902', '30-71435753-7', 'T NUEVO DE ARCA SRL', '100', '10,5', '110,5'],
            ['17/03/2024', '3 - Nota de Crédito A', '9', '5', '74112345678903', '30708805587', 'T CLIENTE RI SA', '-100', '-21', '-121'],
            ['18/03/2024', '1 - Factura A', '9', '121', '', '30708805587', 'T CLIENTE RI SA', '1000', '150', '1150'],
        ];
        $r = $this->importar('subdiario', $filas, ['crear_clientes' => true, 'parcial' => true]);
        $this->assertSame([3, 1], [$r['nuevas'], $r['con_error']], json_encode($r['errores']));
        $this->assertStringContainsString('no corresponde a una sola alícuota', $r['errores'][0][1]);
        $s = Db::pdo()->query("SELECT * FROM subdiario WHERE comprobante = 120")->fetch();
        $this->assertSame(['FC', 'A', 99, 9, 990001, '1000.00', '210.00', '1210.00', 'I', 0, '74112345678901', 120, 1, 1],
            [$s['tipo'], $s['letra'], (int) $s['sucursal'], (int) $s['prefijo'], (int) $s['cliente'], $s['neto_grabado'], $s['iva_1'], $s['neto'], $s['canal'],
             (int) $s['es_prueba'], $s['fe_ccae'], (int) $s['fe_nrocbte'], (int) $s['fe_ntipoafip'], (int) $s['forma_pago']]);
        $this->assertSame($r['importacion_id'], (int) $s['importacion_id']);
        $this->assertSame(['21.00', '1000.00', '210.00'], array_values(Db::pdo()->query("SELECT alicuota, monto, importe FROM iva WHERE subdiario_id = {$s['fe_clave']}")->fetch(\PDO::FETCH_NUM)));
        // El punto de venta 3 no es de ninguna sucursal: va a la primera y el PV queda en prefijo; el cliente se dio de alta con su CUIT
        $n = Db::pdo()->query('SELECT s.prefijo, c.razon_social, c.iva, c.cuit FROM subdiario s JOIN clientes c ON c.codigo = s.cliente WHERE s.comprobante = 7')->fetch(\PDO::FETCH_NUM);
        $this->assertSame([3, 'T NUEVO DE ARCA SRL', 1, 30714357537], [(int) $n[0], $n[1], (int) $n[2], (int) $n[3]]);
        $this->assertSame('10.50', $this->uno('SELECT alicuota FROM iva i JOIN subdiario s ON s.fe_clave = i.subdiario_id WHERE s.comprobante = 7'));
        $this->assertSame(['NC', '100.00', '121.00'], Db::pdo()->query("SELECT tipo, neto_grabado, neto FROM subdiario WHERE comprobante = 5")->fetch(\PDO::FETCH_NUM), 'la NC en negativo se guarda en positivo');
        $this->assertSame(0, (int) $this->uno('SELECT COUNT(*) FROM ctact'), 'sin saldo no va a la cuenta corriente');
        $this->assertSame(0, (int) $this->uno('SELECT COUNT(*) FROM caja'));
        // Entra al Libro IVA Digital (tiene CAE y no es de prueba)
        $libro = \App\Modules\Listados\LibroIvaDigital::generar('2024-03');
        $this->assertSame(3, $libro['cantidad']);

        // Volver a importar el mismo archivo no duplica
        $r = $this->importar('subdiario', $filas, ['crear_clientes' => true, 'parcial' => true]);
        $this->assertSame([0, 3], [$r['nuevas'], $r['salteadas']]);
        $this->assertSame(3, (int) $this->uno('SELECT COUNT(*) FROM subdiario'));
    }

    public function testComprobantesConSaldoLetraCYControlDeTotales(): void
    {
        $filas = [
            ['Fecha', 'Comprobante', 'Letra', 'Número', 'Cód. cliente', 'Neto gravado', 'IVA 21 %', 'IVA 10,5 %', 'Total', 'Saldo'],
            ['01/02/2025', 'FC', 'A', '0009-00000050', '990001', '300', '21', '21', '342', '100'],
            ['02/02/2025', 'FC', 'C', '0009-00000051', '990003', '', '', '', '500', '500'],
            ['03/02/2025', 'FC', 'A', '0009-00000052', '990001', '100', '21', '', '100', ''],
            ['04/02/2025', 'FC', 'A', '0009-00000053', '990077', '100', '21', '', '121', ''],
            ['05/02/2025', 'FC', 'B', '0009-00000054', '990002', '100', '', '', '100', ''],
            ['06/02/2025', 'Remito', 'R', '0009-00000055', '990002', '100', '', '', '100', ''],
        ];
        $r = $this->importar('subdiario', $filas, ['parcial' => true]);
        $this->assertSame([2, 4], [$r['nuevas'], $r['con_error']], json_encode($r['errores']));
        $msj = implode(' | ', array_column($r['errores'], 1));
        foreach (['es menor que la suma', 'No existe el cliente con código', 'necesita el IVA', 'No entiendo el tipo'] as $t) {
            $this->assertStringContainsString($t, $msj);
        }
        $s = Db::pdo()->query('SELECT * FROM subdiario WHERE comprobante = 50')->fetch();
        $this->assertSame(['300.00', '42.00', '342.00', 2], [$s['neto_grabado'], $s['iva_1'], $s['neto'], (int) $s['forma_pago']]);
        $iva = Db::pdo()->query("SELECT alicuota, monto FROM iva WHERE subdiario_id = {$s['fe_clave']} ORDER BY alicuota")->fetchAll(\PDO::FETCH_NUM);
        $this->assertSame([['10.50', '200.00'], ['21.00', '100.00']], $iva, 'la base de cada alícuota sale del IVA');
        $cc = Db::pdo()->query('SELECT tico, saldo, cliente FROM ctact WHERE numero = 50')->fetch(\PDO::FETCH_NUM);
        $this->assertSame([1, '100.00', 990001], [(int) $cc[0], $cc[1], (int) $cc[2]]);
        $c = Db::pdo()->query('SELECT neto_grabado, neto, iva_1, es_prueba, fe_ccae FROM subdiario WHERE comprobante = 51')->fetch(\PDO::FETCH_NUM);
        $this->assertSame(['500.00', '500.00', '0.00', 0, ''], [$c[0], $c[1], $c[2], (int) $c[3], $c[4]]);
        $this->assertSame(0, (int) $this->uno('SELECT COUNT(*) FROM iva i JOIN subdiario s ON s.fe_clave = i.subdiario_id WHERE s.comprobante = 51'));
        // El saldo importado se puede cobrar: aparece en la deuda del cliente
        $this->assertSame(600.0, (float) $this->uno('SELECT SUM(saldo) FROM ctact'));
    }

    public function testFaltaElegirColumnasObligatorias(): void
    {
        try {
            $this->importar('subdiario', [['Fecha', 'Total'], ['01/01/2025', '10']]);
            $this->fail('faltan columnas');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Tipo de comprobante', $e->getMessage());
            $this->assertStringContainsString('Cliente', $e->getMessage());
        }
        try {
            $this->importar('clientes', [['Teléfono'], ['1']]);
            $this->fail('faltan columnas');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Razón social', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ pantalla y permisos

    private function subir(string $destino, string $contenido, string $nombre): \App\Http\Response
    {
        $f = $this->dir . '/subida_' . $nombre;
        file_put_contents($f, $contenido);
        $req = Request::make('POST', '/importar/subir', ['destino' => $destino, 'titulos' => '1', Csrf::CAMPO => Csrf::token()], [],
            ['archivo' => ['name' => $nombre, 'tmp_name' => $f, 'error' => UPLOAD_ERR_OK, 'size' => strlen($contenido)]]);
        return $this->app->handle($req);
    }

    public function testRecorridoCompletoPorLaPantallaYModeloGuardado(): void
    {
        $this->loginComo($this->crearUsuario('t_imp_adm', 'admin'));
        $this->assertStringContainsString('Bajar planilla modelo', $this->get('/importar')->body);
        $plantilla = $this->get('/importar/plantilla', ['destino' => 'articulos']);
        $this->assertSame(200, $plantilla->status);
        $this->assertStringStartsWith('PK', $plantilla->body);

        $res = $this->subir('clientes', "Nombre;Teléfono del local\nT DESDE PANTALLA;1234\n", 'clientes.csv');
        $this->assertSame(302, $res->status);
        $this->assertStringContainsString('/importar/columnas?t=', $res->headers['Location']);
        parse_str((string) parse_url($res->headers['Location'], PHP_URL_QUERY), $q);
        $t = (string) $q['t'];
        $pag = $this->get('/importar/columnas', ['t' => $t]);
        $this->assertStringContainsString('T DESDE PANTALLA', $pag->body, 'vista previa');
        $this->assertStringContainsString('<option value="0" selected>A · Nombre</option>', $pag->body, 'razón social sugerida por el título');

        $col = ['razon_social' => '0', 'telefono' => '1'];
        $res = $this->post('/importar/procesar', ['t' => $t, 'col' => $col, 'accion' => 'probar', 'modelo_nombre' => 'T mi sistema']);
        $this->assertRedirect('/importar/resultado?t=' . $t, $res);
        $this->assertStringContainsString('Prueba (no se grabó nada)', $this->get('/importar/resultado', ['t' => $t])->body);
        $this->assertSame(0, (int) $this->uno("SELECT COUNT(*) FROM clientes WHERE razon_social = 'T DESDE PANTALLA'"));
        $res = $this->post('/importar/procesar', ['t' => $t, 'repetir' => '1', 'accion' => 'importar']);
        $this->assertStringContainsString('Importación grabada', $this->get('/importar/resultado', ['t' => $t])->body);
        $this->assertSame('1234', $this->uno("SELECT telefono FROM clientes WHERE razon_social = 'T DESDE PANTALLA'"));
        $this->assertStringContainsString('clientes.csv', $this->get('/importar')->body, 'queda en el historial');

        // El modelo guardado sirve con otro archivo con las columnas en otro orden
        $m = (int) $this->uno("SELECT id FROM importacion_modelos WHERE nombre = 'T mi sistema'");
        $res = $this->subir('clientes', "Teléfono del local;Otra;Nombre\n99;x;T OTRO\n", 'otro.csv');
        parse_str((string) parse_url($res->headers['Location'], PHP_URL_QUERY), $q);
        $pag = $this->get('/importar/columnas', ['t' => (string) $q['t'], 'modelo' => (string) $m]);
        $this->assertStringContainsString('<option value="2" selected>C · Nombre</option>', $pag->body);
        $this->assertStringContainsString('<option value="0" selected>A · Teléfono del local</option>', $pag->body);
    }

    public function testPermisosOperadorNoEntraYCadaDestinoPideSuPermiso(): void
    {
        $this->loginComo($this->crearUsuario('t_imp_op', 'operador'));
        $this->assertSame(403, $this->get('/importar')->status);
        $this->assertSame(403, $this->subir('clientes', "a\n1\n", 'a.csv')->status);

        // Un rol con importar.datos pero sin editar artículos ni mover stock: solo ve clientes
        $pdo = Db::pdo();
        $pdo->exec("INSERT INTO roles (codigo, nombre) VALUES ('t_imp', 'T Importa clientes')");
        $rol = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO rol_permisos (rol_id, permiso_id) SELECT {$rol}, id FROM permisos WHERE codigo IN ('importar.datos', 'clientes.ver', 'clientes.editar')");
        $id = $this->crearUsuario('t_imp_cli', 't_imp');
        try {
            $this->loginComo($id);
            $html = $this->get('/importar')->body;
            $this->assertStringContainsString('value="clientes"', $html);
            $this->assertStringNotContainsString('value="articulos"', $html);
            $this->assertStringNotContainsString('value="stock"', $html);
            $this->assertStringNotContainsString('value="subdiario"', $html);
            $res = $this->subir('articulos', "Código;Precio\nT-ART;1\n", 'a.csv');
            $this->assertRedirect('/importar', $res, 'artículos sin articulos.editar no');
            $this->assertSame(404, $this->get('/importar/plantilla', ['destino' => 'stock'])->status);
        } finally {
            $pdo->exec("DELETE FROM usuarios WHERE id = {$id}");
            $pdo->exec("DELETE FROM rol_permisos WHERE rol_id = {$rol}");
            $pdo->exec("DELETE FROM roles WHERE id = {$rol}");
        }
    }
}
