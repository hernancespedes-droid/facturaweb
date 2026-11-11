<?php
declare(strict_types=1);

/*
 * Migra los datos del sistema VFP (carpeta con los DBF y datos.dbc) a la base del facturador.
 *
 *   php bin/migrar-dbf.php <carpeta>                    todas las tablas de la etapa 1
 *   php bin/migrar-dbf.php <carpeta> --simular          hace todo y lo deshace: para ver los avisos antes
 *   php bin/migrar-dbf.php <carpeta> --reemplazar       borra lo que ya haya en esas tablas y carga lo del VFP
 *   php bin/migrar-dbf.php <carpeta> --tablas=clientes,articulos
 *   php bin/migrar-dbf.php <carpeta> --sql=migracion.sql   además deja todo en un .sql (para phpMyAdmin)
 *
 * Usa la base de config/local.php (o las variables DB_*). Ver docs/migracion-dbf.md.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\App;
use App\Core\Db;
use App\Modules\Migracion\MigradorVfp;

App::boot();
ini_set('memory_limit', '512M');

$carpeta = null;
$opts = ['simular' => false, 'reemplazar' => false, 'tablas' => null, 'sql' => null];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--simular' || $a === '--reemplazar') {
        $opts[substr($a, 2)] = true;
    } elseif (str_starts_with($a, '--tablas=')) {
        $opts['tablas'] = array_values(array_filter(array_map('trim', explode(',', substr($a, 9)))));
    } elseif (str_starts_with($a, '--sql=')) {
        $opts['sql'] = substr($a, 6);
    } elseif (!str_starts_with($a, '--') && $carpeta === null) {
        $carpeta = $a;
    } else {
        fwrite(STDERR, "Opción desconocida: {$a}\n");
        exit(2);
    }
}
if ($carpeta === null) {
    fwrite(STDERR, "Uso: php bin/migrar-dbf.php <carpeta de los DBF> [--simular] [--reemplazar] [--tablas=a,b] [--sql=archivo.sql]\n"
        . 'Tablas: ' . implode(', ', array_keys(MigradorVfp::TABLAS)) . "\n");
    exit(2);
}

try {
    $resumen = (new MigradorVfp(Db::pdo(), $carpeta))->migrar($opts['tablas'], $opts['reemplazar'], $opts['simular'], $opts['sql']);
} catch (\Throwable $e) {
    fwrite(STDERR, 'No se migró nada: ' . $e->getMessage() . "\n");
    exit(1);
}

$avisos = 0;
foreach ($resumen as $tabla => $r) {
    printf("%-18s %6d leídos %6d grabados%s\n", $tabla, $r['leidas'], $r['grabadas'], $r['avisos'] !== [] ? '   (' . count($r['avisos']) . ' aviso/s)' : '');
}
foreach ($resumen as $tabla => $r) {
    foreach ($r['avisos'] as $a) {
        if ($avisos++ === 0) {
            echo "\nAvisos:\n";
        }
        echo "  [{$tabla}] {$a}\n";
    }
}
echo $opts['simular'] ? "\nSIMULACIÓN: no se grabó nada.\n" : "\nMigración grabada.\n";
if ($opts['sql'] !== null) {
    echo "SQL escrito en {$opts['sql']}.\n";
}
