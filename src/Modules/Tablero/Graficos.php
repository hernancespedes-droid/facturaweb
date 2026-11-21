<?php
declare(strict_types=1);

namespace App\Modules\Tablero;

/**
 * Gráficos del tablero en SVG dibujados en el servidor (sin librerías). Los colores salen de variables CSS (`--g-marca`, `--g-comp`, `--line`…),
 * así respetan el color del cliente y el tema claro/oscuro. `public/assets/tablero.js` agrega la línea guía y el cartel con los valores al pasar
 * el mouse (o tocar): cada zona sensible lleva `data-tip` (renglones separados por «|») y, en las líneas, `data-x` / `data-ya` / `data-yb`.
 */
final class Graficos
{
    private const W = 640;
    private const H = 240;
    private const X0 = 58;
    private const Y0 = 210; // línea de base
    private const Y1 = 14;  // techo

    /** Máximo «redondo» del eje y su paso (3 o 4 líneas). @return array{0:float,1:float} */
    public static function escala(float $max): array
    {
        if ($max <= 0) {
            return [1000.0, 500.0];
        }
        $crudo = $max / 3;
        $pot = 10 ** floor(log10($crudo));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($m * $pot >= $crudo) {
                $paso = $m * $pot;
                break;
            }
        }
        $paso ??= 10 * $pot;
        return [ceil($max / $paso) * $paso, $paso];
    }

    private static function y(float $v, float $max): float
    {
        return round(self::Y0 - max(0.0, $v) * (self::Y0 - self::Y1) / $max, 1);
    }

    private static function grilla(float $max, float $paso, float $x1): string
    {
        $o = '';
        for ($v = $paso; $v <= $max + 0.001; $v += $paso) {
            $y = self::y($v, $max);
            $o .= '<line class="g-grid" x1="' . self::X0 . '" x2="' . $x1 . '" y1="' . $y . '" y2="' . $y . '"/>'
                . '<text class="g-eje" x="' . (self::X0 - 8) . '" y="' . ($y + 4) . '" text-anchor="end">' . e(self::eje($v)) . '</text>';
        }
        return $o . '<text class="g-eje" x="' . (self::X0 - 8) . '" y="' . (self::Y0 + 4) . '" text-anchor="end">0</text>'
            . '<line class="g-base" x1="' . self::X0 . '" x2="' . $x1 . '" y1="' . self::Y0 . '" y2="' . self::Y0 . '"/>';
    }

    /** Rótulo del eje: 0 · 500 mil · 10 M. */
    private static function eje(float $v): string
    {
        if ($v >= 1e6) {
            return rtrim(rtrim(number_format($v / 1e6, 1, ',', '.'), '0'), ',') . ' M';
        }
        if ($v >= 1e3) {
            return rtrim(rtrim(number_format($v / 1e3, 1, ',', '.'), '0'), ',') . ' mil';
        }
        return number_format($v, 0, ',', '.');
    }

    /**
     * Venta acumulada día a día: el mes (color del cliente) contra el anterior (gris).
     * @param array<int,float> $a acumulado del mes, un valor por día transcurrido
     * @param array<int,float> $b acumulado del mes anterior, completo
     */
    public static function lineas(array $a, array $b, int $diasMes, string $nombreA, string $nombreB): string
    {
        $n = max($diasMes, count($b), 2);
        $x1 = self::W - 72;
        [$max, $paso] = self::escala(max([0.0, ...$a, ...$b]));
        $x = static fn (int $d): float => round(self::X0 + ($d - 1) * ($x1 - self::X0) / ($n - 1), 1);
        $camino = static function (array $s) use ($x, $max): string {
            $p = [];
            foreach ($s as $i => $v) {
                $p[] = ($i === 0 ? 'M' : 'L') . $x($i + 1) . ',' . self::y($v, $max);
            }
            return implode(' ', $p);
        };
        $aria = 'Venta acumulada de ' . $nombreA . ' contra ' . $nombreB . ': ' . $nombreA . ' ' . Tablero::pesos((float) (end($a) ?: 0)) . ', ' . $nombreB . ' ' . Tablero::pesos((float) (end($b) ?: 0));
        $o = '<svg class="g-svg" viewBox="0 0 ' . self::W . ' ' . self::H . '" role="img" aria-label="' . e($aria) . '">' . self::grilla($max, $paso, $x1);
        foreach ([1, (int) ceil($n / 4), (int) ceil($n / 2), (int) ceil(3 * $n / 4), $n] as $d) {
            $o .= '<text class="g-eje" x="' . $x($d) . '" y="' . (self::Y0 + 18) . '" text-anchor="middle">' . $d . '</text>';
        }
        if (count($b) > 0) {
            $o .= '<path class="g-linea g-comp" d="' . $camino($b) . '"/>';
        }
        if (count($a) > 0) {
            $o .= '<path class="g-area" d="' . $camino($a) . ' L' . $x(count($a)) . ',' . self::Y0 . ' L' . self::X0 . ',' . self::Y0 . ' Z"/>'
                . '<path class="g-linea g-marca" d="' . $camino($a) . '"/>';
        }
        // guía y puntos que mueve tablero.js
        $o .= '<line class="g-guia" x1="0" x2="0" y1="' . self::Y1 . '" y2="' . self::Y0 . '" hidden/>'
            . '<circle class="g-punto g-pb" r="4" cx="0" cy="0" hidden/><circle class="g-punto g-pa" r="4" cx="0" cy="0" hidden/>';
        $ya = $yb = null;
        if (count($b) > 0) {
            $yb = self::y((float) end($b), $max);
        }
        if (count($a) > 0) {
            $ya = self::y((float) end($a), $max);
            $xa = $x(count($a));
            $o .= '<circle class="g-fin" cx="' . $xa . '" cy="' . $ya . '" r="5"/>'
                . '<text class="g-rotulo" x="' . ($xa + 9) . '" y="' . ($ya - 9) . '">' . e(Tablero::corto((float) end($a))) . '</text>';
        }
        if ($yb !== null && ($ya === null || count($a) < $n - 3 || abs($ya - $yb) > 16)) {
            $o .= '<text class="g-eje g-ult" x="' . ($x1 + 6) . '" y="' . ($yb + 4) . '">' . e(Tablero::corto((float) end($b))) . '</text>';
        }
        $ancho = ($x1 - self::X0) / ($n - 1);
        for ($d = 1; $d <= $n; $d++) {
            $tip = 'Día ' . $d;
            $tip .= '|' . ucfirst($nombreA) . ': ' . (isset($a[$d - 1]) ? Tablero::pesos($a[$d - 1]) : 'todavía no');
            $tip .= '|' . ucfirst($nombreB) . ': ' . (isset($b[$d - 1]) ? Tablero::pesos($b[$d - 1]) : '—');
            $o .= '<rect class="g-zona" x="' . round($x($d) - $ancho / 2, 1) . '" y="0" width="' . round($ancho, 1) . '" height="' . (self::Y0 + 4) . '" data-x="' . $x($d) . '"'
                . ' data-ya="' . (isset($a[$d - 1]) ? self::y($a[$d - 1], $max) : '') . '" data-yb="' . (isset($b[$d - 1]) ? self::y($b[$d - 1], $max) : '') . '" data-tip="' . e($tip) . '"/>';
        }
        return $o . '</svg>';
    }

    /**
     * Columnas de venta por mes (el mes en curso, más claro: está incompleto).
     * @param array<int,array{mes:string,neto:float,parcial:bool}> $meses
     */
    public static function columnas(array $meses): string
    {
        $x1 = self::W - 16;
        $vals = array_map(static fn ($m) => (float) $m['neto'], $meses);
        [$max, $paso] = self::escala(max([0.0, ...$vals]));
        $banda = ($x1 - self::X0) / max(1, count($meses));
        $bw = min(24.0, $banda * 0.6);
        $mayor = $vals === [] ? -1 : array_search(max($vals), $vals, true);
        $partes = [];
        foreach ($meses as $i => $m) {
            $partes[] = Tablero::mesLargo($m['mes']) . ' ' . Tablero::pesos((float) $m['neto']);
        }
        $o = '<svg class="g-svg" viewBox="0 0 ' . self::W . ' ' . self::H . '" role="img" aria-label="' . e('Venta por mes: ' . implode('; ', $partes)) . '">' . self::grilla($max, $paso, $x1);
        foreach ($meses as $i => $m) {
            $v = (float) $m['neto'];
            $bx = round(self::X0 + $banda * $i + ($banda - $bw) / 2, 1);
            $cx = round($bx + $bw / 2, 1);
            if ($v > 0) {
                $y = self::y($v, $max);
                $r = min(4.0, (self::Y0 - $y) / 2);
                $d = 'M' . $bx . ',' . self::Y0 . ' V' . ($y + $r) . ' Q' . $bx . ',' . $y . ' ' . ($bx + $r) . ',' . $y . ' H' . ($bx + $bw - $r)
                    . ' Q' . ($bx + $bw) . ',' . $y . ' ' . ($bx + $bw) . ',' . ($y + $r) . ' V' . self::Y0 . ' Z';
                $o .= '<path class="g-col' . ($m['parcial'] ? ' g-parcial' : '') . '" data-i="' . $i . '" d="' . $d . '"/>';
                if ($i === $mayor || $i === count($meses) - 1) {
                    $o .= '<text class="g-rotulo" x="' . $cx . '" y="' . ($y - 7) . '" text-anchor="middle">' . e(Tablero::corto($v)) . '</text>';
                }
            }
            $o .= '<text class="g-eje" x="' . $cx . '" y="' . (self::Y0 + 18) . '" text-anchor="middle">' . e(Tablero::mesCorto($m['mes'])) . '</text>';
            $prev = $i > 0 ? (float) $meses[$i - 1]['neto'] : 0.0;
            $tip = ucfirst(Tablero::mesLargo($m['mes'])) . ($m['parcial'] ? ' (en curso)' : '') . '|' . Tablero::pesos($v)
                . ($prev > 0 ? '|' . self::signo(($v / $prev - 1) * 100) . ' vs. el mes anterior' : '');
            $o .= '<rect class="g-zona" x="' . round(self::X0 + $banda * $i, 1) . '" y="0" width="' . round($banda, 1) . '" height="' . (self::Y0 + 4) . '" data-i="' . $i . '" data-tip="' . e($tip) . '"/>';
        }
        return $o . '</svg>';
    }

    /** Línea chica de tendencia (los meses cerrados) con el último punto marcado. @param array<int,float> $v */
    public static function sparkline(array $v): string
    {
        $v = array_values($v);
        if (count($v) < 2) {
            return '';
        }
        $max = max([1.0, ...$v]);
        $p = [];
        $n = count($v);
        foreach ($v as $i => $x) {
            $p[] = ($i === 0 ? 'M' : 'L') . round(6 + $i * 228 / ($n - 1), 1) . ',' . round(58 - max(0.0, $x) * 50 / $max, 1);
        }
        $ux = round(6 + ($n - 1) * 228 / ($n - 1), 1);
        $uy = round(58 - max(0.0, $v[$n - 1]) * 50 / $max, 1);
        return '<svg class="g-spark" viewBox="0 0 240 64" aria-hidden="true"><path class="g-linea g-comp" d="' . implode(' ', $p) . '"/>'
            . '<circle class="g-fin" cx="' . $ux . '" cy="' . $uy . '" r="5"/></svg>';
    }

    public static function signo(float $pct): string
    {
        return ($pct >= 0 ? '+' : '−') . number_format(abs($pct), 1, ',', '.') . ' %';
    }
}
