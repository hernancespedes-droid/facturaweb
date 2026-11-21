<?php
declare(strict_types=1);

namespace App\Modules\Importacion;

/**
 * Lee una planilla sin librerías: Excel (.xlsx, la primera hoja), CSV y TXT separados por punto y coma, coma, tabulador o barra.
 *
 *  - CSV/TXT: el separador se detecta solo (el que más se repite igual en las primeras líneas); la codificación también (UTF-8, con o sin
 *    BOM, o Windows-1252, que es lo que guarda el Excel en castellano). Respeta las comillas (un campo con ; o con saltos de línea).
 *  - XLSX: textos compartidos, textos en la celda, números y fechas (una celda con formato de fecha se devuelve dd/mm/aaaa). Los números
 *    se devuelven con coma decimal («1234,5»), así Valida::decimal no confunde «1.234» (un número con 3 decimales) con mil doscientos.
 *  - El Excel viejo (.xls) no se lee: hay que guardarlo como .xlsx o .csv (se avisa).
 *
 * Devuelve las filas como listas de textos (sin filas vacías). Los títulos son la primera fila si $conTitulos.
 */
final class Lector
{
    public const MAX_FILAS = 50000;
    public const EXTENSIONES = ['xlsx', 'csv', 'txt'];

    /**
     * @return array{titulos:string[],filas:array<int,array{n:int,celdas:string[]}>,formato:string,separador:?string}
     *         n = número de fila en el archivo (para que el usuario la encuentre)
     */
    public static function leer(string $archivo, string $nombreOriginal, bool $conTitulos = true): array
    {
        $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
        if ($ext === 'xls') {
            throw new \RuntimeException('El Excel viejo (.xls) no se puede leer: abrilo en Excel y guardalo como «Libro de Excel (.xlsx)» o como CSV.');
        }
        if (!in_array($ext, self::EXTENSIONES, true)) {
            throw new \RuntimeException('El archivo tiene que ser .xlsx, .csv o .txt.');
        }
        $separador = null;
        if ($ext === 'xlsx') {
            $filas = self::xlsx($archivo);
        } else {
            $texto = (string) file_get_contents($archivo);
            [$filas, $separador] = self::delimitado($texto);
        }
        // Sin filas vacías; cada fila recuerda su número en el archivo
        $out = [];
        foreach ($filas as $n => $celdas) {
            $celdas = array_map(static fn ($c) => trim((string) $c), $celdas);
            if (implode('', $celdas) === '') {
                continue;
            }
            $out[] = ['n' => $n, 'celdas' => $celdas];
            if (count($out) > self::MAX_FILAS + 1) {
                throw new \RuntimeException('El archivo tiene más de ' . number_format(self::MAX_FILAS, 0, ',', '.') . ' filas: partilo en varios.');
            }
        }
        if ($out === []) {
            throw new \RuntimeException('El archivo está vacío.');
        }
        $ancho = max(array_map(static fn ($f) => count($f['celdas']), $out));
        if ($conTitulos) {
            $primera = array_shift($out);
            $titulos = [];
            foreach (array_pad($primera['celdas'], $ancho, '') as $i => $t) {
                $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
                $t = $t !== '' ? $t : 'Columna ' . self::letra($i);
                $base = $t;
                for ($k = 2; in_array($t, $titulos, true); $k++) { // dos columnas con el mismo título
                    $t = $base . ' (' . $k . ')';
                }
                $titulos[] = $t;
            }
        } else {
            $titulos = array_map(static fn ($i) => 'Columna ' . self::letra($i), range(0, $ancho - 1));
        }
        foreach ($out as &$f) {
            $f['celdas'] = array_pad($f['celdas'], count($titulos), '');
        }
        unset($f);
        return ['titulos' => $titulos, 'filas' => $out, 'formato' => $ext, 'separador' => $separador];
    }

