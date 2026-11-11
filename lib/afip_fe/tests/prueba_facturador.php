<?php
/**
 * Prueba del Facturador genérico y de la impresión (FacturaHtml), con ARCA simulado. Uso: php prueba_facturador.php
 */
require __DIR__ . '/../afip.php';

use OysAfip\Afip;
use OysAfip\Facturador;
use OysAfip\FacturaHtml;
use OysAfip\Soap;
use OysAfip\AfipException;

$fallas = 0;
function ok($cond, $txt) { global $fallas; echo ($cond ? '  OK   ' : '  FALLA') . ' ' . $txt . "\n"; if (!$cond) $fallas++; }

$tmp = __DIR__ . '/tmp_fac';
@mkdir($tmp);
@mkdir("$tmp/ta");
array_map('unlink', glob("$tmp/ta/*") ?: array());
// certificado de prueba
$k = openssl_pkey_new(array('private_key_bits' => 2048));
$csr = openssl_csr_new(array('commonName' => 'prueba', 'serialNumber' => 'CUIT 30714357537'), $k);
$crt = openssl_csr_sign($csr, null, $k, 30);
openssl_x509_export_to_file($crt, "$tmp/c.crt");
openssl_pkey_export_to_file($k, "$tmp/c.key");

// ARCA simulado: guarda el último pedido
$GLOBALS['ultimo_xml'] = ''; $GLOBALS['ult'] = array();
Soap::$simulador = function ($url, $accion, $xml) {
    $env = function ($c) { return '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>' . $c . '</soap:Body></soap:Envelope>'; };
    $d = new DOMDocument(); $d->loadXML($xml);
    $v = function ($n) use ($d) { $l = $d->getElementsByTagName($n); return $l->length ? $l->item(0)->textContent : ''; };
    if (strpos($url, 'wsaa') !== false) {
        $ta = '<?xml version="1.0" encoding="UTF-8"?><loginTicketResponse version="1.0"><header><expirationTime>' . date('c', time() + 43200)
            . '</expirationTime></header><credentials><token>T</token><sign>S</sign></credentials></loginTicketResponse>';
        return $env('<loginCmsResponse xmlns="http://wsaa.view.sua.dvadac.desein.afip.gov"><loginCmsReturn>' . htmlspecialchars($ta) . '</loginCmsReturn></loginCmsResponse>');
    }
    $m = substr($accion, strrpos($accion, '/') + 1);
    $key = $v('PtoVta') . '_' . $v('CbteTipo');
    $ult = isset($GLOBALS['ult'][$key]) ? $GLOBALS['ult'][$key] : 0;
    if ($m === 'FECompUltimoAutorizado')
        return $env("<FECompUltimoAutorizadoResponse xmlns=\"http://ar.gov.afip.dif.FEV1/\"><FECompUltimoAutorizadoResult><PtoVta>{$v('PtoVta')}</PtoVta><CbteTipo>{$v('CbteTipo')}</CbteTipo><CbteNro>$ult</CbteNro></FECompUltimoAutorizadoResult></FECompUltimoAutorizadoResponse>");
    if ($m === 'FECAESolicitar') {
        $GLOBALS['ultimo_xml'] = $xml;
        $nro = (int) $v('CbteDesde');
        $GLOBALS['ult'][$key] = $nro;
        return $env('<FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/"><FECAESolicitarResult><FeCabResp><Resultado>A</Resultado></FeCabResp><FeDetResp><FECAEDetResponse><CbteDesde>'
            . $nro . '</CbteDesde><CbteHasta>' . $nro . '</CbteHasta><CbteFch>' . $v('CbteFch') . '</CbteFch><Resultado>A</Resultado><CAE>76123456789012</CAE><CAEFchVto>'
            . date('Ymd', strtotime('+10 days')) . '</CAEFchVto></FECAEDetResponse></FeDetResp></FECAESolicitarResult></FECAESolicitarResponse>');
    }
    throw new Exception('no simulado ' . $m);
};
$cfg = array('cuit' => '30714357537', 'produccion' => false, 'certificado' => "$tmp/c.crt", 'clave_privada' => "$tmp/c.key", 'carpeta_ta' => "$tmp/ta");
$afip = new Afip($cfg);
$x = function ($tag) { $d = new DOMDocument(); $d->loadXML($GLOBALS['ultimo_xml']); $l = $d->getElementsByTagNameNS('*', $tag); return $l->length ? $l->item(0)->textContent : ''; };

echo "1. Factura A (RI a RI): 21 %, 10,5 %, exento y percepción\n";
$f = new Facturador($afip);
$r = $f->emitir(array('tipo' => 'FC', 'pto_vta' => 5, 'receptor' => array('documento' => '30-71566061-6', 'condicion_iva' => 1),
    'items' => array(array('descripcion' => 'Cartón', 'cantidad' => 2.5, 'precio' => 1000, 'alicuota' => 21),
                     array('descripcion' => 'Flete', 'cantidad' => 1, 'precio' => 200, 'alicuota' => 10.5),
                     array('descripcion' => 'Seguro', 'cantidad' => 1, 'precio' => 50, 'exento' => true)),
    'percepciones' => array(array('descripcion' => 'Percepción IIBB', 'alicuota' => 1.5))));
ok($r['aprobado'] && $r['numero'] == 1, 'aprobada con el número 1');
ok($x('CbteTipo') === '1', 'tipo 1 (FC A)');
ok($x('ImpNeto') === '2700.00' && $x('ImpOpEx') === '50.00', 'neto 2700 y exento 50: ' . $x('ImpNeto') . ' / ' . $x('ImpOpEx'));
ok($x('ImpIVA') === '546.00', 'IVA 525 + 21 = 546: ' . $x('ImpIVA'));
ok($x('ImpTrib') === '41.25', 'percepción 1,5 % sobre 2750 = 41,25: ' . $x('ImpTrib'));
ok($x('ImpTotal') === '3337.25', 'total 3337,25: ' . $x('ImpTotal'));
ok($x('CondicionIVAReceptorId') === '1', 'condición IVA receptor 1');

