<?php
declare(strict_types=1);

namespace App\Modules\Listados;

use App\Core\Db;
use App\Core\Orden;
use App\Core\Valida;
use App\Modules\Comprobantes\Calculadora;
use App\Modules\Comprobantes\Tipos;

/**
 * Estadística de ventas (y rentabilidad): los renglones vendidos (`estadistica`) agrupados como en el VFP (`l_estadisticas`, `l_ranking`).
 *
 * DECISIÓN D-63 (Claude, aprobada por Hernán con la propuesta del 21/11/2026):
 *  - Entran facturas, notas de débito y de crédito, presupuestos, notas de crédito X y cambios (PR/NX/CM son ventas); «Solo fiscales» deja afuera los internos.
 *  - La factura que sale de un presupuesto (solo_subdiario = 1) NO entra: lo vendido ya está en el presupuesto (no se cuenta dos veces).
 *  - Las NC y NX restan; los renglones del cambio ya vienen con signo (lo devuelto en negativo).
 *  - Rubro, subrubro y proveedor son los de la ficha del artículo hoy (el renglón no los guarda).
 *  - Costo: el grabado en el renglón al emitir (D-62); si el renglón no lo tiene (ventas anteriores a 0.32.0) se usa el costo de hoy y se marca «estimado».
 */
final class Estadistica
{
    public const TIPOS = ['FC', 'ND', 'NC', 'PR', 'NX', 'CM'];

    /** Agrupaciones: clave => [título, expresión del grupo, expresión del nombre]. */
    public const AGRUPAR = [
        'articulo' => ['Artículo', "COALESCE(e.articulo, '')", "MAX(COALESCE(a.descripcion, IF(e.articulo IS NULL, 'Renglones sin artículo', e.observaciones)))"],
        'rubro' => ['Rubro', "COALESCE(a.rubro, '')", "MAX(COALESCE(ru.descripcion, IF(a.rubro IS NULL, 'Sin rubro', '')))"],
        'subrubro' => ['Rubro y subrubro', "CONCAT(COALESCE(a.rubro, ''), ' / ', COALESCE(a.subrubro, ''))", "MAX(CONCAT(COALESCE(ru.descripcion, 'Sin rubro'), ' / ', COALESCE(sr.descripcion, 'Sin subrubro')))"],
        'proveedor' => ['Proveedor', "COALESCE(CAST(a.proveedor AS CHAR), '')", "MAX(IF(a.proveedor IS NULL OR a.proveedor = 0, 'Sin proveedor', CONCAT('Proveedor ', a.proveedor)))"],
        'cliente' => ['Cliente', 'CAST(s.cliente AS CHAR)', 'MAX(COALESCE(s.cliente_razon_social, c.razon_social))'],
        'vendedor' => ['Vendedor', "COALESCE(s.vendedor, '')", "MAX(COALESCE(ve.descripcion, IF(s.vendedor IS NULL OR s.vendedor = '', 'Sin vendedor', s.vendedor)))"],
        'sucursal' => ['Sucursal', 'CAST(s.sucursal AS CHAR)', 'MAX(su.descripcion)'],
        'mes' => ['Mes', "DATE_FORMAT(s.fecha, '%Y-%m')", "MAX(DATE_FORMAT(s.fecha, '%m/%Y'))"],
    ];

    private const SIGNO = "IF(s.tipo IN ('NC', 'NX'), -1, 1)";
    private const ORDEN_DETALLE = ['fecha' => 's.fecha', 'comprobante' => 's.tipo, s.letra, s.prefijo, numero', 'cliente' => 'razon_social', 'articulo' => 'e.articulo',
        'unidades' => 'unidades', 'neto' => 'neto', 'total' => 'total', 'costo' => 'costo', 'margen' => 'margen'];