    /** A, B, … Z, AA, AB… */
    public static function letra(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    // ------------------------------------------------------------------ CSV / TXT

    /** @return array{0:array<int,string[]>,1:string} */
    public static function delimitado(string $texto): array
    {
        if (str_starts_with($texto, "\xEF\xBB\xBF")) {
            $texto = substr($texto, 3);
        } elseif (str_starts_with($texto, "\xFF\xFE") || str_starts_with($texto, "\xFE\xFF")) {
            $texto = (string) mb_convert_encoding(substr($texto, 2), 'UTF-8', str_starts_with($texto, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        }
        if (!mb_check_encoding($texto, 'UTF-8')) {
            $texto = (string) mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
        }
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $sep = self::separador($texto);
        $filas = [];
        $fila = [];
        $campo = '';
        $comillas = false;
        $n = 1;
        $inicio = 1;
        $largo = strlen($texto);
        for ($i = 0; $i < $largo; $i++) {
            $ch = $texto[$i];
            if ($comillas) {
                if ($ch === '"') {
                    if (($texto[$i + 1] ?? '') === '"') {
                        $campo .= '"';
                        $i++;
                    } else {
                        $comillas = false;
                    }
                } else {
                    $campo .= $ch;
                    $n += $ch === "\n" ? 1 : 0;
                }
                continue;
            }
            if ($ch === '"' && trim($campo) === '') {
                $comillas = true;
                $campo = '';
            } elseif ($ch === $sep) {
                $fila[] = $campo;
                $campo = '';
            } elseif ($ch === "\n") {
                $fila[] = $campo;
                $filas[$inicio] = $fila;
                $fila = [];
                $campo = '';
                $inicio = ++$n;
            } else {
                $campo .= $ch;
            }
        }
        if ($campo !== '' || $fila !== []) {
            $fila[] = $campo;
            $filas[$inicio] = $fila;
        }
        return [$filas, $sep];
    }

    /** El separador que aparece la misma cantidad de veces (y más de cero) en más líneas de las primeras 20. */
    private static function separador(string $texto): string
    {
        $lineas = array_slice(array_values(array_filter(explode("\n", $texto), static fn ($l) => trim($l) !== '')), 0, 20);
        $mejor = ';';
        $puntos = -1;
        foreach ([';', "\t", ',', '|'] as $s) {
            $cuentas = array_map(static fn ($l) => substr_count(preg_replace('/"[^"]*"/', '', $l) ?? $l, $s), $lineas);
            $cuentas = array_filter($cuentas);
            if ($cuentas === []) {
                continue;
            }
            $frec = array_count_values($cuentas);
            arsort($frec);
            $p = (int) reset($frec) * 1000 + (int) array_key_first($frec);
            if ($p > $puntos) {
                $puntos = $p;
                $mejor = $s;
            }
        }
        return $mejor;
    }

    // ------------------------------------------------------------------ XLSX

    /** @return array<int,string[]> número de fila => celdas */
    private static function xlsx(string $archivo): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('El servidor no tiene la extensión zip de PHP: guardá el archivo como CSV.');
        }
        $z = new \ZipArchive();
        if ($z->open($archivo) !== true) {
            throw new \RuntimeException('El archivo no es un Excel .xlsx válido (¿es un .xls renombrado? guardalo como .xlsx o CSV).');
        }
        try {
            $hoja = self::primeraHoja($z);
            $xml = $z->getFromName($hoja);
            if ($xml === false) {
                throw new \RuntimeException('El Excel no tiene hojas.');
            }
            $compartidos = self::compartidos((string) $z->getFromName('xl/sharedStrings.xml'));
            $fechas = self::estilosFecha((string) $z->getFromName('xl/styles.xml'));
        } finally {
            $z->close();
        }
        $r = new \XMLReader();
        if (!$r->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new \RuntimeException('No se pudo leer la hoja del Excel.');
        }
        $filas = [];
        $fila = null;
        $nFila = 0;
        while ($r->read()) {
            if ($r->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($r->localName === 'row') {
                $nFila = (int) ($r->getAttribute('r') ?? 0) ?: $nFila + 1;
                $filas[$nFila] = [];
                $fila = &$filas[$nFila];
                $col = -1;
                if ($r->isEmptyElement) {
                    unset($fila);
                    $fila = null;
                }
                continue;
            }
            if ($r->localName === 'c' && $fila !== null) {
                $ref = (string) $r->getAttribute('r');
                $col = $ref !== '' ? self::columna($ref) : $col + 1;
                $tipo = (string) $r->getAttribute('t');
                $estilo = (int) $r->getAttribute('s');
                $valor = '';
                if (!$r->isEmptyElement) {
                    $nodo = $r->expand();
                    if ($nodo instanceof \DOMElement) {
                        if ($tipo === 'inlineStr') {
                            $valor = $nodo->textContent;
                        } else {
                            $v = $nodo->getElementsByTagName('v')->item(0);
                            $valor = $v !== null ? $v->textContent : '';
                        }
                    }
                }
                $valor = match ($tipo) {
                    's' => $compartidos[(int) $valor] ?? '',
                    'b' => $valor === '1' ? 'SI' : 'NO',
                    'inlineStr', 'str', 'e' => $valor,
                    default => self::numero($valor, isset($fechas[$estilo])),
                };
                $fila = array_pad($fila, $col, '');
                $fila[$col] = $valor;
            }
        }
        unset($fila);
        $r->close();
        return $filas;
    }

    private static function primeraHoja(\ZipArchive $z): string
    {
        $wb = $z->getFromName('xl/workbook.xml');
        $rels = $z->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rels !== false) {
            $w = @simplexml_load_string($wb, \SimpleXMLElement::class, LIBXML_NONET);
            $rl = @simplexml_load_string($rels, \SimpleXMLElement::class, LIBXML_NONET);
            if ($w !== false && $rl !== false && isset($w->sheets->sheet[0])) {
                $id = (string) ($w->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
                foreach ($rl->Relationship as $rel) {
                    if ((string) $rel['Id'] === $id) {
                        $t = ltrim((string) $rel['Target'], '/');
                        return str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
                    }
                }
            }
        }
        return 'xl/worksheets/sheet1.xml';
    }

    /** @return string[] */
    private static function compartidos(string $xml): array
    {
        if ($xml === '') {
            return [];
        }
        $out = [];
        $r = new \XMLReader();
        $r->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        while ($r->read()) {
            if ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 'si') {
                $nodo = $r->expand();
                $texto = '';
                if ($nodo instanceof \DOMElement) {
                    foreach ($nodo->getElementsByTagName('t') as $t) {
                        // los textos fonéticos (rPh) no son parte del valor
                        if ($t->parentNode !== null && $t->parentNode->nodeName === 'rPh') {
                            continue;
                        }
                        $texto .= $t->textContent;
                    }
                }
                $out[] = $texto;
            }
        }
        $r->close();
        return $out;
    }

    /** Índices de cellXfs que son fechas. @return array<int,bool> */
    private static function estilosFecha(string $xml): array
    {
        if ($xml === '') {
            return [];
        }
        $s = @simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        if ($s === false) {
            return [];
        }
        $propios = [];
        foreach ($s->numFmts->numFmt ?? [] as $f) {
            $codigo = strtolower(preg_replace('/\[[^\]]*\]|"[^"]*"|\\\\./', '', (string) $f['formatCode']) ?? '');
            if (preg_match('/[dy]/', $codigo) === 1 || preg_match('/m.*[dy]|[dy].*m/', $codigo) === 1) {
                $propios[(int) $f['numFmtId']] = true;
            }
        }
        $out = [];
        $i = 0;
        foreach ($s->cellXfs->xf ?? [] as $xf) {
            $id = (int) $xf['numFmtId'];
            if (($id >= 14 && $id <= 22) || ($id >= 45 && $id <= 47) || isset($propios[$id])) {
                $out[$i] = true;
            }
            $i++;
        }
        return $out;
    }

    private static function columna(string $ref): int
    {
        $n = 0;
        foreach (str_split(preg_replace('/\d+/', '', strtoupper($ref)) ?? '') as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }

    /** Número del Excel a texto: fecha dd/mm/aaaa si la celda es de fecha; si no, con coma decimal y sin notación científica. */
    private static function numero(string $v, bool $esFecha): string
    {
        if ($v === '' || !is_numeric($v)) {
            return $v;
        }
        $f = (float) $v;
        if ($esFecha && $f >= 1 && $f < 2958466) {
            return self::fechaExcel($f);
        }
        if (floor($f) === $f && abs($f) < 1e15) {
            return sprintf('%.0f', $f);
        }
        $t = rtrim(rtrim(sprintf('%.6F', $f), '0'), '.');
        return str_replace('.', ',', $t);
    }

    /** Número de serie de fecha del Excel (sistema 1900) a dd/mm/aaaa. */
    public static function fechaExcel(float $serie): string
    {
        $dias = (int) floor($serie);
        $base = new \DateTimeImmutable('1899-12-30');
        return $base->modify("+{$dias} days")->format('d/m/Y');
    }
}
