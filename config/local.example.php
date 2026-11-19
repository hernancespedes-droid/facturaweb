<?php
declare(strict_types=1);

/*
 * Copiar este archivo como config/local.php en el servidor y completar los datos.
 * config/local.php NO se sube al repositorio ni viaja en los paquetes de entrega,
 * así las contraseñas quedan solo en el servidor.
 */

return [
    'app' => [
        'debug'     => false,   // true solo en el ambiente de prueba, para ver el detalle de los errores
        'base_path' => '',
        // Solo para el diagnóstico inicial: poner una clave larga al azar, abrir
        // /diagnostico?token=CLAVE, copiar el resultado y volver a dejarla vacía.
        'diag_token' => '',
        // Solo para la primera instalación sin SSH: una clave larga al azar, abrir /instalar, crear el Master
        // y volver a dejarla vacía.
        'instalar_token' => '',
    ],
    // Mail de salida para enviar los comprobantes (casilla del dominio del cliente en el hosting).
    'mail' => [
        'host'      => 'smtp.dominio.com',
        'port'      => 587,
        'secure'    => 'tls',          // tls (587) o ssl (465)
        'user'      => 'facturas@dominio.com',
        'pass'      => 'CONTRASEÑA_DE_LA_CASILLA',
        'from'      => 'facturas@dominio.com',
        'from_name' => 'Nombre de la empresa',
    ],
    // Factura electrónica de ARCA (ver docs/ARCA-PUESTA-EN-MARCHA.md). Sin esta sección el sistema emite comprobantes internos.
    'afip' => [
        'modo'   => 'interno',   // interno | simulado | homologacion | produccion
        'config' => '',          // ruta al config_afip.php del cliente, FUERA de public_html
    ],
    // Mercado Pago (cobros con QR y link de pago). Las claves son de la cuenta del cliente y van SOLO acá, nunca al repositorio.
    'mp' => [
        'modo'           => 'apagado',   // apagado | simulado | produccion
        'access_token'   => '',          // credencial de producción de la aplicación de Mercado Pago
        'webhook_secret' => '',          // clave secreta de las notificaciones (Webhooks) de la aplicación
        'user_id'        => '',          // id de la cuenta vendedora (para el QR); sin esto solo hay link de pago
        'pos_id'         => '',          // external_id de la caja (POS) creada en Mercado Pago (para el QR)
        'expira_minutos' => 30,
    ],
    'db' => [
        'host' => 'localhost',
        'name' => 'NOMBRE_DE_LA_BASE',
        'user' => 'USUARIO_DE_LA_BASE',
        'pass' => 'CONTRASEÑA_DE_LA_BASE',
    ],
];
