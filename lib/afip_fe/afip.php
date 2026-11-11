<?php
/**
 * Módulo de factura electrónica ARCA/AFIP — O&S (Organización & Sistemas).
 * Incluir este archivo y usar las clases del espacio de nombres OysAfip.
 *
 *   require __DIR__ . '/afip_fe/afip.php';
 *   $afip = new OysAfip\Afip($config);
 *
 * Requisitos: PHP 7.0 o superior, extensiones openssl, curl y dom. No usa la extensión soap.
 */
require_once __DIR__ . '/src/AfipException.php';
require_once __DIR__ . '/src/Soap.php';
require_once __DIR__ . '/src/Wsaa.php';
require_once __DIR__ . '/src/Wsfe.php';
require_once __DIR__ . '/src/Comprobante.php';
require_once __DIR__ . '/src/Utiles.php';
require_once __DIR__ . '/abm/EquivalenciasIva.php';
require_once __DIR__ . '/abm/EquivalenciasIvaVista.php';
require_once __DIR__ . '/src/Calculo.php';
require_once __DIR__ . '/src/Impresion.php';
require_once __DIR__ . '/src/Facturador.php';
