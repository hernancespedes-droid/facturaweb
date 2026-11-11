<?php
declare(strict_types=1);

/*
 * Corredor de pruebas.
 *   php tests/run.php                 corre todas
 *   php tests/run.php Router          corre solo las clases cuyo nombre contiene "Router"
 *
 * Siempre usa la base *_test, nunca la de desarrollo ni la de producción.
 * Devuelve código de salida 0 si todo pasó y 1 si algo falló (sirve para automatizar).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

use Tests\AssertionFailed;
use Tests\TestCase;

// Entorno de prueba (pisa cualquier otra cosa para no tocar datos reales).
$forced = [
    'APP_ENV' => 'testing',
    'DB_HOST' => env('DB_HOST', '127.0.0.1'),
    'DB_PORT' => env('DB_PORT', '3306'),
    'DB_NAME' => 'facturaweb_test',
    'DB_USER' => env('DB_USER', 'facturaweb'),
    'DB_PASS' => env('DB_PASS', 'facturaweb_dev'),
];
foreach ($forced as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $_SERVER[$key] = $value;
}

$filter = $argv[1] ?? '';
$total = 0;
$failed = 0;
$started = microtime(true);

$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files, SORT_STRING);

foreach ($files as $file) {
    $class = 'Tests\\' . basename($file, '.php');
    if ($filter !== '' && !str_contains($class, $filter)) {
        continue;
    }
    if (!is_subclass_of($class, TestCase::class)) {
        continue;
    }
    $methods = array_filter(get_class_methods($class), static fn (string $m): bool => str_starts_with($m, 'test'));
    foreach ($methods as $method) {
        $total++;
        $label = basename(str_replace('\\', '/', $class)) . '::' . $method;
        try {
            (new $class())->runTest($method);
            echo "  OK    {$label}\n";
        } catch (AssertionFailed $e) {
            $failed++;
            echo "  FALLA {$label}\n        {$e->getMessage()}\n";
        } catch (\Throwable $e) {
            $failed++;
            echo "  ERROR {$label}\n        " . get_class($e) . ': ' . $e->getMessage()
                . "\n        en " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
        }
    }
}

printf(
    "\n%d pruebas, %d con problemas (%.2f s)\n",
    $total,
    $failed,
    microtime(true) - $started
);
exit($failed === 0 && $total > 0 ? 0 : 1);
