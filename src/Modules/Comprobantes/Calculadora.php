<?php
declare(strict_types=1);

namespace App\Modules\Comprobantes;

/**
 * Cálculo de un comprobante a partir de sus renglones (lógica pura, sin base de datos).
 *
 * El precio de cada renglón es el que se ve en el comprobante: en la A va neto de IVA; en la B y en la C va con el IVA
 * incluido (en la C, de todos modos, no se discrimina IVA). Reglas, iguales a las que controla ARCA:
 *  - importe del renglón = cantidad x precio, redondeado a 2 decimales;
 *  - el descuento general (%) se aplica a cada renglón y se redondea a 2 decimales;
 *  - se agrupa por alícuota: en la A, base = suma de netos e IVA = base x alícuota (redondeado); en la B, el IVA de cada
 *    grupo sale del total con IVA incluido (neto = total / (1 + alícuota), IVA = total - neto), así el total del
 *    comprobante es exactamente la suma de lo que se ve en los renglones;
 *  - DECISIÓN D-07 (Claude, a confirmar por Hernán): un renglón con alícuota 0 es exento (neto_exento; no lleva fila de IVA), como en
 *    el VFP. ARCA distingue "exento" de "gravado al 0 %" (IVA Id 3): si hace falta lo segundo hay que agregarlo acá;
 *  - DECISIÓN D-03 (Claude, a confirmar por Hernán): solo hay descuento general (%); el VFP también tenía descuento por renglón
 *    (porcentaje_descuento en cada item), que los renglones de OYS no usan. Para sumarlo, aplicar el % de cada renglón antes
 *    del general en el bucle de abajo;
 *  - DECISIÓN D-08 (Claude, a confirmar por Hernán): en la B y C el IVA se calcula por grupo de alícuota (total / (1 + alícuota)), no
 *    renglón por renglón, así el total del comprobante es exactamente la suma de lo que se ve;
 *  - total = neto gravado + neto exento + IVA.
 */
final class Calculadora
{
    public const MAX_IMPORTE = 99999999999.99;

    public static function r2(float $v): float
    {
        return round($v + ($v >= 0 ? 1 : -1) * 1e-9, 2);
    }

    /**
     * @param string $letra A, B o C
     * @param array<int,array{cantidad:float|int|string,precio:float|int|string,alicuota:float|int|string}> $items
     * @return array{
     *   items:array<int,array<string,mixed>>, iva:array<string,array{alicuota:float,base:float,importe:float}>,
     *   subtotal:float, descuento_importe:float, bruto:float, bonificacion:float, neto_grabado:float, neto_exento:float, iva_total:float,
     *   total:float, descuento:float
     * }
     */
    public static function calcular(string $letra, array $items, float $descuento = 0.0): array
    {
        $descuento = max(0.0, min(100.0, $descuento));
        $factor = 1 - $descuento / 100;
        $porLetraC = $letra === 'C';
        $detalle = [];
        // grupos por alícuota: total mostrado antes y después del descuento
        $grupos = [];
        foreach ($items as $i => $it) {
            $cant = (float) $it['cantidad'];
            $precio = (float) $it['precio'];
            $alic = $porLetraC ? 0.0 : (float) $it['alicuota'];
            $importe = self::r2($cant * $precio);
            $conDesc = self::r2($importe * $factor);
            $k = self::clave($alic);
            $grupos[$k]['alicuota'] = $alic;
            $grupos[$k]['antes'] = self::r2(($grupos[$k]['antes'] ?? 0.0) + $importe);
            $grupos[$k]['despues'] = self::r2(($grupos[$k]['despues'] ?? 0.0) + $conDesc);
            $detalle[$i] = $it + ['importe' => $importe, 'alicuota' => $alic, 'despues' => $conDesc];
        }
        ksort($grupos, SORT_NUMERIC);

        $iva = [];
        $bruto = $netoDespues = $netoExento = $ivaTotal = 0.0;
        foreach ($grupos as $k => $g) {
            $alic = $g['alicuota'];
            if ($letra === 'A') {
                $netoAntes = $g['antes'];
                $neto = $g['despues'];
                $imp = $alic > 0 ? self::r2($neto * $alic / 100) : 0.0;
            } else { // B y C: el precio ya trae el IVA
                $netoAntes = $alic > 0 ? self::r2($g['antes'] / (1 + $alic / 100)) : $g['antes'];
                $neto = $alic > 0 ? self::r2($g['despues'] / (1 + $alic / 100)) : $g['despues'];
                $imp = $alic > 0 ? self::r2($g['despues'] - $neto) : 0.0;
            }
            $bruto = self::r2($bruto + $netoAntes);
            if ($porLetraC) { // C: todo es neto gravado, sin IVA discriminado
                $netoDespues = self::r2($netoDespues + $neto);
            } elseif ($alic > 0) {
                $netoDespues = self::r2($netoDespues + $neto);
                $iva[(string) $k] = ['alicuota' => $alic, 'base' => $neto, 'importe' => $imp];
                $ivaTotal = self::r2($ivaTotal + $imp);
            } else {
                $netoExento = self::r2($netoExento + $neto);
            }
        }
        $total = self::r2($netoDespues + $netoExento + $ivaTotal);

        // neto sin IVA de cada renglón, ya con el descuento (informativo: lo oficial son los grupos de IVA)
        foreach ($detalle as $i => $d) {
            $a = $d['alicuota'];
            $netoLinea = $letra === 'A' || $a <= 0 ? $d['despues'] : self::r2($d['despues'] / (1 + $a / 100));
            $cant = (float) $d['cantidad'];
            $detalle[$i]['neto'] = $netoLinea;
            $detalle[$i]['precio_siniva'] = $cant > 0 ? round($netoLinea / $cant, 4) : 0.0;
            $detalle[$i]['exento'] = $a <= 0 && !$porLetraC;
        }
        $subtotal = $despues = 0.0;
        foreach ($detalle as $d) {
            $subtotal = self::r2($subtotal + $d['importe']);
            $despues = self::r2($despues + $d['despues']);
        }
        return [
            'items' => array_values($detalle),
            'subtotal' => $subtotal,                              // suma de los importes como se ven (en la A, netos; en la B y C, con IVA)
            'descuento_importe' => self::r2($subtotal - $despues), // el descuento como se ve (en la B y C, con IVA)
            'iva' => $iva,
            'bruto' => $bruto,
            'bonificacion' => self::r2($bruto - $netoDespues - $netoExento),
            'neto_grabado' => $netoDespues,
            'neto_exento' => $netoExento,
            'iva_total' => $ivaTotal,
            'total' => $total,
            'descuento' => $descuento,
        ];
    }

    /** Clave numérica de una alícuota para agrupar (10.5 y 10.50 son la misma). */
    private static function clave(float $alic): string
    {
        return rtrim(rtrim(number_format($alic, 2, '.', ''), '0'), '.') ?: '0';
    }
}
