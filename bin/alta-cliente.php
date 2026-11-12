<?php
declare(strict_types=1);

/*
 * Prepara el alta de una instalación nueva (un cliente nuevo de la suscripción). No toca ningún servidor: deja en una carpeta los archivos
 * listos para subir y la lista de pasos con los datos del cliente ya completados.
 *
 *   php bin/alta-cliente.php --nombre="Razón social SA" --cuit=30123456789 --dominio=cliente.com.ar --salida=<carpeta fuera del repo> [--slug=cliente] [--pv=1]
 *
 * Genera en <salida>/<slug>/ :
 *   config/local.php  configuración de la instalación (clave de instalación al azar, licencia exigida, ARCA en modo interno); falta la base y el mail
 *   PASOS.md          los pasos del alta con la URL, las carpetas y los comandos de este cliente (ver docs/alta-de-cliente.md)
 *   ficha.md          ficha del cliente para completar durante el alta (guardala en Drive, NO en el repo: lleva datos del cliente)
 * No escribe contraseñas: la base y el mail se completan a mano. Si la carpeta del cliente ya existe, no la pisa.
 * Plantillas en docs/clientes/ (_pasos.md y _plantilla.md); {{nombre}}, {{cuit}}, {{slug}}, {{dominio}}, {{pv}}, {{token}} se reemplazan.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$o = getopt('', ['nombre:', 'cuit:', 'dominio:', 'salida:', 'slug::', 'pv::']);
$fallar = static function (string $m): never {
    fwrite(STDERR, "Error: {$m}\n");
    exit(1);
};
$nombre = trim((string) ($o['nombre'] ?? ''));
$cuit = preg_replace('/\D/', '', (string) ($o['cuit'] ?? ''));
$dominio = strtolower(trim((string) ($o['dominio'] ?? '')));
$salida = rtrim((string) ($o['salida'] ?? ''), '/\\');
$slug = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) ($o['slug'] ?? '')) ?: '');
if ($slug === '') {
    $slug = substr(strtolower(preg_replace('/[^a-z0-9]+/i', '', iconv('UTF-8', 'ASCII//TRANSLIT', $nombre) ?: $nombre) ?: ''), 0, 12);
}
$pv = max(1, (int) ($o['pv'] ?? 1));
if ($nombre === '' || strlen($cuit) !== 11 || $dominio === '' || $slug === '' || $salida === '' || !is_dir($salida)) {
    $fallar('Faltan datos: --nombre, --cuit (11 dígitos), --dominio y --salida (una carpeta existente, fuera del repositorio).');
}
$raiz = dirname(__DIR__);
if (str_starts_with(realpath($salida) ?: $salida, $raiz)) {
    $fallar('La carpeta de salida no puede estar dentro del repositorio: lleva datos del cliente y una clave de instalación.');
}
$dir = $salida . '/' . $slug;
if (file_exists($dir)) {
    $fallar("Ya existe {$dir}: no se pisa.");
}
$token = bin2hex(random_bytes(20));
$vars = ['{{nombre}}' => $nombre, '{{cuit}}' => $cuit, '{{slug}}' => $slug, '{{dominio}}' => $dominio, '{{pv}}' => (string) $pv, '{{token}}' => $token,
    '{{version}}' => trim((string) @file_get_contents($raiz . '/VERSION')), '{{fecha}}' => date('d/m/Y')];
mkdir($dir . '/config', 0775, true);

$local = file_get_contents($raiz . '/config/local.example.php');
$local = str_replace("'instalar_token' => '',", "'instalar_token' => '{$token}',   // BORRAR (dejar vacío) después de crear el Master", $local);
$local = str_replace("'config' => '',          // ruta al config_afip.php del cliente, FUERA de public_html", "'config' => '/RUTA/DEL/HOSTING/afip_{$slug}/config_afip.php',   // FUERA de public_html", $local);
$local = str_replace("    'db' => [", "    // Suscripción mensual: sin licencia vigente (firmada por O&S, Sistema → Licencia) no se emiten comprobantes.\n    'licencia' => ['exigir' => true],\n    'db' => [", $local);
file_put_contents($dir . '/config/local.php', $local);
foreach (['_pasos.md' => 'PASOS.md', '_plantilla.md' => 'ficha.md'] as $origen => $destino) {
    file_put_contents($dir . '/' . $destino, strtr((string) file_get_contents($raiz . '/docs/clientes/' . $origen), $vars));
}
echo "Listo: {$dir}\n  config/local.php  (falta la base y el mail; la clave de instalación ya está puesta)\n  PASOS.md          seguí esta lista\n  ficha.md          completala durante el alta y guardala en Drive\n";
