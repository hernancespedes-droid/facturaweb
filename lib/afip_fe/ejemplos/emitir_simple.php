<?php
/**
 * Ejemplo mínimo para un sistema nuevo (solo nube, sin el esquema O&S): emitir, grabar e imprimir.
 * Copiar y adaptar; no se usa tal cual.
 *
 * Orden correcto (lo que aprendimos en Reciclados):
 *   1. validar y calcular en el servidor (nunca confiar en los totales del navegador);
 *   2. pedir el CAE;  3. si ARCA aprueba, grabar con el número y el CAE que dio ARCA;
 *   4. si ARCA rechaza, NO grabar nada y mostrar el motivo;  5. imprimir desde lo grabado.
 * Si la grabación falla DESPUÉS del CAE, el comprobante existe en ARCA: hay que dejarlo registrado
 * (log + aviso) y recuperarlo; nunca pedir otro CAE para la misma operación.
 */
require __DIR__ . '/../afip.php';

use OysAfip\Afip;
use OysAfip\Facturador;
use OysAfip\FacturaHtml;
use OysAfip\AfipException;

$pdo = new PDO('mysql:host=localhost;dbname=sistema;charset=utf8mb4', 'usuario', 'clave', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$cfg = require '/ruta/privada/afip_sistema/config_afip.php';
$cfg['almacen_ta'] = new OysAfip\AlmacenTaPdo($pdo);
$afip = new Afip($cfg);
$fact = new Facturador($afip, $pdo, array('condicion_emisor' => 'RI', 'cbu' => ''));

// condición de IVA del cliente -> Id de ARCA (en sistemas O&S: tabla equivalencias_afip, ver EquivalenciasIva)
$cli = array('codigo' => 30, 'razon_social' => 'CLIENTE SRL', 'cuit' => '30715660616', 'domicilio' => 'Calle 1', 'localidad' => 'CABA', 'iva_afip' => 1, 'iva_txt' => 'Responsable Inscripto');

try {
    $r = $fact->emitir(array(
        'tipo' => 'FC', 'pto_vta' => $cfg['puntos_venta'][0], 'fecha' => date('Y-m-d'),
        'receptor' => array('documento' => $cli['cuit'], 'condicion_iva' => $cli['iva_afip']),
        'items' => array(array('descripcion' => 'Cartón', 'cantidad' => 2.5, 'precio' => 1000, 'alicuota' => 21)),
    ));
} catch (AfipException $e) {
    exit('No se pudo emitir: ' . $e->getMessage());     // nada se grabó
}
if (!$r['aprobado']) {
    $m = array();
    foreach ($r['errores'] as $e) $m[] = '(' . $e['codigo'] . ') ' . $e['mensaje'];
    exit('ARCA rechazó el comprobante: ' . implode(' | ', array_merge($m, $r['observaciones'])));
}
$c = $r['comprobante']; $k = $r['calculo'];
// grabar (en una transacción) con el número de ARCA
$pdo->beginTransaction();
// INSERT INTO comprobantes (tipo_afip, pto_vta, numero, fecha, cliente, neto, iva, tributos, total, cae, vto_cae) VALUES (...)
// INSERT INTO comprobante_items (...) por cada $k['items']
$pdo->commit();

// imprimir
echo FacturaHtml::render(array(
    'emisor' => array('nombre' => 'MI EMPRESA SA', 'cuit' => $cfg['cuit'], 'legales' => array('Domicilio', 'Localidad'), 'iibb' => '...', 'condicion_iva' => 'IVA Responsable Inscripto', 'inicio' => '01/01/2020', 'logo' => 'assets/logo.jpg'),
    'comprobante' => array('tipo_afip' => $c->tipo, 'letra' => 'A', 'pto_vta' => $c->ptoVta, 'numero' => $r['numero'], 'fecha' => $c->fecha, 'cae' => $r['cae'], 'vto_cae' => $r['vence']),
    'receptor' => array('razon_social' => $cli['razon_social'], 'codigo' => $cli['codigo'], 'domicilio' => $cli['domicilio'], 'localidad' => $cli['localidad'], 'cuit' => $cli['cuit'], 'condicion_iva' => $cli['iva_txt']),
    'items' => array_map(function ($it) { return array('descripcion' => $it['descripcion'], 'cantidad' => $it['cantidad'], 'precio' => $it['precio'], 'importe' => $it['total']); }, $k['items']),
    'totales' => array('subtotal' => $k['subtotal'], 'iva' => array_combine(array_keys($k['alicuotas']), array_map(function ($a) { return $a['importe']; }, $k['alicuotas'])),
                       'tributos' => array(), 'total' => $k['total']),
    'pie' => 'Generado por Organización y Sistemas - www.oys.com.ar',
));
