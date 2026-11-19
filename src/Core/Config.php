<?php
declare(strict_types=1);

namespace App\Core;

final class Config
{
    /** @var array<string,mixed> */
    private static array $data = [];

    /** @param array<string,mixed> $data */
    public static function load(array $data): void
    {
        self::$data = $data;
    }

    /** Cambia un valor con notación de puntos (las pruebas lo usan para simular configuraciones del servidor). */
    public static function set(string $key, mixed $value): void
    {
        $ref = &self::$data;
        foreach (explode('.', $key) as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
        $ref = $value;
    }

    /** Lee un valor con notación de puntos: Config::get('db.host'). */
    public static function get(string $key, mixed $default = null): mixed
    {
        $cur = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return $default;
            }
            $cur = $cur[$part];
        }
        return $cur;
    }
}