    /** @param array<string,string> $f @return array{0:string,1:array<int,mixed>} */
    private static function filtro(array $f): array
    {
        $w = ["s.estado = ''", 's.solo_subdiario = 0', "s.tipo IN ('" . implode("', '", self::TIPOS) . "')"];
        $p = [];
        if (($d = Valida::fecha((string) ($f['desde'] ?? ''))) !== null) {
            $w[] = 's.fecha >= ?';
            $p[] = $d;
        }
        if (($d = Valida::fecha((string) ($f['hasta'] ?? ''))) !== null) {
            $w[] = 's.fecha <= ?';
            $p[] = $d;
        }
        if (in_array($f['tipo'] ?? '', self::TIPOS, true)) {
            $w[] = 's.tipo = ?';
            $p[] = $f['tipo'];
        }
        if (($f['fiscales'] ?? '') === '1') {
            $w[] = Tipos::sqlSoloFiscales('s');
        }
        foreach (['sucursal' => 's.sucursal', 'cliente' => 's.cliente', 'proveedor' => 'a.proveedor'] as $k => $col) {
            if (ctype_digit((string) ($f[$k] ?? ''))) {
                $w[] = "{$col} = ?";
                $p[] = (int) $f[$k];
            }
        }
        foreach (['articulo' => 'e.articulo', 'rubro' => 'a.rubro', 'subrubro' => 'a.subrubro', 'vendedor' => 's.vendedor'] as $k => $col) {
            $v = trim((string) ($f[$k] ?? ''));
            if ($v !== '') {
                $w[] = "{$col} = ?";
                $p[] = $k === 'articulo' ? mb_strtoupper($v) : $v;
            }
        }
        return [' WHERE ' . implode(' AND ', $w), $p];
    }

    private static function from(): string
    {
        return ' FROM estadistica e JOIN subdiario s ON s.fe_clave = e.subdiario_id LEFT JOIN articulos a ON a.codigo = e.articulo
            LEFT JOIN clientes c ON c.codigo = s.cliente LEFT JOIN rubros ru ON ru.codigo = a.rubro LEFT JOIN subrubros sr ON sr.rubro = a.rubro AND sr.codigo = a.subrubro
            LEFT JOIN vendedores ve ON ve.codigo = s.vendedor LEFT JOIN sucursales su ON su.codigo = s.sucursal';
    }

    /** Expresiones de cada renglón, con signo: unidades, neto, total con IVA, costo, si el costo es estimado. @return array<string,string> */
    private static function medidas(): array
    {
        $sg = self::SIGNO;
        $hoy = Costos::sqlEnPesos('a', Costos::cotizacion(Db::pdo(), date('Y-m-d')));
        $unit = "IF(e.costo > 0, e.costo, IF(a.codigo IS NULL, 0, {$hoy}))";
        return [
            'unidades' => "e.unidades * {$sg}",
            'neto' => "e.total_siniva * {$sg}",
            // A, M y E: el renglón es neto y se le suma el IVA; B, C y X: el renglón ya es el precio final (menos el descuento general)
            'total' => "IF(s.letra IN ('A', 'M', 'E'), e.total_siniva * (1 + IF(e.grabado = 1, e.por_iva, 0) / 100), e.total_item * (1 - e.porcentaje_descuento / 100)) * {$sg}",
            'costo' => "{$unit} * e.unidades * {$sg}",
            'estimado' => "IF(e.costo = 0 AND a.codigo IS NOT NULL AND a.costo > 0, 1, 0)",
            'sin_costo' => "IF(e.costo = 0 AND (a.codigo IS NULL OR a.costo = 0), 1, 0)",
        ];
    }

