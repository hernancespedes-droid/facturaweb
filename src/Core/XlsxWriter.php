<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use ZipArchive;

/**
 * Escritor mínimo de Excel (.xlsx) sin librerías: una hoja, encabezado en negrita con filtro y
 * fila congelada. Los textos van como "inline strings"; los números enteros/decimales como número.
 */
final class XlsxWriter
{
    /** @var array<int,array<int,mixed>> */
    private array $filas = [];
    /** @var int[] */
    private array $anchos = [];
    /** @var array<int,int> índice de fila => estilo (0.9.33: filas pintadas) */
    private array $estilos = [];

    /** Colores de fondo para fila(): estilo de cellXfs. */
    private const COLORES = ['rojo' => 3, 'ambar' => 4, 'verde' => 5];

    /** @param string[] $encabezado @param int[] $anchos ancho de cada columna (caracteres) */
    public function __construct(private string $hoja, private array $encabezado, array $anchos = [])
    {
        $this->anchos = $anchos;
    }

    /** @param array<int,mixed> $fila */
    public function fila(array $fila, ?string $color = null): void
    {
        $this->filas[] = $fila;
        if ($color !== null && isset(self::COLORES[$color])) {
            $this->estilos[count($this->filas) - 1] = self::COLORES[$color];
        }
    }

    /** Contenido binario del .xlsx. */
    public function contenido(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        if ($tmp === false || $zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo armar el Excel.');
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::x(mb_substr($this->hoja, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets>'
            . ($this->filas !== [] || $this->encabezado !== [] ? '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\''
                . self::x(mb_substr($this->hoja, 0, 31)) . '\'!$A$1:$' . self::letra(max(1, count($this->encabezado)) - 1) . '$' . (count($this->filas) + 1) . '</definedName></definedNames>' : '')
            . '</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="6"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFDCE6FF"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFC7CE"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFEB9C"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFC6EFCE"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="6"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="3" borderId="0" xfId="0" applyFill="1" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="4" borderId="0" xfId="0" applyFill="1" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="5" borderId="0" xfId="0" applyFill="1" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->hojaXml());
        $zip->close();
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }

    private function hojaXml(): string
    {
        $out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        if ($this->anchos !== []) {
            $out .= '<cols>';
            foreach ($this->anchos as $i => $w) {
                $out .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (int) $w . '" customWidth="1"/>';
            }
            $out .= '</cols>';
        }
        $out .= '<sheetData>';
        $out .= $this->filaXml(1, $this->encabezado, 1);
        foreach ($this->filas as $i => $f) {
            $out .= $this->filaXml($i + 2, $f, $this->estilos[$i] ?? 2);
        }
        $out .= '</sheetData>';
        $ultima = count($this->filas) + 1;
        $out .= '<autoFilter ref="A1:' . self::letra(max(1, count($this->encabezado)) - 1) . $ultima . '"/>';
        return $out . '</worksheet>';
    }

    /** @param array<int,mixed> $valores */
    private function filaXml(int $nro, array $valores, int $estilo): string
    {
        $out = '<row r="' . $nro . '">';
        foreach (array_values($valores) as $i => $v) {
            $ref = self::letra($i) . $nro;
            if ($v === null || $v === '') {
                if ($estilo > 2) {
                    $out .= '<c r="' . $ref . '" s="' . $estilo . '"/>'; // fila pintada: también las celdas vacías
                }
                continue;
            }
            if ((is_int($v) || is_float($v)) && $estilo !== 1) {
                $out .= '<c r="' . $ref . '"' . ($estilo > 2 ? ' s="' . $estilo . '"' : '') . '><v>' . $v . '</v></c>';
            } else {
                $out .= '<c r="' . $ref . '" t="inlineStr" s="' . $estilo . '"><is><t xml:space="preserve">' . self::x((string) $v) . '</t></is></c>';
            }
        }
        return $out . '</row>';
    }

    /** 0 => A, 25 => Z, 26 => AA */
    public static function letra(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    private static function x(string $s): string
    {
        // Saca caracteres de control que el XML no admite.
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
