<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Orden de las grillas con páginas: el parámetro "orden" es la clave de una columna ("precio") o la clave con
 * "_desc" ("precio_desc"). Cada lista declara sus columnas ordenables (clave => expresión SQL fija, nunca del
 * usuario) y su orden por omisión, que además desempata. Lo usan el título clickeable de la columna
 * (th_orden()) y el "Ordenar por" de los filtros.
 */
final class Orden
{
    /** @return array{0:string,1:bool} clave de la columna y si es descendente */
    public static function partes(string $orden): array
    {
        $desc = str_ends_with($orden, '_desc');
        return [$desc ? substr($orden, 0, -5) : $orden, $desc];
    }

    /**
     * ORDER BY para el orden pedido. Una expresión con varias partes ("v.marca, v.modelo") se invierte entera;
     * las partes "x IS NULL" no, así los vacíos quedan siempre al final.
     *
     * @param array<string,string> $columnas clave => expresión SQL
     */
    public static function sql(array $columnas, string $orden, string $porOmision): string
    {
        [$clave, $desc] = self::partes($orden);
        if (!isset($columnas[$clave])) {
            return $porOmision;
        }
        $partes = array_map('trim', explode(',', $columnas[$clave]));
        if ($desc) {
            $partes = array_map(static fn (string $p) => preg_match('/\bIS NULL$/i', $p) ? $p : $p . ' DESC', $partes);
        }
        return implode(', ', $partes) . ', ' . $porOmision;
    }
}
