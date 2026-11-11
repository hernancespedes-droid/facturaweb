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
    'db' => [
        'host' => 'localhost',
        'name' => 'NOMBRE_DE_LA_BASE',
        'user' => 'USUARIO_DE_LA_BASE',
        'pass' => 'CONTRASEÑA_DE_LA_BASE',
    ],
];
