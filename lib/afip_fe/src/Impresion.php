<?php
namespace OysAfip;

/**
 * Comprobante electrónico imprimible (HTML A4, "Imprimir / Guardar PDF" del navegador), con el diseño de la
 * factura de O&S (Report3QR): logo y datos legales del emisor, letra y código, datos del cliente, ítems,
 * totales con IVA y percepciones, CAE, vencimiento del CAE y QR de ARCA (RG 4892).
 *
 * No depende de ninguna base: el sistema arma el array y llama a FacturaHtml::render($d).
 * El QR se dibuja en el navegador con qrcode-generator 1.4.4 (MIT), que va incluido en assets/qrcode.js
 * y se inserta dentro de la página (la carpeta del módulo puede no ser pública).
 *
 * $d = array(
 *   'emisor'      => array('nombre', 'logo' (URL o data:), 'legales' => array(líneas), 'cuit', 'iibb', 'inicio', 'condicion_iva'),
 *   'comprobante' => array('tipo_afip', 'letra', 'titulo' (opcional), 'pto_vta', 'numero', 'fecha' (Y-m-d),
 *                          'cae', 'vto_cae' (Y-m-d o Ymd), 'condicion_pago' (opcional),
 *                          'mipyme_cbu' (FCE), 'mipyme_vto' (Y-m-d, FCE), 'leyenda' (opcional)),
 *   'receptor'    => array('razon_social', 'codigo', 'domicilio', 'localidad', 'cuit' (o DNI), 'condicion_iva'),
 *   'items'       => array(array('descripcion', 'cantidad', 'precio', 'importe'), ...),
 *   'totales'     => array('subtotal', 'iva' => array('21' => importe, '10.5' => importe), 'tributos' => array('II.BB. Bs. As.' => importe), 'total'),
 *   'observaciones' => texto, 'pie' => texto (línea inferior), 'copia' => 'ORIGINAL' | 'DUPLICADO', 'marca' => texto chico (p. ej. "Impreso desde la web"),
 * )
 * Para las letras B y C no se discrimina el IVA (subtotal con IVA incluido), como el reporte del sistema.
 */
class FacturaHtml
{
    public static function titulo($tipoAfip)
    {
        $t = (int) $tipoAfip;
        $nc = array(3, 8, 13, 53, 203, 208, 213);
        $nd = array(2, 7, 12, 52, 202, 207, 212);
        $fce = $t >= 201 && $t <= 213;
        if (in_array($t, $nc, true)) return $fce ? 'NOTA DE CREDITO MiPyMEs' : 'NOTA DE CREDITO';
        if (in_array($t, $nd, true)) return $fce ? 'NOTA DE DEBITO MiPyMEs' : 'NOTA DE DEBITO';
        return $fce ? 'FACTURA DE CREDITO ELECTRONICA MiPyMEs' : 'FACTURA';
    }

    public static function cuit($v)
    {
        $s = preg_replace('/\D/', '', (string) $v);
        return strlen($s) === 11 ? substr($s, 0, 2) . '-' . substr($s, 2, 8) . '-' . substr($s, 10) : $s;
    }

