<?php
/**
 * Prueba sin conexión: firma real con un certificado de prueba, ARCA simulado y la base de prueba (MariaDB)
 * cargada con los CSV de Reciclado. Uso: php prueba.php
 */
require __DIR__ . '/../afip.php';
require __DIR__ . '/../adaptadores/OysSubdiario.php';

use OysAfip\Afip;
use OysAfip\Comprobante;
use OysAfip\OysSubdiario;
use OysAfip\Soap;
use OysAfip\AfipException;
use OysAfip\AfipConexionException;

$fallas = 0;
function ok($cond, $txt)
{
    global $fallas;
    echo ($cond ? '  OK   ' : '  FALLA') . ' ' . $txt . "\n";
    if (!$cond) {
        $fallas++;
    }
}

$tmp = __DIR__ . '/tmp';
@mkdir($tmp);
array_map("unlink", array_merge(glob("$tmp/ta/*") ?: array(), array_filter(glob("$tmp/*"), "is_file")));

// ---------------- 1. Certificado de prueba y firma CMS
echo "1. Firma del pedido de acceso (WSAA)\n";
exec("openssl req -x509 -newkey rsa:2048 -nodes -keyout $tmp/e.key -out $tmp/e.crt -days 30 -subj '/CN=prueba/serialNumber=CUIT 30714357537' 2>&1");
exec("openssl pkcs12 -export -in $tmp/e.crt -inkey $tmp/e.key -out $tmp/e.pfx -passout pass:clave 2>&1");
$cfg = array('cuit' => '30-71435753-7', 'produccion' => false, 'certificado' => "$tmp/e.crt", 'clave_privada' => "$tmp/e.key",
    'carpeta_ta' => "$tmp/ta", 'carpeta_temp' => $tmp);
$afip = new Afip($cfg);
$tra = '<?xml version="1.0" encoding="UTF-8"?><loginTicketRequest version="1.0"><header><uniqueId>1</uniqueId></header><service>wsfe</service></loginTicketRequest>';
$cms = $afip->wsaa->firmar($tra);
file_put_contents("$tmp/cms.der", base64_decode($cms));
exec("openssl cms -verify -noverify -inform DER -in $tmp/cms.der 2>/dev/null", $salida);
ok(implode('', $salida) === $tra, 'la firma CMS contiene el TRA y verifica con openssl');
$afipPfx = new Afip(array('cuit' => '30714357537', 'pfx' => "$tmp/e.pfx", 'frase' => 'clave', 'carpeta_ta' => "$tmp/ta"));
ok(strlen($afipPfx->wsaa->firmar($tra)) > 500, 'también firma con el .pfx (como lo usa hoy el sistema)');
ok($afip->wsaa->venceCertificado() > time(), 'lee el vencimiento del certificado');

