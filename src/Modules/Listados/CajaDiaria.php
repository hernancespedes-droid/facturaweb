<?php
declare(strict_types=1);

namespace App\Modules\Listados;

use App\Core\Db;
use App\Core\Orden;
use App\Core\Valida;
use App\Modules\Comprobantes\Calculadora;
use App\Modules\Comprobantes\Tipos;
use App\Modules\Tablas\Definiciones;

/**
 * Caja diaria para el dueño: lo vendido (facturas, presupuestos y notas de débito menos las notas de crédito), cuánto de eso se facturó a cuenta
 * corriente, y lo que entró de verdad a la caja por forma de pago (efectivo, tarjeta, billeteras virtuales, cheques…), de ventas de contado y de
 * recibos de cobranza. En efectivo se muestra lo recibido, el vuelto dado y el neto que queda en caja.
 *
 * DECISIÓN D-45 (Claude, a confirmar por Hernán): la caja suma lo que quedó anotado en `caja` (ventas de contado y recibos); la cuenta corriente
 * no es dinero (va aparte); las notas de crédito no devuelven dinero en caja (como el VFP); las facturas que salen de un presupuesto no suman
 * (no tienen caja: ya la sumó el presupuesto); no hay apertura/cierre de turnos: es el total por fecha (desde/hasta).
 */
final class CajaDiaria
{
    private const ORDEN = ['fecha' => 'c.fecha, c.fe_clave', 'comprobante' => 'c.tipo, c.letra, c.prefijo, numero', 'cliente' => 'razon_social', 'forma' => 't.descripcion', 'importe' => 'c.p_importe', 'vuelto' => 'c.p_vuelto'];

    /** @param array<string,string> $f desde, hasta @return array{0:string,1:string} */
    public static function fechas(array $f): array
    {
        $d = Valida::fecha((string) ($f['desde'] ?? '')) ?? date('Y-m-d');
        $h = Valida::fecha((string) ($f['hasta'] ?? '')) ?? $d;
        return $h < $d ? [$h, $d] : [$d, $h];
    }