    private static function f($v) { return number_format((float) $v, 2, ',', '.'); }
    private static function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
    private static function fecha($v)
    {
        $v = (string) $v;
        if ($v === '') return '';
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $v, $m)) return $m[3] . '/' . $m[2] . '/' . $m[1];
        return date('d/m/Y', strtotime($v));
    }

    /** URL del QR de ARCA para estos datos */
    public static function urlQr(array $d)
    {
        $c = $d['comprobante']; $r = $d['receptor'];
        $doc = preg_replace('/\D/', '', (string) (isset($r['cuit']) ? $r['cuit'] : ''));
        return QrAfip::url($d['emisor']['cuit'], array(
            'fecha' => $c['fecha'], 'ptoVta' => $c['pto_vta'], 'tipo' => $c['tipo_afip'], 'numero' => $c['numero'],
            'importe' => $d['totales']['total'], 'docTipo' => strlen($doc) === 11 ? 80 : ($doc !== '' && (int) $doc > 0 ? 96 : 99),
            'docNro' => $doc !== '' ? $doc : '0', 'cae' => $c['cae'],
        ));
    }

    public static function render(array $d)
    {
        $e = $d['emisor']; $c = $d['comprobante']; $r = $d['receptor']; $t = $d['totales'];
        $h = function ($s) { return FacturaHtml::hh($s); };
        $letra = strtoupper((string) $c['letra']);
        $discrimina = in_array($letra, array('A', 'M'), true);
        $titulo = isset($c['titulo']) && $c['titulo'] !== '' ? $c['titulo'] : self::titulo($c['tipo_afip']);
        $nro = str_pad((string) (int) $c['pto_vta'], 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) $c['numero'], 8, '0', STR_PAD_LEFT);
        $mipyme = (int) $c['tipo_afip'] >= 201;
        $qr = self::urlQr($d);
        $js = @file_get_contents(dirname(__DIR__) . '/assets/qrcode.js');

        $o = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
           . '<meta name="robots" content="noindex, nofollow"><title>' . $h($titulo . ' ' . $nro) . '</title><style>' . self::css() . '</style></head><body>'
           . '<div class="acciones"><button onclick="window.print()">Imprimir / Guardar PDF</button> <a href="javascript:window.close()">Cerrar</a></div><div class="hoja">';
        // cabecera
        $o .= '<div class="cab"><div class="emisor">'
            . (!empty($e['logo']) ? '<img src="' . $h($e['logo']) . '" alt="" class="logo">' : '<div class="logo-txt">' . $h(isset($e['nombre']) ? $e['nombre'] : '') . '</div>')
            . '<div class="legales">';
        foreach ((array) (isset($e['legales']) ? $e['legales'] : array()) as $l) if (trim((string) $l) !== '') $o .= '<div>' . $h($l) . '</div>';
        $o .= '</div></div><div class="letra-col"><div class="letra"><b>' . $h($letra) . '</b><span>Codigo ' . (int) $c['tipo_afip'] . '</span></div></div>'
            . '<div class="datos"><div class="titulo">' . $h($titulo) . '</div><div class="titulo">Nº ' . $h($nro) . '</div><div class="titulo">Fecha: ' . $h(self::fecha($c['fecha'])) . '</div>'
            . '<div>C.U.I.T.: ' . $h(self::cuit($e['cuit'])) . '</div>'
            . (!empty($e['iibb']) ? '<div>Ingresos Brutos: ' . $h($e['iibb']) . '</div>' : '')
            . (!empty($e['condicion_iva']) ? '<div class="sep">' . $h($e['condicion_iva']) . '</div>' : '')
            . (!empty($e['inicio']) ? '<div>Inicio de actividades: ' . $h($e['inicio']) . '</div>' : '') . '</div></div>';
        // cliente
        $o .= '<div class="cliente"><div class="fila"><span class="et">Razón social</span><span class="v">' . $h($r['razon_social'] . (isset($r['codigo']) && $r['codigo'] !== '' ? ' - ( ' . $r['codigo'] . ' )' : ''))
            . '</span><span class="c2">IVA: ' . $h(isset($r['condicion_iva']) ? $r['condicion_iva'] : '') . '</span></div>'
            . '<div class="fila"><span class="et">Domicilio</span><span class="v">' . $h(isset($r['domicilio']) ? $r['domicilio'] : '') . '</span><span class="c2">CUIT: ' . $h(self::cuit(isset($r['cuit']) ? $r['cuit'] : '')) . '</span></div>'
            . '<div class="fila"><span class="et"></span><span class="v">' . $h(isset($r['localidad']) ? $r['localidad'] : '') . '</span><span class="c2">'
            . (!empty($c['condicion_pago']) ? 'Cond. de pago: ' . $h($c['condicion_pago']) : '') . '</span></div>';
        if ($mipyme && (!empty($c['mipyme_vto']) || !empty($c['mipyme_cbu']))) $o .= '<div class="vto">Fecha Vto. pago: ' . $h(self::fecha(isset($c['mipyme_vto']) ? $c['mipyme_vto'] : '')) . ' &nbsp; CBU: ' . $h(isset($c['mipyme_cbu']) ? $c['mipyme_cbu'] : '') . '</div>';
        $o .= '</div>';
        // ítems
        $o .= '<div class="cuerpo"><table class="items"><thead><tr><th class="d">Descripción</th><th>Cantidad</th><th>Precio</th><th>Importe</th></tr></thead><tbody>';
        foreach ((array) $d['items'] as $it)
            $o .= '<tr><td class="d">' . $h($it['descripcion']) . '</td><td>' . self::f($it['cantidad']) . '</td><td>' . self::f($it['precio']) . '</td><td>' . self::f($it['importe']) . '</td></tr>';
        if (empty($d['items'])) $o .= '<tr><td class="d" colspan="4">(sin detalle)</td></tr>';
        $o .= '</tbody></table>' . (!empty($d['observaciones']) ? '<div class="nota-pie">' . $h($d['observaciones']) . '</div>' : '') . '</div>';
        // totales
        $iva = (array) (isset($t['iva']) ? $t['iva'] : array()); $trib = (array) (isset($t['tributos']) ? $t['tributos'] : array());
        $sub = $discrimina ? (float) $t['subtotal'] : (float) $t['subtotal'] + array_sum($iva);
        $o .= '<table class="pie-tot"><tr><th>Subtotal</th>';
        if ($discrimina) foreach ($iva as $a => $imp) $o .= '<th>IVA ' . $h(rtrim(rtrim(number_format((float) $a, 2, '.', ''), '0'), '.')) . ' %</th>';
        foreach ($trib as $n => $imp) $o .= '<th>' . $h($n) . '</th>';
        $o .= '<th class="tg">Total</th></tr><tr><td>' . self::f($sub) . '</td>';
        if ($discrimina) foreach ($iva as $imp) $o .= '<td>' . self::f($imp) . '</td>';
        foreach ($trib as $imp) $o .= '<td>' . self::f($imp) . '</td>';
        $o .= '<td class="tg">' . self::f($t['total']) . '</td></tr></table>';
        // CAE + QR
        $o .= '<div class="final"><div id="qr" class="qr"></div><div class="der"><div class="cae">CAE: ' . $h($c['cae']) . ' &nbsp;&nbsp; Vto. CAE: ' . $h(self::fecha($c['vto_cae'])) . '</div>';
        if ($mipyme) $o .= '<div class="leyenda">Luego de su aceptación tácita o expresa, esta Factura de Crédito Electrónica MiPyMEs será transmitida al Sistema de Circulación Abierta para Facturas de Crédito Electrónicas MiPyMEs, para su circulación y negociación, incluso en los Mercados de Valores.</div>';
        if (!empty($c['leyenda'])) $o .= '<div class="leyenda">' . $h($c['leyenda']) . '</div>';
        $o .= '<div class="original">' . $h(implode(' ', str_split(isset($d['copia']) ? $d['copia'] : 'ORIGINAL'))) . '</div>'
            . '<div class="ult"><span>' . $h(isset($d['pie']) ? $d['pie'] : '') . '</span><b>Comprobante Autorizado ARCA</b></div></div></div>';
        if (!empty($d['marca'])) $o .= '<div class="reimp">' . $h($d['marca']) . '</div>';
        $o .= '</div>';
        if ($js) {
            $o .= '<script>' . $js . '</script><script>try{var q=qrcode(0,"M");q.addData(' . json_encode($qr, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ');q.make();document.getElementById("qr").innerHTML=q.createSvgTag({cellSize:2,margin:0,scalable:true});}catch(e){document.getElementById("qr").innerHTML=' . json_encode('<a href="' . self::hh($qr) . '">QR ARCA</a>') . ';}</script>';
        } else {
            $o .= '<script>document.getElementById("qr").innerHTML=' . json_encode('<a href="' . self::hh($qr) . '">QR ARCA</a>') . ';</script>';
        }
        return $o . '</body></html>';
    }

    public static function hh($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

    public static function css()
    {
        return '@page{size:A4;margin:10mm}*{box-sizing:border-box}body{margin:0;background:#e9e9e9;font:10pt Arial,Helvetica,sans-serif;color:#000}'
            . '.acciones{text-align:center;padding:10px;font-family:system-ui,sans-serif}.acciones button,.acciones a{font:inherit;padding:6px 14px;margin:0 4px}'
            . '.hoja{width:190mm;min-height:277mm;margin:0 auto 20px;background:#fff;padding:6mm;position:relative;box-shadow:0 1px 6px rgba(0,0,0,.25);display:flex;flex-direction:column}'
            . '.cab{display:flex;border:1px solid #000;min-height:42mm}.emisor{flex:1;padding:4mm}.logo{max-width:70mm;max-height:22mm}.logo-txt{font-size:16pt;font-weight:bold}'
            . '.legales{margin-top:2mm;font-size:9pt}.letra-col{width:22mm;display:flex;justify-content:center;border-left:1px solid #000;border-right:1px solid #000}'
            . '.letra{border:1px solid #000;border-top:0;width:18mm;height:18mm;text-align:center;background:#fff}.letra b{display:block;font-size:26pt;line-height:12mm}.letra span{font-size:7pt}'
            . '.datos{flex:1;padding:4mm;font-size:9.5pt}.titulo{font-weight:bold;font-size:11pt}.sep{margin-top:1.5mm}'
            . '.cliente{border:1px solid #000;border-top:0;padding:2mm 4mm}.fila{display:flex;gap:4mm;margin:1mm 0}.et{width:24mm;font-weight:bold}.v{flex:1}.c2{width:62mm}.vto{margin-top:1mm;font-weight:bold}'
            . '.cuerpo{border:1px solid #000;border-top:0;flex:1;padding:2mm}.items{width:100%;border-collapse:collapse}.items th{border-bottom:1px solid #000;text-align:right;padding:1mm}'
            . '.items td{text-align:right;padding:1mm}.items .d{text-align:left}.nota-pie{margin-top:3mm;font-size:9pt}'
            . '.pie-tot{width:100%;border-collapse:collapse;border:1px solid #000;border-top:0}.pie-tot th,.pie-tot td{border:1px solid #000;padding:1.5mm;text-align:right}.pie-tot .tg{font-weight:bold}'
            . '.final{display:flex;gap:4mm;border:1px solid #000;border-top:0;padding:3mm}.qr{width:32mm;height:32mm}.qr svg{width:100%;height:100%}.der{flex:1}.cae{font-weight:bold;font-size:11pt}'
            . '.leyenda{font-size:7.5pt;margin-top:2mm}.original{text-align:center;font-weight:bold;margin-top:3mm;letter-spacing:1px}.ult{display:flex;justify-content:space-between;font-size:7.5pt;margin-top:3mm}'
            . '.reimp{position:absolute;bottom:1mm;right:6mm;font-size:7pt;color:#666}'
            . '@media print{body{background:#fff}.acciones{display:none}.hoja{margin:0;box-shadow:none;width:auto;min-height:270mm}}';
    }
}