// ---------------- 2. ARCA simulado
$estado = array('logins' => 0, 'ultimo' => array(), 'emitidos' => array(), 'pedidos' => array(), 'cortar' => false, 'rechazarN' => false);
Soap::$simulador = function ($url, $accion, $xml) use (&$estado) {
    $env = function ($cuerpo) {
        return '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            . $cuerpo . '</soap:Body></soap:Envelope>';
    };
    $d = new DOMDocument();
    $d->loadXML($xml);
    $v = function ($n) use ($d) {
        $l = $d->getElementsByTagName($n);
        return $l->length ? $l->item(0)->textContent : '';
    };
    if (strpos($url, 'wsaa') !== false) {
        $estado['logins']++;
        $ta = '<?xml version="1.0" encoding="UTF-8"?><loginTicketResponse version="1.0"><header><expirationTime>'
            . date('c', time() + 43200) . '</expirationTime></header><credentials><token>TOKEN' . $estado['logins']
            . '</token><sign>SIGN</sign></credentials></loginTicketResponse>';
        return $env('<loginCmsResponse xmlns="http://wsaa.view.sua.dvadac.desein.afip.gov"><loginCmsReturn>'
            . htmlspecialchars($ta) . '</loginCmsReturn></loginCmsResponse>');
    }
    $metodo = substr($accion, strrpos($accion, '/') + 1);
    if ($metodo === 'FECompUltimoAutorizado') {
        $k = $v('PtoVta') . '_' . $v('CbteTipo');
        $n = isset($estado['ultimo'][$k]) ? $estado['ultimo'][$k] : 0;
        return $env("<FECompUltimoAutorizadoResponse xmlns=\"http://ar.gov.afip.dif.FEV1/\"><FECompUltimoAutorizadoResult><PtoVta>{$v('PtoVta')}</PtoVta><CbteTipo>{$v('CbteTipo')}</CbteTipo><CbteNro>$n</CbteNro></FECompUltimoAutorizadoResult></FECompUltimoAutorizadoResponse>");
    }
    if ($metodo === 'FECAESolicitar') {
        $estado['pedidos'][] = $xml;
        $k = $v('PtoVta') . '_' . $v('CbteTipo');
        $nro = (int) $v('CbteDesde');
        $ok = $v('CondicionIVAReceptorId') !== '' && $nro === (isset($estado['ultimo'][$k]) ? $estado['ultimo'][$k] : 0) + 1;
        $obs = $ok ? '' : '<Observaciones><Obs><Code>10242</Code><Msg>El campo Condicion IVA receptor no es un valor permitido</Msg></Obs></Observaciones>';
        $anul = $d->getElementsByTagName('Opcional');
        if ($ok && $estado['rechazarN'] && $anul->length && $anul->item(0)->getElementsByTagName('Valor')->item(0)->textContent === 'N') {
            $ok = false;
            $obs = '<Observaciones><Obs><Code>10180</Code><Msg>Comprobante asociado rechazado por el receptor</Msg></Obs></Observaciones>';
        }
        $cae = $ok ? '7' . str_pad((string) (count($estado['pedidos'])), 13, '0', STR_PAD_LEFT) : '';
        if ($ok) {
            $estado['ultimo'][$k] = $nro;
            $estado['emitidos'][$k . '_' . $nro] = array('cae' => $cae, 'total' => $v('ImpTotal'), 'fecha' => $v('CbteFch'));
        }
        if ($estado['cortar']) {
            $estado['cortar'] = false;
            throw new AfipConexionException('corte simulado');
        }
        return $env('<FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/"><FECAESolicitarResult><FeCabResp><Cuit>30714357537</Cuit><PtoVta>'
            . $v('PtoVta') . '</PtoVta><CbteTipo>' . $v('CbteTipo') . '</CbteTipo><FchProceso>20261107120000</FchProceso><CantReg>1</CantReg><Resultado>'
            . ($ok ? 'A' : 'R') . '</Resultado><Reproceso>N</Reproceso></FeCabResp><FeDetResp><FECAEDetResponse><Concepto>1</Concepto><DocTipo>'
            . $v('DocTipo') . '</DocTipo><DocNro>' . $v('DocNro') . '</DocNro><CbteDesde>' . $nro . '</CbteDesde><CbteHasta>' . $nro
            . '</CbteHasta><CbteFch>' . $v('CbteFch') . '</CbteFch><Resultado>' . ($ok ? 'A' : 'R') . '</Resultado>' . $obs
            . '<CAE>' . $cae . '</CAE><CAEFchVto>' . ($ok ? date('Ymd', strtotime('+10 days')) : '') . '</CAEFchVto></FECAEDetResponse></FeDetResp></FECAESolicitarResult></FECAESolicitarResponse>');
    }
    if ($metodo === 'FECompConsultar') {
        $k = $v('PtoVta') . '_' . $v('CbteTipo') . '_' . (int) $v('CbteNro');
        if (!isset($estado['emitidos'][$k])) {
            return $env('<FECompConsultarResponse xmlns="http://ar.gov.afip.dif.FEV1/"><FECompConsultarResult><Errors><Err><Code>602</Code><Msg>No existen datos</Msg></Err></Errors></FECompConsultarResult></FECompConsultarResponse>');
        }
        $e = $estado['emitidos'][$k];
        return $env('<FECompConsultarResponse xmlns="http://ar.gov.afip.dif.FEV1/"><FECompConsultarResult><ResultGet><CbteFch>' . $e['fecha']
            . '</CbteFch><ImpTotal>' . $e['total'] . '</ImpTotal><Resultado>A</Resultado><CodAutorizacion>' . $e['cae']
            . '</CodAutorizacion><FchVto>' . date('Ymd', strtotime('+10 days')) . '</FchVto></ResultGet></FECompConsultarResult></FECompConsultarResponse>');
    }
    if ($metodo === 'FEParamGetCondicionIvaReceptor') {
        return $env('<FEParamGetCondicionIvaReceptorResponse xmlns="http://ar.gov.afip.dif.FEV1/"><FEParamGetCondicionIvaReceptorResult><ResultGet>'
            . '<CondicionIvaReceptor><Id>1</Id><Desc>IVA Responsable Inscripto</Desc><Cmp_Clase>A/M/C</Cmp_Clase></CondicionIvaReceptor>'
            . '<CondicionIvaReceptor><Id>6</Id><Desc>Responsable Monotributo</Desc><Cmp_Clase>A/M/C</Cmp_Clase></CondicionIvaReceptor>'
            . '</ResultGet></FEParamGetCondicionIvaReceptorResult></FEParamGetCondicionIvaReceptorResponse>');
    }
    throw new Exception('método no simulado: ' . $metodo);
};

