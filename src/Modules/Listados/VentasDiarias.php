<?php
declare(strict_types=1);

namespace App\Modules\Listados;

use App\Core\Db;
use App\Core\Orden;
use App\Core\Valida;
use App\Modules\Comprobantes\Calculadora;
use App\Modules\Comprobantes\Tipos;

/**
 * Ventas diarias: los presupuestos y las facturas mezclados, para ver lo que se vendió. Solo entran los que se cobraron o fueron a cuenta
 * corriente (solo_subdiario = 0): la factura que sale de un presupuesto va únicamente al subdiario y no se lista (ya está el presupuesto).
 * Las contrapartidas (nota de crédito y nota de crédito X) restan.
 *
 * DECISIÓN D-37 (Claude, a confirmar por Hernán): además de presupuestos (PR) y facturas (FC) se listan sus contrapartidas (NX y NC), restando,
 * para que el total del día sea el real; las notas de débito no entran. Para dejar solo PR y FC, sacar 'NC' y 'NX' de TIPOS.
 */
final class VentasDiarias
{
    public const TIPOS = ['FC', 'PR', 'CM', 'NC', 'NX'];
    private const ORDEN = ['fecha' => 's.fecha', 'numero' => 's.tipo, s.letra, s.prefijo, numero', 'cliente' => 'razon_social', 'pago' => 's.forma_pago', 'total' => 'total'];
    private const SIGNO = "IF(s.tipo IN ('NC', 'NX'), -1, 1)";

    /** @param array<string,string> $f desde, hasta, tipo, forma (contado|cuenta), cliente @return array{0:string,1:array<int,mixed>} */
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
        if (($f['forma'] ?? '') === 'contado') {
            $w[] = 's.forma_pago = 1';
        } elseif (($f['forma'] ?? '') === 'cuenta') {
            $w[] = 's.forma_pago = 2';
        }
        $cli = trim((string) ($f['cliente'] ?? ''));
        if ($cli !== '') {
            $dig = preg_replace('/\D/', '', $cli) ?? '';
            $conds = ['COALESCE(s.cliente_razon_social, c.razon_social) LIKE ?'];
            $p[] = '%' . addcslashes($cli, '%_\\') . '%';
            if ($dig !== '') {
                $conds[] = 's.cliente = ?';
                $conds[] = 'CAST(s.cuit_cliente AS CHAR) LIKE ?';
                array_push($p, (int) $dig, '%' . $dig . '%');
            }
            $w[] = '(' . implode(' OR ', $conds) . ')';
        }
        return [' WHERE ' . implode(' AND ', $w), $p];
    }

    /**
     * @param array<string,string> $f
     * @return array{filas:array<int,array<string,mixed>>,total:int,totales:array<string,float>}
     */
    public static function listar(array $f, int $pagina, int $porPagina): array
    {
        [$where, $p] = self::filtro($f);
        $pdo = Db::pdo();
        $sg = self::SIGNO;
        $base = 'FROM subdiario s LEFT JOIN clientes c ON c.codigo = s.cliente' . $where;
        $st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(s.neto * {$sg}), 0), COALESCE(SUM(IF(s.forma_pago = 1, s.neto * {$sg}, 0)), 0),
                COALESCE(SUM(IF(s.forma_pago = 2, s.neto * {$sg}, 0)), 0), COALESCE(SUM(IF(s.tipo IN ('PR', 'NX'), s.neto * {$sg}, 0)), 0),
                COALESCE(SUM(IF(s.tipo IN ('FC', 'NC'), s.neto * {$sg}, 0)), 0) {$base}");
        $st->execute($p);
        $t = $st->fetch(\PDO::FETCH_NUM);
        $nro = Tipos::sqlNumeroVisible('s');
        $desde = max(0, ($pagina - 1) * $porPagina);
        $st = $pdo->prepare("SELECT s.fe_clave, s.fecha, s.tipo, s.letra, s.prefijo, {$nro} AS numero, s.fe_ccae, s.es_prueba, s.forma_pago, s.neto * {$sg} AS total,
                COALESCE(s.cliente_razon_social, c.razon_social) AS razon_social,
                (SELECT f.descripcion FROM caja k LEFT JOIN forma_de_pagos f ON f.codigo = k.p_fpago WHERE k.subdiario_id = s.fe_clave ORDER BY k.fe_clave LIMIT 1) AS medio
            {$base} ORDER BY " . Orden::sql(self::ORDEN, (string) ($f['orden'] ?? ''), 's.fecha DESC, s.fe_clave DESC') . " LIMIT {$porPagina} OFFSET {$desde}");
        $st->execute($p);
        return ['filas' => $st->fetchAll(), 'total' => (int) $t[0], 'totales' => [
            'total' => Calculadora::r2((float) $t[1]), 'contado' => Calculadora::r2((float) $t[2]), 'cuenta' => Calculadora::r2((float) $t[3]),
            'presupuestos' => Calculadora::r2((float) $t[4]), 'facturas' => Calculadora::r2((float) $t[5]),
        ]];
    }
}
