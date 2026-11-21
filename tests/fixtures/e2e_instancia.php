<?php
declare(strict_types=1);

/*
 * Instalación de cliente de prueba para e2e/usuarios-clientes.js (base facturaweb_test_inst, que se vacía).
 *   php tests/fixtures/e2e_instancia.php crear     la crea (master «e2emaster») y deja un operador «cajera» inactivo; imprime JSON
 *   php tests/fixtures/e2e_instancia.php --limpiar la da de baja y deja storage/instancias como estaba
 * Si la base no existe imprime {"ok":false} y el recorrido se saltea.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\App;
use App\Core\Config;
use App\Core\Db;
use App\Modules\Instancias\InstanciasService;

App::boot();
const HOST = 'e2eusu.prueba.test';
$principal = App::root() . '/storage/instancias/_principal.php';
$respaldo = App::storage('e2e_principal.respaldo');

if (($argv[1] ?? '') === '--limpiar') {
    if (is_dir(InstanciasService::raiz() . '/' . HOST)) {
        InstanciasService::baja(HOST, HOST, 'e2e');
    }
    if (is_file($respaldo)) {
        $previo = (string) file_get_contents($respaldo);
        $previo === '' ? @unlink($principal) : file_put_contents($principal, $previo);
        unlink($respaldo);
    }
    $b = InstanciasService::raiz() . '/_bajas.json';
    $bajas = array_values(array_filter(json_decode((string) @file_get_contents($b), true) ?: [], static fn ($x) => ($x['host'] ?? '') !== HOST));
    $bajas === [] ? @unlink($b) : file_put_contents($b, json_encode($bajas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $pdo = Db::pdo();
    $pdo->exec("DELETE FROM clientes WHERE cuit = 30712345671");
    $pdo->prepare('DELETE FROM instancias_resets WHERE instancia = ?')->execute([HOST]);
    $pdo->prepare('DELETE m FROM soporte_mensajes m JOIN soporte_tickets t ON t.id = m.ticket_id WHERE t.instancia = ?')->execute([HOST]);
    $pdo->prepare('DELETE FROM soporte_tickets WHERE instancia = ?')->execute([HOST]);
    exit(0);
}

$db = ['host' => Config::get('db.host'), 'port' => Config::get('db.port'), 'name' => 'facturaweb_test_inst', 'user' => Config::get('db.user'), 'pass' => Config::get('db.pass')];
try {
    $pdo = InstanciasService::conectar($db);
} catch (\PDOException) {
    echo json_encode(['ok' => false]);
    exit(0);
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $t) {
    $pdo->exec('DROP TABLE `' . str_replace('`', '', (string) $t) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
if (!is_file($respaldo)) {
    file_put_contents($respaldo, is_file($principal) ? (string) file_get_contents($principal) : '');
}
$r = InstanciasService::crear(['nombre' => 'Cliente E2E SA', 'cuit' => '30-71234567-1', 'iva' => '1', 'subdominio' => 'e2eusu', 'dominio' => 'prueba.test',
    'db_host' => $db['host'], 'db_port' => (string) $db['port'], 'db_name' => $db['name'], 'db_user' => $db['user'], 'db_pass' => $db['pass'],
    'master_usuario' => 'e2emaster', 'master_clave' => 'ClaveMaster123', 'master_confirmacion' => 'ClaveMaster123', 'admin_usuario' => 'admin'], '127.0.0.1', 'e2e');
if (!$r['ok']) {
    fwrite(STDERR, json_encode($r, JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}
$pdo->exec("INSERT INTO usuarios (usuario, nombre, password_hash, rol_id, activo) SELECT 'cajera', 'Ana Caja', 'x', id, 0 FROM roles WHERE codigo = 'operador'");
$pdo->exec("UPDATE usuarios SET intentos_fallidos = 5, bloqueado_hasta = NOW() + INTERVAL 1 HOUR WHERE usuario = 'admin'");
Db::pdo()->prepare("INSERT INTO soporte_tickets (instancia, instancia_nombre, asunto, tipo, estado, usuario, creado_en, actualizado_en) VALUES (?, 'Cliente E2E SA', 'E2E No puedo entrar', 'consulta', 'nuevo', 'admin', NOW(), NOW())")
    ->execute([HOST]);
echo json_encode(['ok' => true, 'host' => HOST, 'ticket' => (int) Db::pdo()->lastInsertId()]);
