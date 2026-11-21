<?php
declare(strict_types=1);

namespace App\Modules\Stock;

use App\Core\Db;
use App\Core\Orden;
use App\Core\Valida;
use App\Modules\Listados\Costos;

/**
 * Existencias por depósito a una fecha: una columna por depósito y el total (reemplaza `l_stock_por_sucursal`, `..._valorizado` y `l_stock_bajo_minimo`).
 * Hoy sale de `stock_saldos`; a una fecha pasada se suma `stock_movimientos` hasta ese día inclusive. Valorizado (con listados.costos) a costo de hoy
 * o a precio de lista 1 (en pesos).
 */
final class Existencias
{
    public const MOSTRAR = ['' => 'Con movimientos o stock', 'con' => 'Con stock', 'sin' => 'Sin stock', 'negativos' => 'Negativos', 'bajo' => 'Bajo el mínimo', 'todos' => 'Todos los artículos'];

    /**
     * @param array<string,string> $f fecha, rubro, subrubro, proveedor, deposito, mostrar, valorizar (costo|precio), q, orden
     * @param array<int,array<string,mixed>> $depositos los depósitos que van como columnas
     * @return array{filas:array<int,array<string,mixed>>,total:int,totales:array<string,float>,fecha:?string}
     */
    public static function listar(array $f, array $depositos, int $pagina, int $porPagina): array
    {
        $pdo = Db::pdo();
        $fecha = Valida::fecha((string) ($f['fecha'] ?? ''));
        if ($fecha !== null && $fecha >= date('Y-m-d')) {
            $fecha = null; // hoy o después: los saldos de ahora
        }
        $args = [];
        if ($fecha === null) {
            $saldos = 'SELECT articulo, deposito_id, saldo FROM stock_saldos';
        } else {
            $saldos = 'SELECT articulo, deposito_id, SUM(cantidad) AS saldo FROM stock_movimientos WHERE fecha < ? GROUP BY articulo, deposito_id';
            $args[] = date('Y-m-d', strtotime($fecha . ' +1 day')) . ' 00:00:00';
        }
        $ids = array_map(static fn (array $d) => (int) $d['id'], $depositos);
        $soloDep = ctype_digit((string) ($f['deposito'] ?? '')) && in_array((int) $f['deposito'], $ids, true) ? (int) $f['deposito'] : null;
        $cols = [];
        foreach ($ids as $id) { // ids enteros de la tabla depositos: no vienen del usuario
            $cols[] = "COALESCE(SUM(IF(x.deposito_id = {$id}, x.saldo, 0)), 0) AS dep_{$id}";
        }
        $totalExpr = $soloDep === null ? 'COALESCE(SUM(x.saldo), 0)' : "COALESCE(SUM(IF(x.deposito_id = {$soloDep}, x.saldo, 0)), 0)";
        $cot = Costos::cotizacion($pdo, date('Y-m-d'));
        $unit = ($f['valorizar'] ?? '') === 'precio'
            ? "(IF(a.precio_unitario > 0, a.precio_unitario, a.precio_de_venta_1) * IF(a.moneda = 'u\$s', " . number_format($cot, 4, '.', '') . ', 1))'
            : Costos::sqlEnPesos('a', $cot);
        $w = ['a.controla_stock = 1'];
        $p = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $w[] = '(a.codigo LIKE ? OR a.descripcion LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($p, $like, $like);
        }
        foreach (['rubro' => 'a.rubro', 'subrubro' => 'a.subrubro'] as $k => $col) {
            if (trim((string) ($f[$k] ?? '')) !== '') {
                $w[] = "{$col} = ?";
                $p[] = trim((string) $f[$k]);
            }
        }
        if (ctype_digit((string) ($f['proveedor'] ?? ''))) {
            $w[] = 'a.proveedor = ?';
            $p[] = (int) $f['proveedor'];
        }
        $having = match ($f['mostrar'] ?? '') {
            'con' => 'HAVING total > 0.0005',
            'sin' => 'HAVING ABS(total) < 0.0005',
            'negativos' => 'HAVING total < -0.0005',
            'bajo' => 'HAVING minimo > 0 AND total < minimo',
            'todos' => '',
            default => 'HAVING COUNT(x.articulo) > 0',
        };
        if (!in_array($f['mostrar'] ?? '', ['todos', 'sin', 'bajo'], true)) {
            $w[] = "a.estado <> 'BA'";
        }
        $sel = 'SELECT a.codigo, a.descripcion, a.rubro, a.unidades_por_bulto, a.stock_minimo_unidades AS minimo, ' . implode(', ', $cols) . ($cols === [] ? '' : ', ')
            . "{$totalExpr} AS total, {$totalExpr} * MAX({$unit}) AS valorizado
            FROM articulos a LEFT JOIN ({$saldos}) x ON x.articulo = a.codigo WHERE " . implode(' AND ', $w) . " GROUP BY a.codigo {$having}";
        $todos = array_merge($args, $p);
        $st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(total), 0), COALESCE(SUM(valorizado), 0) FROM ({$sel}) t");
        $st->execute($todos);
        $t = $st->fetch(\PDO::FETCH_NUM);
        $orden = ['codigo' => 'a.codigo', 'descripcion' => 'a.descripcion', 'rubro' => 'a.rubro IS NULL, a.rubro', 'total' => 'total', 'minimo' => 'minimo', 'valorizado' => 'valorizado'];
        foreach ($ids as $id) {
            $orden['dep_' . $id] = 'dep_' . $id;
        }
        $desde = max(0, ($pagina - 1) * $porPagina);
        $st = $pdo->prepare($sel . ' ORDER BY ' . Orden::sql($orden, (string) ($f['orden'] ?? ''), 'a.codigo') . " LIMIT {$porPagina} OFFSET {$desde}");
        $st->execute($todos);
        return ['filas' => $st->fetchAll(\PDO::FETCH_ASSOC), 'total' => (int) $t[0], 'totales' => ['unidades' => round((float) $t[1], 3), 'valorizado' => round((float) $t[2], 2)], 'fecha' => $fecha];
    }
}
