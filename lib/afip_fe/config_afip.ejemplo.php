<?php
/**
 * Configuración de ejemplo. Copiar como config_afip.php FUERA de public_html (por ejemplo
 * <raíz del hosting>/afip_<sistema>/config_afip.php), junto con el certificado y la clave.
 * Nunca subir el certificado ni la clave a una carpeta pública. Nunca usar el mismo certificado que otro
 * sistema (escritorio, otro hosting): ARCA da un solo ticket de acceso por certificado y se trabarían.
 */
$dir = __DIR__;
$cfg = array(
    'cuit' => '30000000000',          // CUIT del emisor (sin guiones)
    'produccion' => false,            // false = homologación (pruebas)

    // Certificado de ARCA: .crt + .key (los .pfx viejos con cifrado RC2 no abren en OpenSSL 3).
    // Si el sistema ya tiene un alias que funciona (con la relación wsfe hecha), conviene AGREGAR un certificado
    // nuevo a ese mismo alias: hereda la autorización y tiene su propio ticket de acceso.
    'certificado' => $dir . '/emisor.crt',
    'clave_privada' => $dir . '/emisor.key',
    'frase' => '',                    // contraseña de la clave, si tiene

    // Puntos de venta que puede usar ESTE sistema (uno propio, tipo "RECE para aplicativo y web services",
    // para no competir por los números con el sistema de escritorio). Los sistemas lo usan para el combo.
    'puntos_venta' => array(5),

    // Ticket de acceso: en archivo (carpeta con escritura) o en la base (recomendado en hostings compartidos).
    'carpeta_ta' => $dir . '/ta',
    'carpeta_temp' => '',             // vacío = la carpeta temporal del sistema

    'timeout' => 60,
    // Hostings Windows/IIS con PHP viejo: cURL no trae certificados raíz. Usar la lista de Mozilla
    // incluida en el módulo (recursos/cacert.pem) copiada al lado de esta configuración.
    'ca_bundle' => is_file($dir . '/cacert.pem') ? $dir . '/cacert.pem' : '',
    // Las claves DH de ARCA son cortas para OpenSSL >= 1.1: el módulo agrega @SECLEVEL=1 solo si el OpenSSL de
    // cURL lo soporta (con OpenSSL 1.0.x o Schannel lo omite solo).
    'cifrados' => 'DEFAULT@SECLEVEL=1',
);
// Ticket de acceso en la base del sistema (tabla afip_ta, se crea sola). Ejemplo si el sistema tiene db():
// if (function_exists('db')) $cfg['almacen_ta'] = new OysAfip\AlmacenTaPdo(db());
return $cfg;