echo "\n2. Ticket de acceso guardado\n";
$afip->wsaa->credenciales('wsfe');
$afip->wsaa->credenciales('wsfe');
$afip2 = new Afip($cfg); // otra petición web
$afip2->wsaa->credenciales('wsfe');
ok($estado['logins'] === 1, 'pide un solo ticket y lo reutiliza entre llamadas (hubo ' . $estado['logins'] . ')');
$cond = $afip->wsfe->condicionesIvaReceptor();
ok(count($cond) === 2 && $cond[0]['Id'] === '1', 'lee FEParamGetCondicionIvaReceptor');

// ---------------- 3. Comprobante armado a mano (uso desde cualquier sistema)
echo "\n3. Comprobante armado a mano\n";
$c = new Comprobante();
$c->tipo = Comprobante::FC_A;
$c->ptoVta = 5;
$c->fecha = date('Y-m-d');
$c->receptor('30-71000000-1');
$c->condicionIvaReceptor = Comprobante::IVA_RESPONSABLE_INSCRIPTO;
$c->agregarIva(21, 1000, 210)->agregarIva(10.5, 200, 21);
$c->agregarTributo(2, 'Percepción IIBB', 1200, 3, 36);
$c->impNeto = 1200;
$c->totalizar();
$c->impTotal = 1200 + 231 + 36;
ok($c->validar() === array(), 'pasa los controles: ' . implode('; ', $c->validar()));
$r = $afip->autorizar($c);
ok($r['aprobado'] && $r['numero'] === 1 && strlen($r['cae']) === 14, "aprobado nro {$r['numero']} CAE {$r['cae']}");
$qr = $afip->urlQr($c, $r['cae']);
$j = json_decode(base64_decode(substr($qr, strpos($qr, '=') + 1)), true);
ok($j['cuit'] === 30714357537 && $j['importe'] == 1467 && $j['tipoDocRec'] === 80, 'URL del QR con los datos correctos');
$malo = clone $c;
$malo->impTotal = 1000;
$malo->condicionIvaReceptor = 0;
ok(count($malo->validar()) === 2, 'detecta total mal sumado y falta de condición de IVA');

// El XML respeta el orden del WSDL (se valida contra el esquema del WSDL, sin el campo nuevo)
$wsdl = file_get_contents('/mnt/user-data/uploads/reciclados_cloud/RecicladorWs/WS_AFIP/Web References/FEV1/service.wsdl');
if ($wsdl) {
    preg_match('/<s:schema.*?<\/s:schema>/s', $wsdl, $m);
    $xsd = str_replace('<s:schema ', '<s:schema xmlns:s="http://www.w3.org/2001/XMLSchema" xmlns:tns="http://ar.gov.afip.dif.FEV1/" ', $m[0]);
    $ultimo = end($estado['pedidos']);
    $d = new DOMDocument();
    $d->loadXML($ultimo);
    $nodo = $d->getElementsByTagNameNS('http://ar.gov.afip.dif.FEV1/', 'FECAESolicitar')->item(0);
    $sin = new DOMDocument();
    $sin->appendChild($sin->importNode($nodo, true));
    foreach (iterator_to_array($sin->getElementsByTagName('CondicionIVAReceptorId')) as $n) {
        $n->parentNode->removeChild($n);
    }
    libxml_use_internal_errors(true);
    $valido = $sin->schemaValidateSource($xsd);
    $errs = array_map(function ($e) {
        return trim($e->message);
    }, libxml_get_errors());
    libxml_clear_errors();
    ok($valido, 'el pedido valida contra el esquema WSFEv1 ' . implode(' ', $errs));
}

// ---------------- 4. Adaptador O&S con la base de prueba
echo "\n4. Adaptador O&S sobre la base de prueba (datos de Reciclado)\n";
$pdo = new PDO('mysql:unix_socket=/tmp/mdb/sock;dbname=fe_test;charset=utf8', 'root', '');
$pdo->exec("UPDATE fe_configuracion SET con_cCUIT = '30714357537'");
$ad = new OysSubdiario($pdo, $afip, 1, array('registro' => "$tmp/autorizaciones.log"));
$pend = $ad->pendientes();
ok(count($pend) === 18, 'encuentra las ' . count($pend) . ' facturas pendientes de CAE');

