<?php
declare(strict_types=1);

// Recorrido de navegador: prende el portal, marca como fiscales los comprobantes del cliente E2E y deja un link de acceso. Imprime el token.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__, 2) . '/src/bootstrap.php';
App\App::boot();
$pdo = App\Core\Db::pdo();
$pdo->exec("UPDATE configuracion SET valor = '1' WHERE clave = 'portal.activo'");
$pdo->exec("UPDATE subdiario SET es_prueba = 0 WHERE cliente_razon_social = 'CLIENTE E2E SRL'");
$t = bin2hex(random_bytes(32));
$pdo->prepare('INSERT INTO portal_accesos (email, token_hash, vence) VALUES (?, ?, ?)')->execute(['facturas@e2e.com', hash('sha256', $t), date('Y-m-d H:i:s', time() + 600)]);
echo $t;
