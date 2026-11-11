<?php
declare(strict_types=1);

/*
 * Genera la clave privada y el pedido de certificado (.csr) para ARCA.
 *
 *   php bin/afip-csr.php --cuit=20114993191 --org="ioszpe enrique daniel" --cn="organizacion y sistemas" --salida=/ruta/afip_oys
 *
 * El --org y el --cn tienen que ser IGUALES al DN del alias en ARCA (Administración de certificados digitales → el alias → "DN"):
 * si se va a "Agregar certificado" a un alias que ya funciona, el .csr tiene que llevar el mismo DN para que herede la relación con
 * el servicio de factura electrónica.
 *
 * Deja en la carpeta de salida (que tiene que estar FUERA de public_html):
 *   <nombre>.key   clave privada (NO se comparte con nadie ni se sube a ARCA; va solo en config_afip.php)
 *   <nombre>.csr   pedido de certificado: este es el archivo que se sube a ARCA
 * y muestra el DN que quedó, para compararlo con el del alias. Nunca escribe la clave en pantalla.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$o = getopt('', ['cuit:', 'org:', 'cn:', 'pais::', 'salida:', 'nombre::', 'bits::']);
$cuit = preg_replace('/\D/', '', (string) ($o['cuit'] ?? ''));
$org = trim((string) ($o['org'] ?? ''));
$cn = trim((string) ($o['cn'] ?? ''));
$pais = strtoupper(trim((string) ($o['pais'] ?? 'AR')));
$salida = rtrim((string) ($o['salida'] ?? ''), '/\\');
$nombre = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($o['nombre'] ?? 'web')) ?: 'web';
$bits = (int) ($o['bits'] ?? 2048);

if (strlen($cuit) !== 11 || $org === '' || $cn === '' || $salida === '' || strlen($pais) !== 2) {
    fwrite(STDERR, "Uso: php bin/afip-csr.php --cuit=20123456789 --org=\"razón social del alias\" --cn=\"nombre común del alias\" --salida=/ruta/fuera/de/public_html [--pais=AR] [--nombre=web]\n");
    exit(2);
}
if (!is_dir($salida) && !mkdir($salida, 0700, true)) {
    fwrite(STDERR, "No se pudo crear {$salida}\n");
    exit(1);
}
$key = "{$salida}/{$nombre}.key";
$csrArchivo = "{$salida}/{$nombre}.csr";
foreach ([$key, $csrArchivo] as $f) {
    if (is_file($f)) {
        fwrite(STDERR, "Ya existe {$f}: no se pisa (si se pierde la clave hay que pedir otro certificado). Usá otro --nombre o borralo a propósito.\n");
        exit(1);
    }
}

// Configuración mínima de OpenSSL: la de fábrica agrega campos por omisión (ej. ST = Some-State) que cambiarían el DN del pedido.
$cnf = tempnam(sys_get_temp_dir(), 'cnf');
file_put_contents($cnf, "[req]\ndistinguished_name = req_dn\nprompt = no\n[req_dn]\nC = AR\n");
$dn = ['countryName' => $pais, 'organizationName' => $org, 'serialNumber' => 'CUIT ' . $cuit, 'commonName' => $cn];
$cfg = ['private_key_bits' => max(2048, $bits), 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256', 'config' => $cnf];
$k = openssl_pkey_new($cfg);
$csr = $k !== false ? openssl_csr_new($dn, $k, $cfg) : false;
@unlink($cnf);
if ($k === false || $csr === false || $csr === true) {
    fwrite(STDERR, 'No se pudo generar el pedido: ' . openssl_error_string() . "\n");
    exit(1);
}
openssl_pkey_export($k, $pem);
file_put_contents($key, $pem);
chmod($key, 0600);
openssl_csr_export($csr, $txt);
file_put_contents($csrArchivo, $txt);

$s = openssl_csr_get_subject($csr);
echo "Listo.\n  Clave privada (guardala, no se comparte): {$key}\n  Pedido para subir a ARCA:                  {$csrArchivo}\n";
echo "DN del pedido: C={$s['C']}, O={$s['O']}, SERIALNUMBER={$s['serialNumber']}, CN={$s['CN']}\n";
echo "Compará ese DN con el del alias en ARCA: tienen que ser iguales.\n";
