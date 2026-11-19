<?php
declare(strict_types=1);

namespace App\Modules\Stock;

use App\Core\Db;
use App\Core\Orden;
use App\Core\Valida;
use App\Modules\Comprobantes\Tipos;

/**
 * Informe «Ventas sin stock»: todo lo que se vendió sin que alcanzara el stock disponible, quién lo vendió y cómo se autorizó (un supervisor, el permiso del que
 * vende, o «sin autorización» cuando ARCA ya había aprobado el comprobante). Sale de `stock_autorizaciones` (D-51 y D-55).
 */
final class VentasSinStock
{
    private const ORDEN = ['fecha' => 'a.fecha', 'comprobante' => 'numero', 'articulo' => 'a.articulo', 'faltante' => 'a.cantidad', 'disponible' => 'a.disponible',
        'vendio' => 'up.nombre', 'autorizo' => 'ua.nombre'];

    public const MODOS = ['supervisor' => 'Autorizó un supervisor', 'permiso' => 'Por permiso del que vende', 'arca' => 'Sin autorización (ya estaba en ARCA)'];

    /**
     * @param array<string,string> $f desde, hasta, modo, orden
     * @return array{filas:array<int,array<string,mixed>>,total:int,unidades:float}
     */
    public static function listar(array $f, int $pagina, int $porPagina): array
    {
        $hasta = Valida::fecha((string) ($f['hasta'] ?? '')) ?? date('Y-m-d');
        $desde = Valida::fecha((string) ($f['desde'] ?? '')) ?? date('Y-m-d', strtotime('-30 days'));
        if ($hasta < $desde) {
            [$desde, $hasta] = [$hasta, $desde];
        }
        $where = ['DATE(a.fecha) BETWEEN ? AND ?'];
        $args = [$desde, $hasta];
        $modo = (string) ($f['modo'] ?? '');
        if ($modo === 'permiso') {
            $where[] = 'a.automatica = 1';
        } elseif ($modo === 'supervisor') {
            $where[] = 'a.automatica = 0 AND a.autoriza_usuario_id IS NOT NULL';
        } elseif ($modo === 'arca') {
            $where[] = 'a.autoriza_usuario_id IS NULL';
        }
        $w = implode(' AND ', $where);
        $pdo = Db::pdo();
        $st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(a.cantidad), 0) FROM stock_autorizaciones a WHERE {$w}");
        $st->execute($args);
        [$total, $unidades] = $st->fetch(\PDO::FETCH_NUM);
        $nro = 'IF(s.fe_clave IS NULL, 0, ' . Tipos::sqlNumeroVisible('s') . ')';
        $orden = Orden::sql(self::ORDEN, (string) ($f['orden'] ?? ''), 'a.fecha DESC, a.id DESC');
        $desdeFila = max(0, ($pagina - 1) * $porPagina);
        $st = $pdo->prepare("SELECT a.id, a.fecha, a.articulo, ar.descripcion AS articulo_desc, a.cantidad, a.disponible, a.automatica, a.autoriza_usuario_id, a.subdiario_id,
                s.tipo, s.letra, s.prefijo, {$nro} AS numero, up.nombre AS vendio, ua.nombre AS autorizo
            FROM stock_autorizaciones a LEFT JOIN articulos ar ON ar.codigo = a.articulo LEFT JOIN subdiario s ON s.fe_clave = a.subdiario_id
            LEFT JOIN usuarios up ON up.id = a.pide_usuario_id LEFT JOIN usuarios ua ON ua.id = a.autoriza_usuario_id
            WHERE {$w} ORDER BY {$orden} LIMIT {$porPagina} OFFSET {$desdeFila}");
        $st->execute($args);
        return ['filas' => $st->fetchAll(), 'total' => (int) $total, 'unidades' => (float) $unidades];
    }

    /** @param array<string,mixed> $fila */
    public static function modo(array $fila): string
    {
        return $fila['autoriza_usuario_id'] === null ? 'arca' : ((int) $fila['automatica'] === 1 ? 'permiso' : 'supervisor');
    }
}