echo "2. Letras automáticas\n";
ok(Facturador::letra('RI', 6) === 'A' && Facturador::letra('RI', 5) === 'B' && Facturador::letra('MT', 1) === 'C', 'RI→A a monotributo, B a consumidor final; monotributo→C');
$r = $f->emitir(array('tipo' => 'FC', 'pto_vta' => 5, 'receptor' => array('documento' => '', 'condicion_iva' => 5),
    'items' => array(array('descripcion' => 'Venta', 'cantidad' => 1, 'precio' => 100, 'alicuota' => 21))));
ok($x('CbteTipo') === '6' && $x('DocTipo') === '99' && $x('ImpTotal') === '121.00', 'FC B a consumidor final sin documento: tipo 6, doc 99, total 121');

echo "3. Monotributista (letra C)\n";
$fc = new Facturador($afip, null, array('condicion_emisor' => 'MT'));
$r = $fc->emitir(array('tipo' => 'FC', 'pto_vta' => 2, 'receptor' => array('documento' => '20123456786', 'condicion_iva' => 1),
    'items' => array(array('descripcion' => 'Servicio', 'cantidad' => 3, 'precio' => 100, 'alicuota' => 0))));
ok($x('CbteTipo') === '11' && $x('ImpNeto') === '300.00' && $x('ImpIVA') === '0.00' && $x('ImpTotal') === '300.00', 'FC C: neto = total 300, sin IVA');

echo "4. Nota de crédito\n";
try { $f->emitir(array('tipo' => 'NC', 'pto_vta' => 5, 'receptor' => array('documento' => '30715660616', 'condicion_iva' => 1), 'items' => array(array('descripcion' => 'x', 'cantidad' => 1, 'precio' => 1, 'alicuota' => 21)))); ok(false, 'NC sin asociado tendría que fallar'); }
catch (AfipException $e) { ok(true, 'NC sin asociado: ' . $e->getMessage()); }
$r = $f->emitir(array('tipo' => 'NC', 'pto_vta' => 5, 'receptor' => array('documento' => '30715660616', 'condicion_iva' => 1),
    'items' => array(array('descripcion' => 'Bonificación', 'cantidad' => 1, 'precio' => 100, 'alicuota' => 21)),
    'asociado' => array('tipo_afip' => 1, 'numero' => 1, 'fecha' => date('Y-m-d'))));
ok($x('CbteTipo') === '3' && strpos($GLOBALS['ultimo_xml'], 'CbtesAsoc') !== false, 'NC A con comprobante asociado');

echo "5. FCE MiPyMEs\n";
$ff = new Facturador($afip, null, array('cbu' => '0170000000000000000001'));
$r = $ff->emitir(array('tipo' => 'FC', 'mipyme' => true, 'pto_vta' => 5, 'receptor' => array('documento' => '30715660616', 'condicion_iva' => 1),
    'items' => array(array('descripcion' => 'Chatarra', 'cantidad' => 10, 'precio' => 1000, 'alicuota' => 21))));
ok($x('CbteTipo') === '201' && $x('FchVtoPago') === date('Ymd', strtotime('+30 days')), 'FCE A con vencimiento a 30 días');
ok(strpos($GLOBALS['ultimo_xml'], '2101') !== false && strpos($GLOBALS['ultimo_xml'], 'SCA') !== false, 'opcionales 2101 (CBU) y 27 (SCA)');

echo "6. Impresión\n";
$c = $r['comprobante'];
$html = FacturaHtml::render(array(
    'emisor' => array('nombre' => 'RECICLADOS DEL OESTE SA', 'cuit' => '30714357537', 'legales' => array('Emilio Mitre 1595', 'CABA'), 'iibb' => '901-706880-7', 'condicion_iva' => 'IVA Responsable Inscripto', 'inicio' => '22/01/2014'),
    'comprobante' => array('tipo_afip' => $c->tipo, 'letra' => 'A', 'pto_vta' => $c->ptoVta, 'numero' => $r['numero'], 'fecha' => $c->fecha, 'cae' => $r['cae'], 'vto_cae' => $r['vence'],
                           'mipyme_cbu' => '0170000000000000000001', 'mipyme_vto' => $c->vtoPago),
    'receptor' => array('razon_social' => 'CLIENTE SRL', 'codigo' => 30, 'domicilio' => 'Calle 1', 'localidad' => 'CABA', 'cuit' => '30715660616', 'condicion_iva' => 'Responsable Inscripto'),
    'items' => array(array('descripcion' => 'Chatarra', 'cantidad' => 10, 'precio' => 1000, 'importe' => 10000)),
    'totales' => array('subtotal' => 10000, 'iva' => array('21' => 2100), 'tributos' => array(), 'total' => 12100),
    'pie' => 'Generado por O&S', 'marca' => 'Prueba'));
ok(strpos($html, 'FACTURA DE CREDITO ELECTRONICA MiPyMEs') !== false && strpos($html, '0005-00000001') !== false, 'título y número');
ok(strpos($html, '76123456789012') !== false && strpos($html, '12.100,00') !== false, 'CAE y total');
ok(strpos($html, 'qrcode(0') !== false && strpos($html, 'afip.gob.ar/fe/qr/?p=') !== false, 'QR de ARCA con qrcode.js incluido');
file_put_contents("$tmp/factura.html", $html);

echo $fallas ? "\n$fallas FALLAS\n" : "\nTodas las pruebas OK\n";
exit($fallas ? 1 : 0);
