<?php
declare(strict_types=1);

/*
 * Arranque común: autoloader propio (sin depender de Composer) + funciones auxiliares.
 * Si más adelante se suma alguna librería con Composer, vendor/autoload.php se carga solo.
 */

spl_autoload_register(static function (string $class): void {
    $map = [
        'App\\'   => __DIR__ . '/',
        'Tests\\' => dirname(__DIR__) . '/tests/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

require_once __DIR__ . '/helpers.php';

$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}
