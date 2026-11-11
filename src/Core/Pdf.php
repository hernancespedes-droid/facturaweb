<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Generador de PDF mínimo, sin librerías (en el hosting no hay Composer): páginas A4, texto en Helvetica y
 * Helvetica-Bold (fuentes estándar de PDF, codificación Windows-1252: tildes y eñes), líneas, rectángulos y
 * un código QR dibujado con rectángulos. Las coordenadas son en milímetros, con el origen arriba a la izquierda.
 */
final class Pdf
{
    public const ANCHO = 210.0;
    public const ALTO = 297.0;
    private const PT = 72 / 25.4;

    /** Anchos de Helvetica (por 1000) de los caracteres 32 a 126. */
    private const W_REG = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556,
        278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944,
        667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556,
        500, 722, 500, 500, 500, 334, 260, 334, 584];
    /** Anchos de Helvetica-Bold (por 1000) de los caracteres 32 a 126. */
    private const W_BOLD = [278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556,
        333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944,
        667, 667, 611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611,
        556, 778, 556, 556, 500, 389, 280, 389, 584];

    /** @var string[] contenido de cada página */
    private array $paginas = [''];
    private int $actual = 0;

    public function pagina(): void
    {
        $this->paginas[] = '';
        $this->actual = count($this->paginas) - 1;
    }

    private static function pt(float $mm): string
    {
        return rtrim(rtrim(number_format($mm * self::PT, 2, '.', ''), '0'), '.') ?: '0';
    }

    private static function y(float $mm): string
    {
        return self::pt(self::ALTO - $mm);
    }

    /** Texto en Windows-1252 (lo que no existe en esa codificación sale como "?"). */
    private static function cp1252(string $s): string
    {
        $s = str_replace(["\r", "\n", "\t"], ' ', $s);
        return (string) mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    }

    /** Ancho de un texto en milímetros. */
    public function ancho(string $s, float $size = 9.0, bool $bold = false): float
    {
        $t = self::cp1252($s);
        $w = $bold ? self::W_BOLD : self::W_REG;
        $suma = 0;
        for ($i = 0, $n = strlen($t); $i < $n; $i++) {
            $c = ord($t[$i]);
            // letras con tilde y caracteres fuera de ASCII: el ancho de la letra base (o el de la "n" para el resto)
            $suma += $c >= 32 && $c <= 126 ? $w[$c - 32] : ($c >= 192 ? $w[ord('a') - 32] : $w[ord('n') - 32]);
        }
        return $suma * $size / 1000 / self::PT;
    }

    /** Recorta un texto con "…" si no entra en $max milímetros. */
    public function ajustar(string $s, float $max, float $size = 9.0, bool $bold = false): string
    {
        if ($this->ancho($s, $size, $bold) <= $max) {
            return $s;
        }
        while ($s !== '' && $this->ancho($s . '...', $size, $bold) > $max) {
            $s = mb_substr($s, 0, -1);
        }
        return rtrim($s) . '...';
    }

    /** Parte un texto en renglones que entren en $max milímetros (respeta los saltos de línea del texto). @return string[] */
    public function partir(string $s, float $max, float $size = 9.0, bool $bold = false): array
    {
        $out = [];
        foreach (preg_split('/\R/u', trim($s)) ?: [''] as $parrafo) {
            $linea = '';
            foreach (preg_split('/\s+/u', trim($parrafo)) ?: [] as $palabra) {
                $prueba = $linea === '' ? $palabra : $linea . ' ' . $palabra;
                if ($linea !== '' && $this->ancho($prueba, $size, $bold) > $max) {
                    $out[] = $linea;
                    $linea = $palabra;
                } else {
                    $linea = $prueba;
                }
            }
            $out[] = $linea;
        }
        return $out ?: [''];
    }

    /** Texto con la línea base en $y. $alinear: L, R o C respecto de $x (con $w, dentro de ese ancho). */
    public function texto(float $x, float $y, string $s, float $size = 9.0, bool $bold = false, string $alinear = 'L', ?float $w = null, float $gris = 0.0): void
    {
        if ($s === '') {
            return;
        }
        $ancho = $this->ancho($s, $size, $bold);
        if ($w !== null) {
            $x += $alinear === 'R' ? $w - $ancho : ($alinear === 'C' ? ($w - $ancho) / 2 : 0);
        }
        $esc = strtr(self::cp1252($s), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
        $this->paginas[$this->actual] .= sprintf("BT %s g /%s %s Tf %s %s Td (%s) Tj ET\n", $gris, $bold ? 'F2' : 'F1', rtrim(rtrim(number_format($size, 2, '.', ''), '0'), '.'),
            self::pt($x), self::y($y), $esc);
    }

    public function linea(float $x1, float $y1, float $x2, float $y2, float $grosor = 0.2, float $gris = 0.0): void
    {
        $this->paginas[$this->actual] .= sprintf("%s G %s w %s %s m %s %s l S\n", $gris, self::pt($grosor), self::pt($x1), self::y($y1), self::pt($x2), self::y($y2));
    }

    public function rect(float $x, float $y, float $w, float $h, float $grosor = 0.2, ?float $relleno = null): void
    {
        $pdf = '';
        if ($relleno !== null) {
            $pdf .= sprintf("%s g %s %s %s %s re f\n", $relleno, self::pt($x), self::y($y + $h), self::pt($w), self::pt($h));
        }
        if ($grosor > 0) {
            $pdf .= sprintf("0 G %s w %s %s %s %s re S\n", self::pt($grosor), self::pt($x), self::y($y + $h), self::pt($w), self::pt($h));
        }
        $this->paginas[$this->actual] .= $pdf;
    }

    /** Dibuja una matriz de QR (true = módulo negro) de $lado milímetros, con un margen blanco de un módulo. @param array<int,array<int,bool>> $m */
    public function qr(array $m, float $x, float $y, float $lado): void
    {
        $n = count($m);
        $mod = $lado / ($n + 2);
        $this->paginas[$this->actual] .= '0 g ';
        for ($f = 0; $f < $n; $f++) {
            for ($c = 0; $c < $n; $c++) {
                if ($m[$f][$c]) {
                    $this->paginas[$this->actual] .= sprintf("%s %s %s %s re f ", self::pt($x + ($c + 1) * $mod), self::y($y + ($f + 2) * $mod), self::pt($mod), self::pt($mod));
                }
            }
        }
        $this->paginas[$this->actual] .= "\n";
    }

    /** El archivo PDF. */
    public function salida(string $titulo = ''): string
    {
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $n = count($this->paginas);
        $kids = [];
        for ($i = 0; $i < $n; $i++) {
            $kids[] = (5 + $i * 2) . ' 0 R';
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        foreach ($this->paginas as $i => $contenido) {
            $p = 5 + $i * 2;
            $objs[$p] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::pt(self::ANCHO) . ' ' . self::pt(self::ALTO) . '] /Contents ' . ($p + 1)
                . ' 0 R /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> >>';
            $datos = function_exists('gzcompress') ? gzcompress($contenido, 6) : $contenido;
            $filtro = function_exists('gzcompress') ? ' /Filter /FlateDecode' : '';
            $objs[$p + 1] = '<< /Length ' . strlen((string) $datos) . $filtro . " >>\nstream\n" . $datos . "\nendstream";
        }
        $info = count($objs) + 1;
        $objs[$info] = '<< /Title (' . strtr(self::cp1252($titulo), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ') /Producer (FacturaWeb) >>';

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $num => $cuerpo) {
            $offsets[$num] = strlen($out);
            $out .= $num . " 0 obj\n" . $cuerpo . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= 'xref' . "\n0 " . ($info + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $info; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        return $out . 'trailer << /Size ' . ($info + 1) . ' /Root 1 0 R /Info ' . $info . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }
}
