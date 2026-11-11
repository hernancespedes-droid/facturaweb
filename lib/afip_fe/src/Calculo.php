<?php
namespace OysAfip;

/**
 * Cálculo de un comprobante a partir de sus renglones, igual que los facturadores de O&S
 * (Frm_FacturadorLibre / Frm_FacturadorArticulo / Frm_FacturacionRemitos):
 *   total del renglón = cantidad x precio; IVA del renglón = total x alícuota / 100;
 *   IVA agrupado por alícuota; percepciones (IIBB) = subtotal x alícuota / 100;
 *   total = subtotal + IVA + percepciones.
 * Diferencia con el exe: redondea cada renglón a 2 decimales (el exe no redondea), así los totales
 * cierran con lo que controla ARCA.
 *
 * Sirve para cualquier sistema (no depende de tablas).
 */
class CalculoComprobante
{
    public static function r2($v)
    {
        return round((float) $v + 0.0000001 * ((float) $v >= 0 ? 1 : -1), 2);
    }

    /**
     * @param array $items        [['cantidad'=>, 'precio'=>, 'alicuota'=>, ...otros datos que se conservan], ...]
     * @param array $percepciones [['descripcion'=>'IIBB Bs. As.', 'alicuota'=>1.5], ...]
     * @return array items (con total, iva, total_iva), alicuotas [alic => [base, importe]], subtotal, iva,
     *               gravado, exento, percepciones (con base e importe), tributos, total
     */
    public static function calcular(array $items, array $percepciones = array())
    {
        $out = array('items' => array(), 'alicuotas' => array(), 'subtotal' => 0.0, 'iva' => 0.0,
            'gravado' => 0.0, 'exento' => 0.0, 'percepciones' => array(), 'tributos' => 0.0, 'total' => 0.0);
        foreach ($items as $it) {
            $alic = (float) $it['alicuota'];
            $tot = self::r2((float) $it['cantidad'] * (float) $it['precio']);
            $iva = self::r2($tot * $alic / 100);
            $it['total'] = $tot;
            $it['iva'] = $iva;
            $it['total_iva'] = self::r2($tot + $iva);
            $out['items'][] = $it;
            $k = rtrim(rtrim(number_format($alic, 2, '.', ''), '0'), '.');
            if (!isset($out['alicuotas'][$k])) {
                $out['alicuotas'][$k] = array('alicuota' => $alic, 'base' => 0.0, 'importe' => 0.0);
            }
            $out['alicuotas'][$k]['base'] = self::r2($out['alicuotas'][$k]['base'] + $tot);
            $out['alicuotas'][$k]['importe'] = self::r2($out['alicuotas'][$k]['importe'] + $iva);
            $out['subtotal'] = self::r2($out['subtotal'] + $tot);
            $out['iva'] = self::r2($out['iva'] + $iva);
        }
        // Como el exe: si no hay IVA, todo el subtotal es exento; si hay, todo es gravado
        if ($out['iva'] == 0) {
            $out['exento'] = $out['subtotal'];
        } else {
            $out['gravado'] = $out['subtotal'];
        }
        foreach ($percepciones as $p) {
            $imp = self::r2($out['subtotal'] * (float) $p['alicuota'] / 100);
            if ($imp == 0) {
                continue;
            }
            $p['base'] = $out['subtotal'];
            $p['importe'] = $imp;
            $out['percepciones'][] = $p;
            $out['tributos'] = self::r2($out['tributos'] + $imp);
        }
        $out['total'] = self::r2($out['subtotal'] + $out['iva'] + $out['tributos']);
        return $out;
    }
}
