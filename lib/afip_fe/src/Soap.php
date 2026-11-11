<?php
namespace OysAfip;

/**
 * Cliente SOAP mínimo con cURL. No usa la extensión soap de PHP (muchos hostings no la tienen).
 */
class Soap
{
    /** Segundos de espera de la respuesta */
    public static $timeout = 60;

    /** Ruta a un bundle de CA (.pem) si el servidor no trae uno. Vacío = el del sistema. */
    public static $caBundle = '';

    /**
     * Lista de cifrados para cURL. Los servidores de ARCA usan claves DH cortas y OpenSSL 1.1+/3
     * los rechaza ("dh key too small") salvo con SECLEVEL=1. Vacío = no tocar (p. ej. cURL con Schannel).
     */
    public static $cifrados = 'DEFAULT@SECLEVEL=1';

    /**
     * "@SECLEVEL" existe desde OpenSSL 1.1.0: con OpenSSL 1.0.x (hostings viejos, PHP 7.0) cURL da
     * "failed setting cipher list" y no hace falta (1.0.x ya acepta las claves DH cortas de ARCA).
     * Schannel (cURL de Windows) tampoco acepta listas de cifrados de OpenSSL.
     */
    public static function cifradosSoportados($lista)
    {
        if (stripos($lista, '@SECLEVEL') === false || !function_exists('curl_version')) {
            return true;
        }
        $v = curl_version();
        $ssl = isset($v['ssl_version']) ? $v['ssl_version'] : '';
        if (stripos($ssl, 'schannel') !== false) {
            return false;
        }
        if (preg_match('/OpenSSL\/(\d+)\.(\d+)/i', $ssl, $m)) {
            return (int) $m[1] > 1 || ((int) $m[1] === 1 && (int) $m[2] >= 1);
        }
        return true;   // LibreSSL / BoringSSL / otros: se deja como estaba
    }

    /** Si se define, se llama con (url, accion, xmlEnviado, xmlRecibido, segundos) en cada llamada */
    public static $registro = null;

    /** Para pruebas: callable(url, accion, xml) que devuelve el XML de respuesta sin salir a internet */
    public static $simulador = null;

    /**
     * Envía un sobre SOAP 1.1 y devuelve el DOMDocument de la respuesta.
     * @throws AfipConexionException si no hay respuesta
     * @throws AfipException si la respuesta es un SOAP Fault
     */
    public static function llamar($url, $accion, $cuerpo, $ns)
    {
        $sobre = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ar="' . $ns . '">'
            . '<soap:Header/><soap:Body>' . $cuerpo . '</soap:Body></soap:Envelope>';

        $inicio = microtime(true);
        if (self::$simulador) {
            $respuesta = call_user_func(self::$simulador, $url, $accion, $sobre);
            $http = 200;
        } else {
            $ch = curl_init($url);
            $opciones = array(
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $sobre,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => self::$timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: text/xml; charset=utf-8',
                    'SOAPAction: "' . $accion . '"',
                ),
            );
            if (self::$caBundle !== '') {
                $opciones[CURLOPT_CAINFO] = self::$caBundle;
            }
            if (self::$cifrados !== '' && self::cifradosSoportados(self::$cifrados)) {
                $opciones[CURLOPT_SSL_CIPHER_LIST] = self::$cifrados;
            }
            curl_setopt_array($ch, $opciones);
            $respuesta = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errorCurl = curl_error($ch);
            curl_close($ch);
            if ($respuesta === false || $respuesta === '') {
                if (self::$registro) {
                    call_user_func(self::$registro, $url, $accion, $sobre, '', microtime(true) - $inicio);
                }
                throw new AfipConexionException('Sin respuesta de ARCA (' . $url . '): ' . $errorCurl, array(), $sobre, '');
            }
        }
        if (self::$registro) {
            call_user_func(self::$registro, $url, $accion, $sobre, $respuesta, microtime(true) - $inicio);
        }

        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($respuesta);
        libxml_use_internal_errors($prev);
        if (!$ok) {
            throw new AfipConexionException('Respuesta de ARCA no es XML (HTTP ' . $http . '): ' . substr(strip_tags($respuesta), 0, 300), array(), $sobre, $respuesta);
        }
        $fault = $doc->getElementsByTagName('Fault');
        if ($fault->length > 0) {
            $codigo = self::texto($fault->item(0), 'faultcode');
            $texto = self::texto($fault->item(0), 'faultstring');
            throw new AfipException('SOAP Fault ' . $codigo . ': ' . $texto, array(array('codigo' => $codigo, 'mensaje' => $texto)), $sobre, $respuesta);
        }
        return $doc;
    }

    /** Texto del primer descendiente con ese nombre local, o '' */
    public static function texto($nodo, $nombre)
    {
        $lista = $nodo->getElementsByTagName($nombre);
        return $lista->length ? trim($lista->item(0)->textContent) : '';
    }

    /**
     * Convierte un nodo a arreglo. Los elementos que se repiten pasan a ser listas.
     * Usar Soap::lista() sobre colecciones (Obs, Err, AlicIva...) para tener siempre una lista.
     */
    public static function aArreglo($nodo)
    {
        $hijos = array();
        $hayElementos = false;
        foreach ($nodo->childNodes as $h) {
            if ($h->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            $hayElementos = true;
            $n = $h->localName;
            $v = self::aArreglo($h);
            if (array_key_exists($n, $hijos)) {
                if (!is_array($hijos[$n]) || !isset($hijos[$n][0])) {
                    $hijos[$n] = array($hijos[$n]);
                }
                $hijos[$n][] = $v;
            } else {
                $hijos[$n] = $v;
            }
        }
        return $hayElementos ? $hijos : trim($nodo->textContent);
    }

    /** Devuelve siempre una lista: null => [], un elemento => [elemento] */
    public static function lista($valor)
    {
        if ($valor === null || $valor === '' || $valor === array()) {
            return array();
        }
        if (is_array($valor) && isset($valor[0])) {
            return $valor;
        }
        return array($valor);
    }

    /** Escapa texto para XML */
    public static function x($valor)
    {
        return htmlspecialchars((string) $valor, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
