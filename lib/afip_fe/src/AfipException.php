<?php
/**
 * Módulo de factura electrónica ARCA/AFIP de O&S.
 * Compatible con PHP 7.0 a 8.x. Requiere las extensiones openssl, curl y dom.
 */
namespace OysAfip;

class AfipException extends \Exception
{
    /** Errores devueltos por ARCA: lista de ['codigo' => int, 'mensaje' => string] */
    public $errores = array();

    /** XML enviado y recibido, para auditoría */
    public $xmlEnviado = '';
    public $xmlRecibido = '';

    public function __construct($mensaje, $errores = array(), $xmlEnviado = '', $xmlRecibido = '')
    {
        parent::__construct($mensaje);
        $this->errores = $errores;
        $this->xmlEnviado = $xmlEnviado;
        $this->xmlRecibido = $xmlRecibido;
    }
}

/** Falla de comunicación (sin respuesta de ARCA). El comprobante puede o no haberse autorizado. */
class AfipConexionException extends AfipException
{
}