// Último número que "tiene" ARCA = último aprobado en el subdiario
$estado['ultimo']['3_201'] = 639;
$estado['ultimo']['3_202'] = 147;
$estado['ultimo']['3_203'] = 86;

$clave = array('tipo' => 'FC', 'letra' => 'A', 'prefijo' => 3, 'comprobante' => 101490, 'cliente' => 73, 'idx_registro' => '2026052617053267');
$fila = $ad->buscar($clave);
$cb = $ad->armar($fila);
echo '     FC FCE: total ' . $cb->impTotal . ' = gravado ' . $cb->impNeto . ' + IVA ' . $cb->impIva . ' + trib ' . $cb->impTrib
    . ' | IVA ' . json_encode($cb->iva) . ' | opc ' . json_encode($cb->opcionales) . ' | vto ' . $cb->vtoPago . "\n";
ok($cb->tipo === 201 && $cb->condicionIvaReceptor === 1 && $cb->docTipo === 80, 'tipo 201, CUIT y condición IVA 1');
// La condición sale de equivalencias_afip ('Equivaliencia'), como en el webservice actualizado
$pdo->exec("INSERT INTO equivalencias_afip (tabla, codigoafip, codigooys) VALUES ('Equivaliencia', '6', '7')");
$fila7 = $fila;
$fila7['iva_cliente'] = 7;
ok($ad->armar($fila7)->condicionIvaReceptor === 6, 'condición IVA tomada de equivalencias_afip (código 7 => 6)');
$fila9 = $fila;
$fila9['iva_cliente'] = 9;
$fila9['letra'] = 'B';
$fila9['tipoafip_oys'] = 206;
try {
    $ad->armar($fila9);
    ok(false, 'código sin equivalencia');
} catch (AfipException $e) {
    ok(strpos($e->getMessage(), 'Equivaliencia') !== false, 'código sin equivalencia en una B: avisa en lugar de mandar cualquier cosa');
}
$filaA = $fila;
$filaA['iva_cliente'] = 9;
try {
    $ad->armar($filaA);
    ok(false, 'A sin equivalencia');
} catch (AfipException $e) {
    ok(strpos($e->getMessage(), 'iva_cliente = 9') !== false, 'tampoco en una A: no supone Responsable Inscripto');
}
ok($cb->validar() === array(), 'las cifras del subdiario cierran: ' . implode('; ', $cb->validar()));
ok($cb->opcionales[0]['id'] === '2101' && $cb->opcionales[1]['valor'] === 'SCA', 'opcionales FCE: CBU y SCA');

// Antes de aprobar: renglones en las tablas asociadas
$cuenta = function ($tabla, $nro) use ($pdo) {
    return (int) $pdo->query("SELECT COUNT(*) FROM $tabla WHERE tipo='FC' AND letra='A' AND prefijo=3 AND comprobante=$nro AND idx_registro='2026052617053267'")->fetchColumn();
};
$antes = array();
foreach (array('iva', 'tributo', 'fectact', 'feestadistica', 'fecaja') as $t) {
    $antes[$t] = $cuenta($t, 101490);
}
echo '     renglones asociados antes: ' . json_encode($antes) . "\n";

$r = $ad->aprobar($clave);
ok($r['aprobado'] && $r['numero'] === 640, "aprobada con número {$r['numero']}, CAE {$r['cae']}");
$s = $pdo->query("SELECT * FROM subdiario WHERE idx_registro='2026052617053267'")->fetch(PDO::FETCH_ASSOC);
ok($s['fe_ccae'] === $r['cae'] && (int) $s['fe_nrocbte'] === 640 && (int) $s['comprobante'] === 640 && (int) $s['fe_ntipoafip'] === 201,
    'subdiario: CAE, fe_nrocbte, comprobante y tipo grabados');
ok($s['fe_dvtocae'] !== null && $s['fe_dfecha'] !== null && strpos($s['fe_mobservaciones'], 'Procesado') === 0, 'subdiario: fechas y observación');
ok(substr($s['actualizacion'], 0, 10) === date('Y-m-d'), 'subdiario: actualizacion al día (la ve la sincronización)');
$despues = array();
foreach ($antes as $t => $n) {
    $despues[$t] = $cuenta($t, 640);
}
ok($despues == $antes, 'tablas de fe_actualizacion renumeradas a 640: ' . json_encode($despues));
ok($cuenta('iva', 101490) === 0, 'no quedan renglones con el número provisorio');
ok(strpos(file_get_contents("$tmp/autorizaciones.log"), $r['cae']) !== false, 'queda el registro de la autorización');
ok(strpos($r['qr'], 'https://www.afip.gob.ar/fe/qr/?p=') === 0, 'devuelve la URL del QR');

