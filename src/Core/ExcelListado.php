<?php
declare(strict_types=1);

namespace App\Core;

use App\Http\Response;

/**
 * "Enviar a Excel" de las grillas con filtros: baja todo lo que respeta el filtro (no solo la página que se ve),
 * con las mismas columnas que el rol ve en pantalla. Los servicios, con $f['sin_paginar'], devuelven todas las filas
 * (hasta MAX); ese flag lo pone solo el controlador, nunca viene de la URL.
 */
final class ExcelListado
{
    public const MAX = 50000;

    /** LIMIT de la consulta: la página pedida, o todo (hasta MAX) para el Excel. */
    public static function limite(array $f, int $porPagina): string
    {
        if (!empty($f['sin_paginar'])) {
            return ' LIMIT ' . self::MAX;
        }
        $pagina = max(1, (int) ($f['pagina'] ?? 1));
        return ' LIMIT ' . $porPagina . ' OFFSET ' . (($pagina - 1) * $porPagina);
    }

    /** ¿Pidió el Excel? (?excel=1 en la misma dirección de la grilla) */
    public static function pedido(\App\Http\Request $req): bool
    {
        return ($req->query['excel'] ?? '') === '1';
    }

    /**
     * @param array<int,array{0:string,1:int,2:callable}> $columnas [título, ancho, fn(fila) => valor]
     * @param iterable<array<string,mixed>> $filas
     */
    public static function respuesta(string $archivo, string $hoja, array $columnas, iterable $filas): Response
    {
        $x = new XlsxWriter($hoja, array_column($columnas, 0), array_column($columnas, 1));
        foreach ($filas as $fila) {
            $x->fila(array_map(static fn ($c) => $c[2]($fila), $columnas));
        }
        return new Response(200, $x->contenido(), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $archivo . '-' . date('Ymd-Hi') . '.xlsx"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Importe como número (para que Excel sume), o vacío. */
    public static function numero(mixed $v): float|string
    {
        return $v === null || $v === '' ? '' : round((float) $v, 2);
    }
}
