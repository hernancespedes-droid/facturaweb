<?php
/**
 * Prueba de conexión con ARCA, sin emitir nada.
 *   Línea de comandos: php probar_conexion.php ruta/config_afip.php [punto_de_venta] [tipo]
 *   Navegador: probar_conexion.php?pv=3&tipo=201  (usa ../config_afip.php; borrar o proteger después de usar)
 * Muestra: estado de los servidores, vencimiento del certificado, ticket de acceso,
 * puntos de venta, último número autorizado y las condiciones de IVA del receptor.
 */
require __DIR__ . '/../afip.php';

use OysAfip\Afip;

$args = PHP_SAPI === 'cli' ? $argv
    : array(0, dirname(__DIR__) . '/config_afip.php', isset($_GET['pv']) ? (int) $_GET['pv'] : 0, isset($_GET['tipo']) ? (int) $_GET['tipo'] : 1);
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    // las consultas pueden tardar: no trabar la sesión del usuario en otras pestañas
    if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    @set_time_limit(180);
}
if (empty($args[1]) || !is_file($args[1])) {
    exit("Uso: php probar_conexion.php config_afip.php [punto_de_venta] [tipo]\n");
}
$cfg = require $args[1];
$cfg['timeout'] = 15;   // en la prueba no se espera tanto a cada servicio
$pv = isset($args[2]) ? (int) $args[2] : 0;
$tipo = isset($args[3]) ? (int) $args[3] : 1;

function paso($titulo, $fn)
{
    echo str_pad($titulo, 34, '.') . ' ';
    try {
        $r = $fn();
        echo "OK\n";
        if ($r !== null) {
            echo '    ' . str_replace("\n", "\n    ", is_string($r) ? $r : print_r($r, true)) . "\n";
        }
    } catch (\Exception $e) {
        echo 'ERROR: ' . $e->getMessage() . "\n";
    }
}

echo 'PHP ' . PHP_VERSION . ' | openssl ' . (extension_loaded('openssl') ? 'sí' : 'NO') . ' | curl ' . (extension_loaded('curl') ? 'sí' : 'NO')
    . ' | dom ' . (extension_loaded('dom') ? 'sí' : 'NO') . "\n";
echo 'Entorno: ' . (!empty($cfg['produccion']) ? 'PRODUCCIÓN' : 'homologación') . ' | CUIT ' . $cfg['cuit'] . "\n\n";

$afip = null;
paso('Certificado', function () use ($cfg, &$afip) {
    $afip = new Afip($cfg);
    $v = $afip->wsaa->venceCertificado();
    $dias = floor(($v - time()) / 86400);
    return 'vence el ' . date('d/m/Y', $v) . " ($dias días)";
});
if (!$afip) {
    exit(1);
}
paso('Servidores de ARCA (FEDummy)', function () use ($afip) {
    $d = $afip->wsfe->dummy();
    return "app {$d['AppServer']} | db {$d['DbServer']} | auth {$d['AuthServer']}";
});
paso('Ticket de acceso (WSAA)', function () use ($afip) {
    $ta = $afip->wsaa->credenciales('wsfe');
    return 'vence ' . date('d/m/Y H:i', $ta['vence']);
});
paso('Puntos de venta', function () use ($afip) {
    $out = array();
    foreach ($afip->wsfe->parametros('PtosVenta') as $p) {
        $out[] = $p['Nro'] . ' ' . $p['EmisionTipo'] . ($p['Bloqueado'] === 'S' ? ' BLOQUEADO' : '') . ($p['FchBaja'] !== 'NULL' && $p['FchBaja'] !== '' ? ' baja ' . $p['FchBaja'] : '');
    }
    return implode("\n", $out);
});
if ($pv) {
    paso("Último autorizado pv $pv tipo $tipo", function () use ($afip, $pv, $tipo) {
        return (string) $afip->wsfe->ultimoAutorizado($pv, $tipo);
    });
}
paso('Condiciones de IVA del receptor', function () use ($afip) {
    $out = array();
    foreach ($afip->wsfe->condicionesIvaReceptor() as $p) {
        $out[] = $p['Id'] . ' = ' . $p['Desc'] . (isset($p['Cmp_Clase']) ? ' (' . $p['Cmp_Clase'] . ')' : '');
    }
    return implode("\n", $out);
});
