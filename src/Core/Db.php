<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/** Conexión única a MySQL (PDO). Siempre usar consultas preparadas. */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = (array) Config::get('db');
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $c['host'],
                $c['port'],
                $c['name'],
                $c['charset']
            );
            $opciones = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Las consultas se preparan en PHP y no en el MySQL: en el hosting compartido el servidor descarta
                // seguido la estructura de las tablas y rechaza las preparadas allá con el error 1615
                // ("Prepared statement needs to be re-prepared"). Los datos siguen yendo como parámetros, escapados
                // por PDO con el charset de la conexión, y desde PHP 8.1 los números vuelven como int/float igual.
                PDO::ATTR_EMULATE_PREPARES   => true,
            ];
            // En el hosting compartido la base a veces rechaza una conexión un instante (demasiadas conexiones,
            // servidor reiniciando): se reintenta dos veces antes de dar error.
            for ($intento = 1; ; $intento++) {
                try {
                    $pdo = new PDO($dsn, (string) $c['user'], (string) $c['pass'], $opciones);
                    break;
                } catch (\PDOException $e) {
                    if ($intento >= 3 || !self::esPasajero($e)) {
                        throw $e;
                    }
                    usleep($intento * 400000);
                }
            }
            // Argentina no tiene horario de verano: UTC-3 fijo.
            $pdo->exec("SET time_zone = '-03:00'");
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }

    /** Error de conexión que suele pasar solo: 1040/1203 demasiadas conexiones, 2002/2006/2013 servidor no disponible. */
    public static function esPasajero(\PDOException $e): bool
    {
        $codigo = (int) ($e->errorInfo[1] ?? 0) ?: (int) $e->getCode();
        return in_array($codigo, [1040, 1203, 2002, 2006, 2013], true)
            || preg_match('/too many connections|max_user_connections|gone away|lost connection|connection refused/i', $e->getMessage()) === 1;
    }

    /** Fuerza reconectar (lo usan las pruebas). */
    public static function reset(): void
    {
        self::$pdo = null;
    }
}
