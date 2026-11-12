<?php
declare(strict_types=1);

/*
 * Licencias de la suscripción (herramienta de O&S: la corre Hernán, NO va al servidor del cliente).
 *
 *   php bin/licencia.php clave   --salida=<carpeta segura>
 *       Crea el par de claves de O&S (una sola vez). Deja licencia_privada.key (SECRETA: no va al repo, ni al chat, ni a un servidor)
 *       y licencia_publica.txt. La pública se copia a config/licencia_publica.txt y se sube al repo; con ella las instalaciones verifican.
 *   php bin/licencia.php emitir  --privada=<licencia_privada.key> --cliente="Razón social" --cuit=30123456789 [--meses=1 | --vence=AAAA-MM-DD] [--salida=<archivo>]
 *       Emite licencia.json para un cliente (por omisión vence dentro de 1 mes). Se la mandás al cliente o la cargás en Sistema → Licencia.
 *   php bin/licencia.php verificar <licencia.json> [--publica=<licencia_publica.txt>]
 *       Muestra los datos de una licencia y si la firma es correcta.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

use App\Modules\Licencia\Licencia;

$cmd = $argv[1] ?? '';
// Opciones --nombre=valor después del subcomando (getopt se detiene en el primer argumento que no es opción) y archivos sueltos.
$o = [];
$resto = [];
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--([a-z]+)=(.*)$/s', $a, $m) === 1) {
        $o[$m[1]] = $m[2];
    } else {
        $resto[] = $a;
    }
}
$fallar = static function (string $m): never {
    fwrite(STDERR, "Error: {$m}\n");
    exit(1);
};

if ($cmd === 'clave') {
    $dir = rtrim((string) ($o['salida'] ?? ''), '/\\');
    if ($dir === '' || !is_dir($dir)) {
        $fallar('Indicá una carpeta existente con --salida=<carpeta segura>.');
    }
    if (is_file($dir . '/licencia_privada.key')) {
        $fallar('Ya hay una clave privada en esa carpeta: no se pisa.');
    }
    $par = sodium_crypto_sign_keypair();
    file_put_contents($dir . '/licencia_privada.key', base64_encode(sodium_crypto_sign_secretkey($par)));
    @chmod($dir . '/licencia_privada.key', 0600);
    file_put_contents($dir . '/licencia_publica.txt', base64_encode(sodium_crypto_sign_publickey($par)) . "\n");
    echo "Listo en {$dir}:\n  licencia_privada.key  SECRETA: guardala en un lugar seguro (con copia). Nunca al repositorio ni al chat.\n"
        . "  licencia_publica.txt  copiala a config/licencia_publica.txt del proyecto y subila.\n";
    exit(0);
}
if ($cmd === 'emitir') {
    $priv = (string) ($o['privada'] ?? '');
    $cliente = trim((string) ($o['cliente'] ?? ''));
    $cuit = preg_replace('/\D/', '', (string) ($o['cuit'] ?? ''));
    if (!is_file($priv) || $cliente === '' || strlen($cuit) !== 11) {
        $fallar('Faltan datos: --privada=<archivo>, --cliente="Razón social" y --cuit=<11 dígitos>.');
    }
    $hoy = new DateTimeImmutable('today');
    if (isset($o['vence'])) {
        $vence = (string) $o['vence'];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $vence) !== 1) {
            $fallar('--vence va como AAAA-MM-DD.');
        }
    } else {
        $vence = $hoy->modify('+' . max(1, (int) ($o['meses'] ?? 1)) . ' month')->format('Y-m-d');
    }
    $lic = Licencia::firmar(['cliente' => $cliente, 'cuit' => $cuit, 'vence' => $vence, 'emitida' => $hoy->format('Y-m-d')], trim((string) file_get_contents($priv)));
    $salida = (string) ($o['salida'] ?? ('licencia-' . $cuit . '-' . $vence . '.json'));
    file_put_contents($salida, json_encode($lic, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    echo "Licencia de {$cliente} (CUIT {$cuit}) vigente hasta {$vence}: {$salida}\n";
    exit(0);
}
if ($cmd === 'verificar') {
    $archivo = (string) ($resto[0] ?? '');
    $l = is_file($archivo) ? json_decode((string) file_get_contents($archivo), true) : null;
    if (!is_array($l)) {
        $fallar('Indicá el archivo de licencia: php bin/licencia.php verificar licencia.json');
    }
    $pub = isset($o['publica']) ? trim((string) file_get_contents((string) $o['publica'])) : Licencia::clavePublica();
    echo "Cliente: {$l['cliente']}\nCUIT: {$l['cuit']}\nEmitida: {$l['emitida']}\nVence: {$l['vence']}\nFirma: " . (Licencia::firmaValida($l, $pub) ? "VÁLIDA\n" : "NO VÁLIDA\n");
    exit(0);
}
$fallar("Comandos: clave, emitir, verificar. Ver el encabezado de bin/licencia.php.");
