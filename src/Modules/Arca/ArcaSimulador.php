<?php
declare(strict_types=1);

namespace App\Modules\Arca;

use App\App;
use PDO;

/**
 * ARCA simulado (modo "simulado"): contesta lo mismo que WSAA y WSFEv1 sin salir a internet ni usar un certificado real, para
 * probar todo el circuito (pedido de CAE, numeración, impresión con QR, notas de crédito, rechazos) antes de tener el trámite en
 * ARCA hecho. Los CAE que da NO valen nada.
 *
 * DECISIÓN D-14 (Claude, a confirmar por Hernán): para poder probar un rechazo, el simulador rechaza todo comprobante cuyo total
 * termine en ,13 (centavos 13) con el error 10016 simulado. Se saca o se cambia en rechazaPorTotal().
 *
 * Los números los lleva ARCA de verdad: acá salen del último comprobante simulado (fe_entorno = 'S') que haya en el subdiario.
 */
final class ArcaSimulador
{
    /** Configuración para el módulo: un certificado autofirmado de mentira que se crea una vez en storage/afip_simulado/. @return array<string,mixed> */
    public static function configuracion(string $cuit): array
    {
        $dir = App::storage('afip_simulado');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new \RuntimeException('No se pudo crear storage/afip_simulado (falta permiso de escritura en storage/).');
        }
        $crt = $dir . '/simulado.crt';
        $key = $dir . '/simulado.key';
        if (!is_file($crt) || !is_file($key)) {
            $k = openssl_pkey_new(['private_key_bits' => 2048]);
            $csr = $k !== false ? openssl_csr_new(['commonName' => 'simulado', 'organizationName' => 'ARCA SIMULADO'], $k) : false;
            $x = $csr !== false && $csr !== true ? openssl_csr_sign($csr, null, $k, 3650) : false;
            if ($k === false || $x === false) {
                throw new \RuntimeException('No se pudo crear el certificado del simulador (falta la extensión openssl).');
            }
            openssl_x509_export_to_file($x, $crt);
            openssl_pkey_export_to_file($k, $key);
        }
        return ['cuit' => $cuit !== '' ? $cuit : '20000000001', 'produccion' => false, 'certificado' => $crt, 'clave_privada' => $key,
            'carpeta_temp' => $dir, 'puntos_venta' => []];
    }

    /** Pone el simulador en lugar de internet. */
    public static function instalar(PDO $pdo): void
    {
        \OysAfip\Soap::$simulador = static fn ($url, $accion, $xml) => self::responder($pdo, (string) $url, (string) $accion, (string) $xml);
    }

    /** El total termina en ,13: el simulador lo rechaza. */
    public static function rechazaPorTotal(float $total): bool
    {
        return (int) round(fmod(abs($total) * 100, 100)) === 13;
    }

    private static function sobre(string $cuerpo): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>' . $cuerpo . '</soap:Body></soap:Envelope>';
    }

    private static function responder(PDO $pdo, string $url, string $accion, string $xml): string
    {
        $d = new \DOMDocument();
        $d->loadXML($xml);
        $v = static function (string $n) use ($d): string {
            $l = $d->getElementsByTagName($n);
            return $l->length > 0 ? (string) $l->item(0)->textContent : '';
        };
        if (str_contains($url, 'sr-padron')) {
            return self::padron($v('idPersona'));
        }
        if (str_contains($url, 'wsaa')) {
            $ta = '<?xml version="1.0" encoding="UTF-8"?><loginTicketResponse version="1.0"><header><expirationTime>' . date('c', time() + 43200)
                . '</expirationTime></header><credentials><token>SIMULADO</token><sign>SIMULADO</sign></credentials></loginTicketResponse>';
            return self::sobre('<loginCmsResponse xmlns="http://wsaa.view.sua.dvadac.desein.afip.gov"><loginCmsReturn>' . htmlspecialchars($ta) . '</loginCmsReturn></loginCmsResponse>');
        }
        $m = substr($accion, (int) strrpos($accion, '/') + 1);
        $ns = 'xmlns="http://ar.gov.afip.dif.FEV1/"';
        switch ($m) {
            case 'FEDummy':
                return self::sobre("<FEDummyResponse {$ns}><FEDummyResult><AppServer>OK</AppServer><DbServer>OK</DbServer><AuthServer>OK</AuthServer></FEDummyResult></FEDummyResponse>");
            case 'FECompUltimoAutorizado':
                $ult = self::ultimo($pdo, (int) $v('PtoVta'), (int) $v('CbteTipo'));
                return self::sobre("<FECompUltimoAutorizadoResponse {$ns}><FECompUltimoAutorizadoResult><PtoVta>{$v('PtoVta')}</PtoVta><CbteTipo>{$v('CbteTipo')}</CbteTipo><CbteNro>{$ult}</CbteNro></FECompUltimoAutorizadoResult></FECompUltimoAutorizadoResponse>");
            case 'FEParamGetPtosVenta':
                $l = '';
                foreach ($pdo->query('SELECT prefijo FROM sucursales WHERE prefijo > 0 AND fac_electronica = 1')->fetchAll(PDO::FETCH_COLUMN) as $pv) {
                    $l .= "<PtoVenta><Nro>{$pv}</Nro><EmisionTipo>RECE para aplicativo y web services (simulado)</EmisionTipo><Bloqueado>N</Bloqueado><FchBaja>NULL</FchBaja></PtoVenta>";
                }
                return self::sobre("<FEParamGetPtosVentaResponse {$ns}><FEParamGetPtosVentaResult><ResultGet>{$l}</ResultGet></FEParamGetPtosVentaResult></FEParamGetPtosVentaResponse>");
            case 'FEParamGetCondicionIvaReceptor':
                $l = '';
                foreach ([1 => 'IVA Responsable Inscripto', 4 => 'IVA Sujeto Exento', 5 => 'Consumidor Final', 6 => 'Responsable Monotributo', 7 => 'Sujeto No Categorizado',
                    10 => 'IVA Liberado - Ley Nº 19.640', 13 => 'Monotributista Social', 15 => 'IVA No Alcanzado', 16 => 'Monotributo Trabajador Independiente Promovido'] as $id => $t) {
                    $l .= "<CondicionIvaReceptor><Id>{$id}</Id><Desc>{$t}</Desc><Cmp_Clase>A</Cmp_Clase></CondicionIvaReceptor>";
                }
                return self::sobre("<FEParamGetCondicionIvaReceptorResponse {$ns}><FEParamGetCondicionIvaReceptorResult><ResultGet>{$l}</ResultGet></FEParamGetCondicionIvaReceptorResult></FEParamGetCondicionIvaReceptorResponse>");
            case 'FECompConsultar':
                $st = $pdo->prepare("SELECT fe_ccae, fe_dvtocae, neto, fecha FROM subdiario WHERE fe_entorno = 'S' AND prefijo = ? AND fe_ntipoafip = ? AND fe_nrocbte = ? LIMIT 1");
                $st->execute([(int) $v('PtoVta'), (int) $v('CbteTipo'), (int) $v('CbteNro')]);
                $f = $st->fetch();
                if ($f === false) {
                    return self::sobre("<FECompConsultarResponse {$ns}><FECompConsultarResult><Errors><Err><Code>602</Code><Msg>No existe el comprobante (simulado)</Msg></Err></Errors></FECompConsultarResult></FECompConsultarResponse>");
                }
                return self::sobre("<FECompConsultarResponse {$ns}><FECompConsultarResult><ResultGet><CodAutorizacion>{$f['fe_ccae']}</CodAutorizacion><FchVto>" . str_replace('-', '', (string) $f['fe_dvtocae'])
                    . "</FchVto><ImpTotal>{$f['neto']}</ImpTotal><CbteFch>" . str_replace('-', '', (string) $f['fecha']) . '</CbteFch></ResultGet></FECompConsultarResult></FECompConsultarResponse>');
            case 'FECAESolicitar':
                $nro = self::ultimo($pdo, (int) $v('PtoVta'), (int) $v('CbteTipo')) + 1;
                $fch = $v('CbteFch');
                if (self::rechazaPorTotal((float) $v('ImpTotal'))) {
                    return self::sobre("<FECAESolicitarResponse {$ns}><FECAESolicitarResult><FeCabResp><Resultado>R</Resultado></FeCabResp><FeDetResp><FECAEDetResponse><CbteDesde>{$nro}</CbteDesde><CbteHasta>{$nro}</CbteHasta>"
                        . "<CbteFch>{$fch}</CbteFch><Resultado>R</Resultado><CAE></CAE><CAEFchVto></CAEFchVto><Observaciones><Obs><Code>10016</Code><Msg>Simulado: el total termina en ,13 y el simulador lo rechaza para probar el rechazo</Msg></Obs></Observaciones>"
                        . '</FECAEDetResponse></FeDetResp></FECAESolicitarResult></FECAESolicitarResponse>');
                }
                $cae = '99' . str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
                return self::sobre("<FECAESolicitarResponse {$ns}><FECAESolicitarResult><FeCabResp><Resultado>A</Resultado></FeCabResp><FeDetResp><FECAEDetResponse><CbteDesde>{$nro}</CbteDesde><CbteHasta>{$nro}</CbteHasta>"
                    . "<CbteFch>{$fch}</CbteFch><Resultado>A</Resultado><CAE>{$cae}</CAE><CAEFchVto>" . date('Ymd', strtotime('+10 days')) . '</CAEFchVto></FECAEDetResponse></FeDetResp></FECAESolicitarResult></FECAESolicitarResponse>');
        }
        throw new \RuntimeException('El simulador de ARCA no conoce ' . $m);
    }

    /**
     * Padrón simulado (constancia de inscripción). Contesta siempre lo mismo para el mismo CUIT:
     *   30-, 33-, 34-…            sociedad Responsable Inscripta en Morón (Buenos Aires)
     *   20/23/24/27-… según la suma de las cifras: resto 0 → monotributista categoría D en CABA, resto 1 → persona Responsable Inscripta
     *                              en Córdoba, resto 2 → sin IVA ni monotributo (consumidor final)
     *   las 8 cifras del medio en cero (p. ej. 20-00000000-1): «No existe persona con ese Id».
     */
    public static function padron(string $cuit): string
    {
        $cuit = preg_replace('/\D/', '', $cuit) ?? '';
        $ns = 'xmlns:ns2="http://a5.soap.ws.server.puc.sr/"';
        if (substr($cuit, 2, 8) === '00000000') {
            return '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault>'
                . '<faultcode>soap:Server</faultcode><faultstring>No existe persona con ese Id</faultstring></soap:Fault></soap:Body></soap:Envelope>';
        }
        $fin = substr($cuit, 6, 4);
        $meta = '<metadata><fechaHora>' . date('c') . '</fechaHora><servidor>simulado</servidor></metadata>';
        $imp = static fn (int $id, string $d): string => "<impuesto><descripcionImpuesto>{$d}</descripcionImpuesto><estadoImpuesto>AC</estadoImpuesto><idImpuesto>{$id}</idImpuesto><motivo>simulado</motivo><periodo>201901</periodo></impuesto>";
        $act = static fn (string $id, string $d, int $o): string => "<actividad><descripcionActividad>{$d}</descripcionActividad><idActividad>{$id}</idActividad><nomenclador>883</nomenclador><orden>{$o}</orden><periodo>201901</periodo></actividad>";
        $dom = static fn (string $dir, string $loc, string $cp, int $idp, string $prov): string => "<domicilioFiscal><codPostal>{$cp}</codPostal><descripcionProvincia>{$prov}</descripcionProvincia>"
            . "<direccion>{$dir}</direccion><idProvincia>{$idp}</idProvincia>" . ($loc !== '' ? "<localidad>{$loc}</localidad>" : '') . '<tipoDomicilio>FISCAL</tipoDomicilio></domicilioFiscal>';
        if (in_array(substr($cuit, 0, 2), ['30', '33', '34'], true)) {
            $gen = "<datosGenerales>{$dom('AV RIVADAVIA 18500', 'MORON', '1708', 1, 'BUENOS AIRES')}<esSucesion>NO</esSucesion><estadoClave>ACTIVO</estadoClave>"
                . "<idPersona>{$cuit}</idPersona><mesCierre>12</mesCierre><razonSocial>EMPRESA SIMULADA {$fin} SA</razonSocial><tipoClave>CUIT</tipoClave><tipoPersona>JURIDICA</tipoPersona></datosGenerales>";
            $resto = "<datosRegimenGeneral>{$act('461011', 'VENTA AL POR MAYOR EN COMISIÓN O CONSIGNACIÓN DE CEREALES', 1)}{$act('620100', 'SERVICIOS DE CONSULTORES EN INFORMÁTICA', 2)}"
                . "{$imp(10, 'GANANCIAS SOCIEDADES')}{$imp(30, 'IVA')}{$imp(301, 'EMPLEADOR-APORTES SEG. SOCIAL')}</datosRegimenGeneral>";
        } else {
            $caso = array_sum(array_map('intval', str_split($cuit))) % 3;
            $persona = static fn (string $d): string => "<datosGenerales>{$d}<estadoClave>ACTIVO</estadoClave><idPersona>{$cuit}</idPersona>"
                . "<apellido>SIMULADO {$fin}</apellido><nombre>" . ['MARIA', 'JUAN', 'ANA'][$caso] . '</nombre><tipoClave>CUIT</tipoClave><tipoPersona>FISICA</tipoPersona></datosGenerales>';
            if ($caso === 0) {
                $resto = $persona($dom('AV CORRIENTES 1234 PISO 3 DTO B', '', '1043', 0, 'CIUDAD AUTONOMA BUENOS AIRES'))
                    . "<datosMonotributo><actividadMonotributista><descripcionActividad>VENTA AL POR MENOR DE PRENDAS DE VESTIR</descripcionActividad><idActividad>477150</idActividad>"
                    . "<nomenclador>883</nomenclador><orden>1</orden><periodo>201901</periodo></actividadMonotributista><categoriaMonotributo><descripcionCategoria>D LOCACIONES DE SERVICIO</descripcionCategoria>"
                    . "<idCategoria>34</idCategoria><idImpuesto>20</idImpuesto><periodo>202401</periodo></categoriaMonotributo>{$imp(20, 'MONOTRIBUTO')}</datosMonotributo>";
                $gen = '';
            } elseif ($caso === 1) {
                $resto = $persona($dom('BV SAN JUAN 450', 'CORDOBA', '5000', 3, 'CORDOBA'))
                    . "<datosRegimenGeneral>{$act('471110', 'VENTA AL POR MENOR EN HIPERMERCADOS', 1)}{$imp(11, 'GANANCIAS PERSONAS FISICAS')}{$imp(30, 'IVA')}</datosRegimenGeneral>";
                $gen = '';
            } else {
                $resto = $persona($dom('CALLE 7 N 1020', 'LA PLATA', '1900', 1, 'BUENOS AIRES'))
                    . '<errorConstancia><error>El contribuyente no registra impuestos activos (simulado)</error></errorConstancia>';
                $gen = '';
            }
        }
        return '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            . "<ns2:getPersona_v2Response {$ns}><personaReturn>{$gen}{$resto}{$meta}</personaReturn></ns2:getPersona_v2Response></soap:Body></soap:Envelope>";
    }

    private static function ultimo(PDO $pdo, int $pv, int $tipo): int
    {
        $st = $pdo->prepare("SELECT COALESCE(MAX(fe_nrocbte), 0) FROM subdiario WHERE fe_entorno = 'S' AND prefijo = ? AND fe_ntipoafip = ?");
        $st->execute([$pv, $tipo]);
        return (int) $st->fetchColumn();
    }
}
