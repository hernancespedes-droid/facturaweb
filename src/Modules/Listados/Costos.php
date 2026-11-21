<?php
declare(strict_types=1);

namespace App\Modules\Listados;

use PDO;

/**
 * Costo de los artículos en pesos. El costo de la ficha está en la moneda del artículo (`articulos.moneda`, '$' o 'u$s'); el de dólares se pasa con la
 * última cotización hasta la fecha (`cotizacion_dolar`, 1 si no hay ninguna).
 *
 * DECISIÓN D-62 (Claude, aprobada por Hernán el 21/11/2026 junto con la propuesta de listados): al emitir, cada renglón con artículo guarda en
 * `estadistica.costo` el costo unitario en pesos de ese momento (como hacía el VFP), así el margen de la venta queda fijo aunque después cambie el costo.
 */
final class Costos
{
    /** Última cotización del dólar hasta la fecha (1 si no hay). */
    public static function cotizacion(PDO $pdo, string $fecha): float
    {
        $st = $pdo->prepare('SELECT importe FROM cotizacion_dolar WHERE fecha <= ? ORDER BY fecha DESC LIMIT 1');
        $st->execute([$fecha]);
        $v = $st->fetchColumn();
        return $v === false || (float) $v <= 0 ? 1.0 : (float) $v;
    }

    /** @return array{0:float,1:?string} la cotización a la fecha y la fecha de esa cotización (null si no hay ninguna) */
    public static function cotizacionConFecha(PDO $pdo, string $fecha): array
    {
        $st = $pdo->prepare('SELECT importe, fecha FROM cotizacion_dolar WHERE fecha <= ? ORDER BY fecha DESC LIMIT 1');
        $st->execute([$fecha]);
        $r = $st->fetch(PDO::FETCH_NUM);
        return $r === false || (float) $r[0] <= 0 ? [1.0, null] : [(float) $r[0], (string) $r[1]];
    }

    /**
     * Costo unitario en pesos de cada artículo, a la fecha.
     * @param string[] $codigos @return array<string,float> código => costo en pesos (solo los que existen)
     */
    public static function unitarios(PDO $pdo, array $codigos, string $fecha): array
    {
        $codigos = array_values(array_unique(array_filter(array_map('strval', $codigos), static fn (string $c) => $c !== '')));
        if ($codigos === []) {
            return [];
        }
        $st = $pdo->prepare('SELECT codigo, costo, moneda FROM articulos WHERE codigo IN (' . implode(', ', array_fill(0, count($codigos), '?')) . ')');
        $st->execute($codigos);
        $cot = null;
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $c = (float) $a['costo'];
            if ($a['moneda'] === 'u$s' && $c > 0) {
                $cot ??= self::cotizacion($pdo, $fecha);
                $c *= $cot;
            }
            $out[(string) $a['codigo']] = round($c, 4);
        }
        return $out;
    }

    /** Expresión SQL del costo unitario en pesos HOY de un artículo (alias $a), con la cotización ya leída (número, no viene del usuario). */
    public static function sqlEnPesos(string $a, float $cotizacion): string
    {
        $cot = number_format($cotizacion, 4, '.', '');
        return "({$a}.costo * IF({$a}.moneda = 'u\$s', {$cot}, 1))";
    }
}