    /**
     * Resumen agrupado (sin páginas: lo ordena grilla.js). Con 'primeros' = N y 'por' = cantidad|importe|margen, el ranking: los N primeros.
     * @param array<string,string> $f
     * @return array{filas:array<int,array<string,mixed>>,totales:array<string,float|int>,agrupar:string}
     */
    public static function resumen(array $f): array
    {
        $ag = isset(self::AGRUPAR[$f['agrupar'] ?? '']) ? (string) $f['agrupar'] : 'articulo';
        [, $grupo, $nombre] = self::AGRUPAR[$ag];
        [$where, $p] = self::filtro($f);
        $m = self::medidas();
        $por = ['cantidad' => 'unidades DESC', 'importe' => 'neto DESC', 'margen' => '(neto - costo) DESC'][$f['por'] ?? ''] ?? ($ag === 'mes' ? 'grupo' : 'neto DESC');
        $n = (int) ($f['primeros'] ?? 0);
        $limite = $n > 0 ? ' LIMIT ' . min($n, 10000) : '';
        $sql = "SELECT {$grupo} AS grupo, {$nombre} AS nombre, SUM({$m['unidades']}) AS unidades, SUM({$m['neto']}) AS neto, SUM({$m['total']}) AS total,
                SUM({$m['costo']}) AS costo, COUNT(DISTINCT s.fe_clave) AS comprobantes, SUM({$m['estimado']}) AS estimados, SUM({$m['sin_costo']}) AS sin_costo"
            . self::from() . $where . " GROUP BY grupo ORDER BY {$por}, grupo{$limite}";
        $st = Db::pdo()->prepare($sql);
        $st->execute($p);
        $filas = $st->fetchAll(\PDO::FETCH_ASSOC);
        // totales del período (de todo lo filtrado, aunque el ranking muestre solo los primeros)
        $st = Db::pdo()->prepare("SELECT SUM({$m['unidades']}), SUM({$m['neto']}), SUM({$m['total']}), SUM({$m['costo']}), COUNT(DISTINCT s.fe_clave), SUM({$m['estimado']}), SUM({$m['sin_costo']}), COUNT(*)"
            . self::from() . $where);
        $st->execute($p);
        $t = $st->fetch(\PDO::FETCH_NUM);
        $tot = ['unidades' => round((float) $t[0], 3), 'neto' => Calculadora::r2((float) $t[1]), 'total' => Calculadora::r2((float) $t[2]), 'costo' => Calculadora::r2((float) $t[3]),
            'comprobantes' => (int) $t[4], 'estimados' => (int) $t[5], 'sin_costo' => (int) $t[6], 'renglones' => (int) $t[7]];
        $tot['margen'] = Calculadora::r2($tot['neto'] - $tot['costo']);
        foreach ($filas as &$x) {
            foreach (['neto', 'total', 'costo'] as $k) {
                $x[$k] = Calculadora::r2((float) $x[$k]);
            }
            $x['unidades'] = round((float) $x['unidades'], 3);
            $x['margen'] = Calculadora::r2($x['neto'] - $x['costo']);
            $x['margen_pct'] = self::pct($x['margen'], $x['neto']);
            $x['pct_venta'] = self::pct($x['neto'], $tot['neto']);
            $x['pct_margen'] = self::pct($x['margen'], $tot['margen']);
            $x['precio_promedio'] = abs($x['unidades']) > 0.0005 ? round($x['neto'] / $x['unidades'], 2) : null;
        }
        unset($x);
        $tot['margen_pct'] = self::pct($tot['margen'], $tot['neto']);
        return ['filas' => $filas, 'totales' => $tot, 'agrupar' => $ag];
    }

    /**
     * Detalle: un renglón por ítem vendido, con páginas.
     * @param array<string,string> $f
     * @return array{filas:array<int,array<string,mixed>>,total:int}
     */
    public static function detalle(array $f, int $pagina, int $porPagina): array
    {
        [$where, $p] = self::filtro($f);
        $m = self::medidas();
        $pdo = Db::pdo();
        $st = $pdo->prepare('SELECT COUNT(*)' . self::from() . $where);
        $st->execute($p);
        $total = (int) $st->fetchColumn();
        $nro = Tipos::sqlNumeroVisible('s');
        $desde = max(0, ($pagina - 1) * $porPagina);
        $st = $pdo->prepare("SELECT s.fe_clave, s.fecha, s.tipo, s.letra, s.prefijo, {$nro} AS numero, COALESCE(s.cliente_razon_social, c.razon_social) AS razon_social,
                e.articulo, COALESCE(NULLIF(e.observaciones, ''), a.descripcion) AS descripcion, e.precio, e.porcentaje_descuento,
                {$m['unidades']} AS unidades, {$m['neto']} AS neto, {$m['total']} AS total, {$m['costo']} AS costo, {$m['neto']} - {$m['costo']} AS margen, {$m['estimado']} AS estimado"
            . self::from() . $where . ' ORDER BY ' . Orden::sql(self::ORDEN_DETALLE, (string) ($f['orden'] ?? ''), 's.fecha DESC, s.fe_clave DESC, e.orden') . " LIMIT {$porPagina} OFFSET {$desde}");
        $st->execute($p);
        return ['filas' => $st->fetchAll(\PDO::FETCH_ASSOC), 'total' => $total];
    }

    private static function pct(float $parte, float $total): ?float
    {
        return abs($total) < 0.005 ? null : round($parte / $total * 100, 2);
    }
}