    /**
     * @param array<string,string> $f
     * @return array{ventas:array<string,float>,cobrado:array<int,array<string,mixed>>,por_tipo:array<int,float>,efectivo:array<string,float>,total_cobrado:float}
     */
    public static function resumen(array $f): array
    {
        [$d, $h] = self::fechas($f);
        $pdo = Db::pdo();
        // Lo vendido: por tipo, y cuánto fue a cuenta corriente
        $st = $pdo->prepare("SELECT tipo, forma_pago, COUNT(*), COALESCE(SUM(neto), 0) FROM subdiario WHERE estado = '' AND solo_subdiario = 0 AND fecha BETWEEN ? AND ?
            AND tipo IN ('FC', 'ND', 'PR', 'CM', 'NC', 'NX') GROUP BY tipo, forma_pago");
        $st->execute([$d, $h]);
        $v = ['facturas' => 0.0, 'presupuestos' => 0.0, 'cambios' => 0.0, 'notas_debito' => 0.0, 'notas_credito' => 0.0, 'cuenta_corriente' => 0.0, 'contado' => 0.0, 'comprobantes' => 0.0];
        foreach ($st->fetchAll(\PDO::FETCH_NUM) as [$tipo, $fp, $n, $suma]) {
            $suma = (float) $suma;
            $clave = ['FC' => 'facturas', 'PR' => 'presupuestos', 'CM' => 'cambios', 'ND' => 'notas_debito'][$tipo] ?? 'notas_credito';
            $v[$clave] += $suma;
            $v['comprobantes'] += (int) $n;
        }
        // Lo facturado a cuenta corriente es la parte de cada venta que quedó como deuda (una venta puede ser mixta: parte cobrada y parte a cuenta, D-49)
        $st = $pdo->prepare("SELECT COALESCE(SUM(c.p_importe), 0) FROM caja c JOIN forma_de_pagos t ON t.codigo = c.p_fpago JOIN subdiario s ON s.fe_clave = c.subdiario_id
            WHERE c.fecha BETWEEN ? AND ? AND t.tipo = 6 AND s.estado = '' AND s.solo_subdiario = 0 AND s.tipo IN ('FC', 'ND', 'PR', 'CM')");
        $st->execute([$d, $h]);
        $v['cuenta_corriente'] = (float) $st->fetchColumn();
        $v['contado'] = $v['facturas'] + $v['presupuestos'] + $v['cambios'] + $v['notas_debito'] - $v['cuenta_corriente'];
        $v['ventas_netas'] = Calculadora::r2($v['facturas'] + $v['presupuestos'] + $v['cambios'] + $v['notas_debito'] - $v['notas_credito']);
        foreach ($v as $k => $x) {
            $v[$k] = Calculadora::r2($x);
        }

        // Lo cobrado: por forma de pago, separando ventas de contado y recibos
        $st = $pdo->prepare('SELECT t.codigo, t.descripcion, t.tipo, COALESCE(SUM(IF(c.cobra_id IS NULL, c.p_importe, 0)), 0) AS ventas,
                COALESCE(SUM(IF(c.cobra_id IS NULL, 0, c.p_importe)), 0) AS recibos, COALESCE(SUM(c.p_importe), 0) AS total,
                COALESCE(SUM(COALESCE(c.p_recibido, c.p_importe)), 0) AS recibido, COALESCE(SUM(c.p_vuelto), 0) AS vuelto, COUNT(*) AS cantidad
            FROM caja c JOIN forma_de_pagos t ON t.codigo = c.p_fpago WHERE c.fecha BETWEEN ? AND ? AND t.tipo <> 6
            GROUP BY t.codigo, t.descripcion, t.tipo ORDER BY t.tipo, t.descripcion');
        $st->execute([$d, $h]);
        $cobrado = $st->fetchAll();
        $porTipo = [];
        $ef = ['recibido' => 0.0, 'vuelto' => 0.0, 'neto' => 0.0];
        $total = 0.0;
        foreach ($cobrado as $c) {
            $porTipo[(int) $c['tipo']] = Calculadora::r2(($porTipo[(int) $c['tipo']] ?? 0.0) + (float) $c['total']);
            $total += (float) $c['total'];
            if ((int) $c['tipo'] === 1) {
                $ef['recibido'] += (float) $c['recibido'];
                $ef['vuelto'] += (float) $c['vuelto'];
                $ef['neto'] += (float) $c['total'];
            }
        }
        return ['ventas' => $v, 'cobrado' => $cobrado, 'por_tipo' => $porTipo, 'total_cobrado' => Calculadora::r2($total),
            'efectivo' => array_map([Calculadora::class, 'r2'], $ef)];
    }

    /** Movimientos de caja del período. @param array<string,string> $f @return array{filas:array<int,array<string,mixed>>,total:int} */
    public static function movimientos(array $f, int $pagina, int $porPagina): array
    {
        [$d, $h] = self::fechas($f);
        $pdo = Db::pdo();
        $base = 'FROM caja c JOIN forma_de_pagos t ON t.codigo = c.p_fpago LEFT JOIN subdiario s ON s.fe_clave = c.subdiario_id LEFT JOIN clientes k ON k.codigo = c.cliente
            WHERE c.fecha BETWEEN ? AND ? AND t.tipo <> 6';
        $st = $pdo->prepare("SELECT COUNT(*) {$base}");
        $st->execute([$d, $h]);
        $total = (int) $st->fetchColumn();
        $desde = max(0, ($pagina - 1) * $porPagina);
        $nro = 'IF(s.fe_clave IS NULL, c.comprobante, ' . Tipos::sqlNumeroVisible('s') . ')';
        $st = $pdo->prepare("SELECT c.fe_clave, c.subdiario_id, c.cobra_id, c.fecha, c.tipo, c.letra, c.prefijo, {$nro} AS numero, c.p_importe, c.p_vuelto,
                COALESCE(c.p_recibido, c.p_importe) AS recibido, t.descripcion AS forma, t.tipo AS tipo_forma, COALESCE(s.cliente_razon_social, k.razon_social) AS razon_social
            {$base} ORDER BY " . Orden::sql(self::ORDEN, (string) ($f['orden'] ?? ''), 'c.fecha DESC, c.fe_clave DESC') . " LIMIT {$porPagina} OFFSET {$desde}");
        $st->execute([$d, $h]);
        return ['filas' => $st->fetchAll(), 'total' => $total];
    }

    /** Etiqueta de un tipo de forma de pago. */
    public static function etiquetaTipo(int $tipo): string
    {
        return Definiciones::TIPOS_PAGO[$tipo] ?? 'Otros';
    }
}