try {
    $ad->aprobar(array('tipo' => 'FC', 'letra' => 'A', 'prefijo' => 3, 'comprobante' => 640, 'cliente' => 73, 'idx_registro' => '2026052617053267'));
    ok(false, 'no deja aprobar dos veces');
} catch (AfipException $e) {
    ok(strpos($e->getMessage(), 'ya tiene CAE') !== false, 'no deja aprobar dos veces');
}

// ND FCE con comprobante asociado (cbte_fc = 611)
$nd = array('tipo' => 'ND', 'letra' => 'A', 'prefijo' => 3, 'comprobante' => 101466, 'cliente' => 1, 'idx_registro' => '2026020616020476');
$cbn = $ad->armar($ad->buscar($nd));
ok(count($cbn->asociados) === 1 && $cbn->asociados[0]['nro'] === 611 && $cbn->opcionales[0]['id'] === '22', 'ND FCE: asociado FC 611 y opcional 22');
$r = $ad->aprobar($nd);
ok($r['aprobado'] && $r['numero'] === 148, "ND aprobada con número {$r['numero']}");

// ND sin comprobante asociado (cbte_fc = 0): no se manda
$nd0 = array('tipo' => 'ND', 'letra' => 'A', 'prefijo' => 3, 'comprobante' => 101474, 'cliente' => 73, 'idx_registro' => '2026030517030156');
$n = count($estado['pedidos']);
try {
    $ad->aprobar($nd0);
    ok(false, 'ND sin asociado');
} catch (AfipException $e) {
    ok(count($estado['pedidos']) === $n && strpos($e->getMessage(), 'asociado') !== false, 'ND sin factura asociada: no se envía y se avisa');
}
$obs = $pdo->query("SELECT fe_mobservaciones FROM subdiario WHERE idx_registro='2026030517030156'")->fetchColumn();
ok(strpos($obs, 'asociado') !== false, 'el motivo queda en fe_mobservaciones');

// NC FCE rechazada con 22=N: reintenta con S (como el C#)
$estado['rechazarN'] = true;
$nc = array('tipo' => 'NC', 'letra' => 'A', 'prefijo' => 3, 'comprobante' => 101373, 'cliente' => 1, 'idx_registro' => '2025071715071496');
$r = $ad->aprobar($nc);
ok($r['aprobado'] && strpos(end($estado['pedidos']), '<ar:Valor>S</ar:Valor>') !== false, 'NC FCE rechazada con N: se reintenta con S y se aprueba');
$estado['rechazarN'] = false;

// ND FCE cuyo número de factura existe como FC común y como FCE: toma la FCE
$nd2 = array('tipo' => 'ND', 'letra' => 'A', 'prefijo' => 3, 'comprobante' => 101331, 'cliente' => 1, 'idx_registro' => '2025043011044564');
$cbn = $ad->armar($ad->buscar($nd2));
ok($cbn->asociados[0]['tipo'] === 201, 'asociado ambiguo (FC 570 común y FCE): elige la FCE');

// Corte de comunicación después de que ARCA aprobó: se recupera con FECompConsultar
$estado['cortar'] = true;
$fc1 = array('tipo' => 'FC', 'letra' => 'A', 'prefijo' => 3, 'comprobante' => 101338, 'cliente' => 30, 'idx_registro' => '2025051416052712');
$estado['ultimo']['3_1'] = 99575;
$r = $ad->aprobar($fc1);
ok($r['aprobado'] && $r['numero'] === 99576 && strpos($r['observaciones'][0], 'Recuperado') === 0, 'corte de comunicación: recupera el CAE sin duplicar');

// Correlatividad (apagada por defecto, como en producción): ARCA tiene un número que el subdiario no
$ad->opciones['controlar_correlatividad'] = true;
$estado['ultimo']['3_1'] = 99999;
$fc2 = array('tipo' => 'FC', 'letra' => 'A', 'prefijo' => 3, 'comprobante' => 101338, 'cliente' => 30, 'idx_registro' => '202505141605006');
try {
    $ad->aprobar($fc2);
    ok(false, 'correlatividad');
} catch (AfipException $e) {
    ok(strpos($e->getMessage(), 'no está sincronizado') !== false, 'detecta que el subdiario no está sincronizado con ARCA');
}

echo "\n" . ($fallas ? "$fallas FALLAS\n" : "Todas las pruebas OK\n");
exit($fallas ? 1 : 0);
