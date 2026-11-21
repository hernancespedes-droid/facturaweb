<?php
declare(strict_types=1);

namespace App\Modules\Stock;

use App\Core\Db;
use App\Core\Orden;
use App\Core\Valida;
use App\Modules\Comprobantes\Tipos;
use App\Modules\Listados\Costos;

/**
 * Movimientos de stock (trazabilidad): todos los movimientos de todos los artículos, con el comprobante o el motivo de cada uno, quién lo hizo y,
 * en las ventas sin stock, quién lo autorizó. Con un solo artículo muestra el saldo inicial y el saldo corrido renglón por renglón (D-65: solo
 * cuando no se filtra por origen, usuario ni cliente, porque con esos filtros el saldo corrido no sería el real).
 * Modo resumido: por artículo y depósito, saldo inicial, entradas, salidas y saldo final del período (valorizado a costo con listados.costos).
 */
final class MovimientosStock
{
    private const ORDEN = ['fecha' => 'm.fecha, m.id', 'articulo' => 'm.articulo', 'deposito' => 'd.nombre', 'origen' => 'm.origen', 'cantidad' => 'm.cantidad', 'usuario' => 'u.usuario'];
    private const ORDEN_RESUMEN = ['articulo' => 'm.articulo', 'descripcion' => 'descripcion', 'deposito' => 'deposito', 'inicial' => 'inicial', 'entradas' => 'entradas',
        'salidas' => 'salidas', 'final' => 'final', 'valorizado' => 'valorizado'];

    /** @param array<string,string> $f @return array{desde:string,hasta:string} el período (por omisión, el mes en curso) */
    public static function periodo(array $f): array
    {
        $d = Valida::fecha((string) ($f['desde'] ?? '')) ?? date('Y-m-01');
        $h = Valida::fecha((string) ($f['hasta'] ?? '')) ?? date('Y-m-d');
        return $h < $d ? ['desde' => $h, 'hasta' => $d] : ['desde' => $d, 'hasta' => $h];
    }

    /** Filtro de todo menos las fechas. @param array<string,string> $f @return array{0:string[],1:array<int,mixed>} */
    private static function filtro(array $f, bool $conOrigenYUsuario = true): array
    {
        $w = [];
        $p = [];
        $art = mb_strtoupper(trim((string) ($f['articulo'] ?? '')));
        if ($art !== '') {
            $w[] = 'm.articulo = ?';
            $p[] = $art;
        }
        if (ctype_digit((string) ($f['deposito'] ?? ''))) {
            $w[] = 'm.deposito_id = ?';
            $p[] = (int) $f['deposito'];
        }
        foreach (['rubro' => 'a.rubro', 'subrubro' => 'a.subrubro'] as $k => $col) {
            if (trim((string) ($f[$k] ?? '')) !== '') {
                $w[] = "{$col} = ?";
                $p[] = trim((string) $f[$k]);
            }
        }
        if ($conOrigenYUsuario) {
            if (isset(StockService::ORIGENES[$f['origen'] ?? ''])) {
                $w[] = 'm.origen = ?';
                $p[] = $f['origen'];
            }
            if (ctype_digit((string) ($f['usuario'] ?? ''))) {
                $w[] = 'm.usuario_id = ?';
                $p[] = (int) $f['usuario'];
            }
            if (ctype_digit((string) ($f['cliente'] ?? ''))) {
                $w[] = 's.cliente = ?';
                $p[] = (int) $f['cliente'];
            }
        }
        return [$w, $p];
    }

    /** ¿Se puede mostrar el saldo corrido? Un artículo y sin filtros que salteen movimientos. */
    public static function conSaldo(array $f): bool
    {
        return trim((string) ($f['articulo'] ?? '')) !== '' && ($f['origen'] ?? '') === '' && ($f['usuario'] ?? '') === '' && ($f['cliente'] ?? '') === '';
    }

    private static function from(): string
    {
        return ' FROM stock_movimientos m JOIN articulos a ON a.codigo = m.articulo LEFT JOIN depositos d ON d.id = m.deposito_id
            LEFT JOIN subdiario s ON s.fe_clave = m.subdiario_id LEFT JOIN clientes c ON c.codigo = s.cliente LEFT JOIN usuarios u ON u.id = m.usuario_id';
    }

