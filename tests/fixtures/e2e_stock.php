<?php
declare(strict_types=1);

/*
 * Prende o apaga el control de stock y un segundo depósito para el recorrido de navegador (base de desarrollo).
 *   php tests/fixtures/e2e_stock.php on    activa stock.controlar y crea el depósito «E2E SEGUNDO»
 *   php tests/fixtures/e2e_stock.php off   los deja como estaban
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
$on = ($argv[1] ?? '') === 'on';
$pdo->exec("UPDATE configuracion SET valor = '" . ($on ? '1' : '0') . "' WHERE clave = 'stock.controlar'");
if ($on) {
    (new \App\Modules\Stock\StockService())->guardarDeposito(null, 'E2', 'E2E SEGUNDO', false, true);
} else {
    $pdo->exec("DELETE FROM depositos WHERE codigo = 'E2'");
}
