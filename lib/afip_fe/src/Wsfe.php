<?php
namespace OysAfip;

/**
 * WSFEv1: autorización de comprobantes A, B, C, M y FCE MiPyMEs (CAE).
 */
class Wsfe
{
    const URL_PRODUCCION = 'https://servicios1.afip.gov.ar/wsfev1/service.asmx';
    const URL_HOMOLOGACION = 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx';
    const NS = 'http://ar.gov.afip.dif.FEV1/';

    private $wsaa;
    private $cuit;
    private $url;

    public function __construct(Wsaa $wsaa, $cuit, $produccion)
    {
        $this->wsaa = $wsaa;
        $this->cuit = preg_replace('/\D/', '', $cuit);
        $this->url = $produccion ? self::URL_PRODUCCION : self::URL_HOMOLOGACION;
    }

    public function cuit()
    {
        return $this->cuit;
    }

    private function auth()
    {
        $c = $this->wsaa->credenciales('wsfe');
        return '<ar:Auth><ar:Token>' . Soap::x($c['token']) . '</ar:Token><ar:Sign>' . Soap::x($c['sign'])
            . '</ar:Sign><ar:Cuit>' . $this->cuit . '</ar:Cuit></ar:Auth>';
    }

    /**
     * Llama a un método y devuelve el nodo <metodoResult> como arreglo.
     * Si ARCA devuelve Errors, lanza AfipException con la lista.
     */
    public function llamar($metodo, $parametrosXml = '', $conAuth = true)
    {
        $cuerpo = '<ar:' . $metodo . '>' . ($conAuth ? $this->auth() : '') . $parametrosXml . '</ar:' . $metodo . '>';
        $doc = Soap::llamar($this->url, self::NS . $metodo, $cuerpo, self::NS);
        $nodo = $doc->getElementsByTagName($metodo . 'Result');
        if ($nodo->length === 0) {
            throw new AfipException('Respuesta de ' . $metodo . ' sin resultado');
        }
        $r = Soap::aArreglo($nodo->item(0));
        return is_array($r) ? $r : array();
    }

    /** Arma la lista de errores [codigo, mensaje] de un resultado */
    public static function errores($r)
    {
        $out = array();
        if (isset($r['Errors'])) {
            foreach (Soap::lista($r['Errors']['Err']) as $e) {
                $out[] = array('codigo' => (int) $e['Code'], 'mensaje' => $e['Msg']);
            }
        }
        return $out;
    }

    private function exigirSinErrores($r, $metodo)
    {
        $err = self::errores($r);
        if ($err) {
            $txt = array();
            foreach ($err as $e) {
                $txt[] = '(' . $e['codigo'] . ') ' . $e['mensaje'];
            }
            throw new AfipException($metodo . ': ' . implode(' | ', $txt), $err);
        }
    }

    /** Estado de los servidores de ARCA (no requiere certificado) */
    public function dummy()
    {
        return $this->llamar('FEDummy', '', false);
    }

    /** Último número autorizado para ese punto de venta y tipo */
    public function ultimoAutorizado($ptoVta, $tipo)
    {
        $r = $this->llamar('FECompUltimoAutorizado',
            '<ar:PtoVta>' . (int) $ptoVta . '</ar:PtoVta><ar:CbteTipo>' . (int) $tipo . '</ar:CbteTipo>');
        $this->exigirSinErrores($r, 'FECompUltimoAutorizado');
        return (int) $r['CbteNro'];
    }

    /** Datos de un comprobante ya emitido, o null si no existe */
    public function consultar($ptoVta, $tipo, $numero)
    {
        $r = $this->llamar('FECompConsultar', '<ar:FeCompConsReq><ar:CbteTipo>' . (int) $tipo . '</ar:CbteTipo><ar:CbteNro>'
            . (int) $numero . '</ar:CbteNro><ar:PtoVta>' . (int) $ptoVta . '</ar:PtoVta></ar:FeCompConsReq>');
        foreach (self::errores($r) as $e) {
            if ($e['codigo'] == 602) { // no existe
                return null;
            }
        }
        $this->exigirSinErrores($r, 'FECompConsultar');
        return isset($r['ResultGet']) ? $r['ResultGet'] : null;
    }

    /** Tablas de parámetros: CondicionIvaReceptor, TiposCbte, TiposIva, TiposDoc, TiposTributos, PtosVenta, TiposOpcional, TiposMonedas */
    public function parametros($tabla, $extraXml = '')
    {
        $r = $this->llamar('FEParamGet' . $tabla, $extraXml);
        $this->exigirSinErrores($r, 'FEParamGet' . $tabla);
        if (!isset($r['ResultGet']) || !is_array($r['ResultGet'])) {
            return array();
        }
        $lista = reset($r['ResultGet']);
        return Soap::lista($lista);
    }

    /** Condiciones de IVA válidas para el receptor según la clase de comprobante (A, B, C, M) */
    public function condicionesIvaReceptor($claseCmp = '')
    {
        $extra = $claseCmp !== '' ? '<ar:ClaseCmp>' . Soap::x($claseCmp) . '</ar:ClaseCmp>' : '';
        return $this->parametros('CondicionIvaReceptor', $extra);
    }

    public function cotizacion($moneda)
    {
        $r = $this->llamar('FEParamGetCotizacion', '<ar:MonId>' . Soap::x($moneda) . '</ar:MonId>');
        $this->exigirSinErrores($r, 'FEParamGetCotizacion');
        return (float) $r['ResultGet']['MonCotiz'];
    }

    /**
     * Pide el CAE de UN comprobante con el número ya asignado ($c->numero).
     * Devuelve el resultado de Comprobante::leerRespuesta(): aprobado, cae, vence, observaciones, errores.
     * Si se corta la comunicación lanza AfipConexionException: usar autorizar() que lo resuelve.
     */
    public function solicitarCae(Comprobante $c)
    {
        $xml = '<ar:FeCAEReq>' . $c->xmlCabecera() . '<ar:FeDetReq>' . $c->xmlDetalle() . '</ar:FeDetReq></ar:FeCAEReq>';
        $r = $this->llamar('FECAESolicitar', $xml);
        return $c->leerRespuesta($r);
    }

    /**
     * Flujo completo y seguro: toma el último número, asigna el siguiente, pide el CAE y,
     * si la comunicación se corta, consulta a ARCA si el comprobante quedó autorizado.
     * Para evitar números repetidos entre dos usuarios, llamar dentro de un bloqueo (ver Bloqueo).
     */
    public function autorizar(Comprobante $c)
    {
        $c->numero = $this->ultimoAutorizado($c->ptoVta, $c->tipo) + 1;
        try {
            return $this->solicitarCae($c);
        } catch (AfipConexionException $e) {
            sleep(3);
            $ya = $this->consultar($c->ptoVta, $c->tipo, $c->numero);
            if ($ya && !empty($ya['CodAutorizacion']) && abs((float) $ya['ImpTotal'] - $c->impTotal) < 0.01) {
                return array(
                    'aprobado' => true,
                    'resultado' => 'A',
                    'numero' => $c->numero,
                    'cae' => $ya['CodAutorizacion'],
                    'vence' => $ya['FchVto'],
                    'fecha' => $ya['CbteFch'],
                    'observaciones' => array('Recuperado con FECompConsultar después de un corte de comunicación'),
                    'errores' => array(),
                );
            }
            throw $e;
        }
    }
}