    /**
     * @param array<string,string> $f desde, hasta, articulo, deposito, origen, usuario, cliente, rubro, subrubro, orden
     * @return array{filas:array<int,array<string,mixed>>,total:int,entradas:float,salidas:float,inicial:?float,final:?float}
     */
    public static function listar(array $f, int $pagina, int $porPagina): array
    {
        $per = self::periodo($f);
        [$w, $p] = self::filtro($f);
        $w[] = 'm.fecha >= ?';
        $w[] = 'm.fecha < ?';
        array_push($p, $per['desde'] . ' 00:00:00', date('Y-m-d', strtotime($per['hasta'] . ' +1 day')) . ' 00:00:00');
        $where = ' WHERE ' . implode(' AND ', $w);
        $pdo = Db::pdo();
        $st = $pdo->prepare('SELECT COUNT(*), COALESCE(SUM(GREATEST(m.cantidad, 0)), 0), COALESCE(SUM(LEAST(m.cantidad, 0)), 0)' . self::from() . $where);
        $st->execute($p);
        [$total, $entradas, $salidas] = $st->fetch(\PDO::FETCH_NUM);
        $inicial = null;
        if (self::conSaldo($f)) {
            [$w0, $p0] = self::filtro($f, false);
            $w0[] = 'm.fecha < ?';
            $p0[] = $per['desde'] . ' 00:00:00';
            $st = $pdo->prepare('SELECT COALESCE(SUM(m.cantidad), 0)' . self::from() . ' WHERE ' . implode(' AND ', $w0));
            $st->execute($p0);
            $inicial = round((float) $st->fetchColumn(), 3);
        }
        $nro = 'IF(s.fe_clave IS NULL, 0, ' . Tipos::sqlNumeroVisible('s') . ')';
        $desdeFila = max(0, ($pagina - 1) * $porPagina);
        $st = $pdo->prepare("SELECT m.id, m.fecha, m.articulo, a.descripcion, m.deposito_id, d.nombre AS deposito, m.origen, m.cantidad, m.motivo, m.subdiario_id, m.grupo,
                s.tipo, s.letra, s.prefijo, {$nro} AS numero, COALESCE(s.cliente_razon_social, c.razon_social) AS cliente, u.usuario,
                SUM(m.cantidad) OVER (ORDER BY m.fecha, m.id) AS acumulado,
                (SELECT d2.nombre FROM stock_movimientos m2 JOIN depositos d2 ON d2.id = m2.deposito_id WHERE m.grupo IS NOT NULL AND m2.grupo = m.grupo AND m2.id <> m.id LIMIT 1) AS otro_deposito,
                (SELECT COALESCE(ua.nombre, IF(sa.autoriza_usuario_id IS NULL, 'sin autorización', '')) FROM stock_autorizaciones sa LEFT JOIN usuarios ua ON ua.id = sa.autoriza_usuario_id
                    WHERE m.subdiario_id IS NOT NULL AND sa.subdiario_id = m.subdiario_id AND sa.articulo = m.articulo ORDER BY sa.id LIMIT 1) AS sin_stock_autorizo"
            . self::from() . $where . ' ORDER BY ' . Orden::sql(self::ORDEN, (string) ($f['orden'] ?? ''), 'm.fecha DESC, m.id DESC') . " LIMIT {$porPagina} OFFSET {$desdeFila}");
        $st->execute($p);
        $filas = $st->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($filas as &$x) {
            $x['saldo'] = $inicial === null ? null : round($inicial + (float) $x['acumulado'], 3);
        }
        unset($x);
        return ['filas' => $filas, 'total' => (int) $total, 'entradas' => round((float) $entradas, 3), 'salidas' => round(-(float) $salidas, 3), 'inicial' => $inicial,
            'final' => $inicial === null ? null : round($inicial + (float) $entradas + (float) $salidas, 3)];
    }

    /**
     * Resumido por artículo y depósito: saldo inicial, entradas, salidas y saldo final del período, y el final valorizado a costo de hoy.
     * @param array<string,string> $f
     * @return array{filas:array<int,array<string,mixed>>,total:int,totales:array<string,float>}
     */
    public static function resumen(array $f, int $pagina, int $porPagina): array
    {
        $per = self::periodo($f);
        [$w, $p] = self::filtro($f, false);
        $ini = $per['desde'] . ' 00:00:00';
        $fin = date('Y-m-d', strtotime($per['hasta'] . ' +1 day')) . ' 00:00:00';
        $w[] = 'm.fecha < ?';
        $where = ' WHERE ' . implode(' AND ', $w);
        $costo = Costos::sqlEnPesos('a', Costos::cotizacion(Db::pdo(), date('Y-m-d')));
        $sel = "SELECT m.articulo, MAX(a.descripcion) AS descripcion, m.deposito_id, MAX(d.nombre) AS deposito,
                SUM(IF(m.fecha < ?, m.cantidad, 0)) AS inicial, SUM(IF(m.fecha >= ? AND m.cantidad > 0, m.cantidad, 0)) AS entradas,
                -SUM(IF(m.fecha >= ? AND m.cantidad < 0, m.cantidad, 0)) AS salidas, SUM(m.cantidad) AS final, SUM(m.cantidad) * MAX({$costo}) AS valorizado,
                SUM(IF(m.fecha >= ?, 1, 0)) AS movimientos"
            . self::from() . $where . ' GROUP BY m.articulo, m.deposito_id HAVING movimientos > 0 OR ABS(inicial) > 0.0005';
        $args = array_merge([$ini, $ini, $ini, $ini], $p, [$fin]);
        $pdo = Db::pdo();
        $st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(inicial), 0), COALESCE(SUM(entradas), 0), COALESCE(SUM(salidas), 0), COALESCE(SUM(final), 0), COALESCE(SUM(valorizado), 0) FROM ({$sel}) x");
        $st->execute($args);
        $t = $st->fetch(\PDO::FETCH_NUM);
        $desdeFila = max(0, ($pagina - 1) * $porPagina);
        $st = $pdo->prepare($sel . ' ORDER BY ' . Orden::sql(self::ORDEN_RESUMEN, (string) ($f['orden'] ?? ''), 'm.articulo, deposito') . " LIMIT {$porPagina} OFFSET {$desdeFila}");
        $st->execute($args);
        return ['filas' => $st->fetchAll(\PDO::FETCH_ASSOC), 'total' => (int) $t[0], 'totales' => ['inicial' => round((float) $t[1], 3), 'entradas' => round((float) $t[2], 3),
            'salidas' => round((float) $t[3], 3), 'final' => round((float) $t[4], 3), 'valorizado' => round((float) $t[5], 2)]];
    }

    /** @return array<int,array{id:int,usuario:string}> usuarios que movieron stock (para el filtro) */
    public static function usuarios(): array
    {
        return Db::pdo()->query('SELECT DISTINCT u.id, u.usuario FROM stock_movimientos m JOIN usuarios u ON u.id = m.usuario_id ORDER BY u.usuario')->fetchAll(\PDO::FETCH_ASSOC);
    }
}
