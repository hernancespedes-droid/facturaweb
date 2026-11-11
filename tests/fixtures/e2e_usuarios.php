<?php
declare(strict_types=1);

/*
 * Usuarios y datos de las pruebas de navegador (base de desarrollo).
 *   php tests/fixtures/e2e_usuarios.php <clave>   crea e2e_admin (Administrador) y e2e_operador (Operador)
 *   php tests/fixtures/e2e_usuarios.php --limpiar borra lo que cargó el recorrido (todo lo "E2E")
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\App;
use App\Core\Db;

App::boot();
$pdo = Db::pdo();

// Los datos de la empresa (Tablas -> Parámetros) se guardan antes del recorrido y se devuelven al final.
$copia = sys_get_temp_dir() . '/facturaweb_e2e_parametros.json';
$limpiar = static function () use ($pdo, $copia): void {
    if (is_file($copia)) {
        $p = json_decode((string) file_get_contents($copia), true);
        $pdo->prepare('UPDATE parametros SET empresa = ?, cuit = ?, iva = ? WHERE id = 1')->execute([$p['empresa'], $p['cuit'], $p['iva']]);
        foreach ($p['sucursales'] ?? [] as $codigo => $num) {
            $pdo->prepare('UPDATE sucursales SET ultima_factura_a = ?, ultima_factura_b = ?, ultima_factura_c = ?, ultima_nc_a = ?, ultima_nc_b = ?, ultima_nc_c = ?, ultima_nd_a = ?, ultima_nd_b = ?, ultima_nd_c = ? WHERE codigo = ?')
                ->execute([...array_values($num), $codigo]);
        }
        unlink($copia);
    }
    // Comprobantes que emitió el recorrido (los clientes "E2E") y todo lo que cuelga de ellos
    foreach (['subdiario' => 'cliente_razon_social LIKE \'%E2E%\''] as $t => $w) {
        $claves = $pdo->query("SELECT tipo, letra, sucursal, comprobante FROM subdiario WHERE {$w}")->fetchAll();
        foreach ($claves as $k) {
            foreach (['iva' => 'comprobante', 'estadistica' => 'comprobante', 'caja' => 'comprobante', 'ctact' => 'numero', 'subdiario' => 'comprobante'] as $tabla => $col) {
                $pdo->prepare("DELETE FROM {$tabla} WHERE tipo = ? AND letra = ? AND sucursal = ? AND {$col} = ?")->execute([$k['tipo'], $k['letra'], $k['sucursal'], $k['comprobante']]);
            }
        }
    }
    $pdo->exec("DELETE FROM clientes WHERE razon_social LIKE '%E2E%'");
    $pdo->exec("DELETE FROM articulos WHERE codigo LIKE 'E2E%'");
    $pdo->exec("DELETE FROM vendedores WHERE descripcion LIKE '%E2E%'");
    $pdo->exec("DELETE FROM usuarios WHERE usuario LIKE 'e2e\\_%'");
};
$limpiar();
if (($argv[1] ?? '') === '--limpiar') {
    exit(0);
}
$guardar = $pdo->query('SELECT empresa, cuit, iva FROM parametros WHERE id = 1')->fetch();
$guardar['sucursales'] = [];
foreach ($pdo->query('SELECT codigo, ultima_factura_a, ultima_factura_b, ultima_factura_c, ultima_nc_a, ultima_nc_b, ultima_nc_c, ultima_nd_a, ultima_nd_b, ultima_nd_c FROM sucursales')->fetchAll() as $suc) {
    $codigo = $suc['codigo'];
    unset($suc['codigo']);
    $guardar['sucursales'][$codigo] = $suc;
}
file_put_contents($copia, json_encode($guardar));
$clave = (string) ($argv[1] ?? '');
$ins = $pdo->prepare('INSERT INTO usuarios (usuario, nombre, password_hash, rol_id) SELECT ?, ?, ?, id FROM roles WHERE codigo = ?');
$ins->execute(['e2e_admin', 'Admin E2E', password_hash($clave, PASSWORD_DEFAULT), 'admin']);
$ins->execute(['e2e_operador', 'Operador E2E', password_hash($clave, PASSWORD_DEFAULT), 'operador']);
