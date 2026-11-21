<?php
declare(strict_types=1);

namespace App\Modules\Compras;

use App\Core\Db;
use App\Core\Valida;
use PDO;

/**
 * Tablero y listados de compras: análisis de gastos (por centro, concepto, proveedor o mes, con columnas por mes), presupuesto contra real,
 * saldos y vencimientos a pagar y subdiario de IVA compras. Solo compras vigentes; la nota de crédito resta.
 * Lo que se suma en los gastos es el costo repartido (compras_reparto.importe): sin IVA computable ni percepciones (D-111).
 */
final class ComprasListados
{
    private const SG = "IF(c.tipo = 'NC', -1, 1)";
    public const AGRUPAR = [
        'centro' => ['Centro de costos', 'COALESCE(pc.id, cc.id)', 'MAX(COALESCE(pc.nombre, cc.nombre))'],
        'subcentro' => ['Centro (con el detalle de segundo nivel)', 'cc.id', "MAX(IF(pc.id IS NULL, cc.nombre, CONCAT(pc.nombre, ' › ', cc.nombre)))"],
        'concepto' => ['Concepto de gasto', 'cg.id', 'MAX(cg.nombre)'],
        'proveedor' => ['Proveedor', 'p.id', 'MAX(p.razon_social)'],
        'mes' => ['Mes', "DATE_FORMAT(c.fecha, '%Y-%m')", "MAX(DATE_FORMAT(c.fecha, '%m/%Y'))"],
    ];
    public const MESES = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    public static function mesCorto(string $ym): string
    {
        return self::MESES[(int) substr($ym, 5, 2) - 1] . ' ' . substr($ym, 2, 2);
    }

    /** @return array{0:string,1:array<int,mixed>} WHERE de los gastos (compras + reparto) */
    private static function filtro(array $f): array
    {
        $w = ["c.estado = 'V'"];
        $p = [];
        if (($d = Valida::fecha((string) ($f['desde'] ?? ''))) !== null) {
            $w[] = 'c.fecha >= ?';
            $p[] = $d;
        }
        if (($h = Valida::fecha((string) ($f['hasta'] ?? ''))) !== null) {
            $w[] = 'c.fecha <= ?';
            $p[] = $h;
        }
        if ((int) ($f['proveedor'] ?? 0) > 0) {
            $w[] = 'c.proveedor_id = ?';
            $p[] = (int) $f['proveedor'];
        }
        if ((int) ($f['centro'] ?? 0) > 0) {
            $w[] = '(cc.id = ? OR cc.padre_id = ?)';
            $p[] = (int) $f['centro'];
            $p[] = (int) $f['centro'];
        }
        if ((int) ($f['concepto'] ?? 0) > 0) {
            $w[] = 'r.concepto_id = ?';
            $p[] = (int) $f['concepto'];
        }
        return [implode(' AND ', $w), $p];
    }

    private const FROM = 'FROM compras c JOIN compras_reparto r ON r.compra_id = c.id JOIN centros_costo cc ON cc.id = r.centro_id LEFT JOIN centros_costo pc ON pc.id = cc.padre_id
        JOIN conceptos_gasto cg ON cg.id = r.concepto_id JOIN proveedores p ON p.id = c.proveedor_id';

    /**
     * Análisis de gastos: agrupado y, si se pide, con una columna por mes (hasta 24).
     *
     * @return array{agrupar:string,filas:array<int,array<string,mixed>>,meses:string[],total:float,totales_mes:array<string,float>}
     */
    public static function analisis(array $f): array
    {
        $ag = isset(self::AGRUPAR[$f['agrupar'] ?? '']) ? (string) $f['agrupar'] : 'centro';
        [, $grupo, $nombre] = self::AGRUPAR[$ag];
        [$where, $params] = self::filtro($f);
        $st = Db::pdo()->prepare("SELECT {$grupo} AS grupo, {$nombre} AS nombre, DATE_FORMAT(c.fecha, '%Y-%m') AS ym, SUM(" . self::SG . " * r.importe) AS importe,
                COUNT(DISTINCT c.id) AS comprobantes " . self::FROM . " WHERE {$where} GROUP BY grupo, ym");
        $st->execute($params);
        $filas = [];
        $meses = [];
        $total = 0.0;
        $porMes = [];
        foreach ($st->fetchAll() as $x) {
            $g = (string) $x['grupo'];
            $filas[$g] ??= ['grupo' => $g, 'nombre' => (string) $x['nombre'], 'total' => 0.0, 'comprobantes' => 0, 'meses' => []];
            $filas[$g]['total'] += (float) $x['importe'];
            $filas[$g]['comprobantes'] += (int) $x['comprobantes'];
            $filas[$g]['meses'][$x['ym']] = (float) $x['importe'];
            $meses[$x['ym']] = true;
            $porMes[$x['ym']] = ($porMes[$x['ym']] ?? 0.0) + (float) $x['importe'];
            $total += (float) $x['importe'];
        }
        ksort($meses);
        ksort($porMes);
        $filas = array_values($filas);
        if ($ag === 'mes') {
            usort($filas, static fn ($a, $b) => strcmp($a['grupo'], $b['grupo']));
        } else {
            usort($filas, static fn ($a, $b) => $b['total'] <=> $a['total'] ?: strcmp($a['nombre'], $b['nombre']));
        }
        foreach ($filas as &$x) {
            $x['total'] = round($x['total'], 2);
            $x['pct'] = abs($total) > 0.005 ? $x['total'] / $total * 100 : null;
        }
        unset($x);
        $m = array_keys($meses);
        return ['agrupar' => $ag, 'filas' => $filas, 'meses' => count($m) > 24 ? array_slice($m, -24) : $m, 'total' => round($total, 2), 'totales_mes' => $porMes];
    }

    /**
     * Presupuesto contra real de un mes, con el árbol de centros (los principales suman a sus centros de segundo nivel).
     *
     * @return array{filas:array<int,array<string,mixed>>,presupuesto:float,gastado:float,sobre:int,con_presupuesto:int}
     */
    public static function presupuestoReal(string $periodo): array
    {
        $cs = new CentrosService();
        $centros = $cs->centros();
        $pres = $cs->presupuesto($periodo);
        $st = Db::pdo()->prepare("SELECT r.centro_id, SUM(" . self::SG . " * r.importe) FROM compras c JOIN compras_reparto r ON r.compra_id = c.id
            WHERE c.estado = 'V' AND DATE_FORMAT(c.fecha, '%Y-%m') = ? GROUP BY r.centro_id");
        $st->execute([$periodo]);
        $gasto = array_map('floatval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
        $filas = [];
        foreach ($centros as $id => $c) {
            $filas[$id] = ['id' => $id, 'nombre' => $c['nombre'], 'completo' => $c['completo'], 'nivel' => $c['nivel'], 'activo' => (int) $c['activo'],
                'general' => (int) $c['general'], 'padre_id' => $c['padre_id'] === null ? null : (int) $c['padre_id'],
                'presupuesto_propio' => $pres[$id] ?? 0.0, 'presupuesto' => $pres[$id] ?? 0.0, 'gastado' => $gasto[$id] ?? 0.0];
        }
        foreach ($filas as $id => $x) { // los principales suman lo de sus centros de segundo nivel
            if ($x['padre_id'] !== null && isset($filas[$x['padre_id']])) {
                $filas[$x['padre_id']]['presupuesto'] += $x['presupuesto'];
                $filas[$x['padre_id']]['gastado'] += $x['gastado'];
            }
        }
        $tp = $tg = 0.0;
        $sobre = $con = 0;
        foreach ($filas as &$x) {
            $x['gastado'] = round($x['gastado'], 2);
            $x['diferencia'] = round($x['presupuesto'] - $x['gastado'], 2);
            $x['uso'] = $x['presupuesto'] > 0.005 ? $x['gastado'] / $x['presupuesto'] * 100 : null;
            if ($x['nivel'] === 0) {
                $tp += $x['presupuesto'];
                $tg += $x['gastado'];
                if ($x['presupuesto'] > 0.005) {
                    $con++;
                    if ($x['gastado'] > $x['presupuesto'] + 0.005) {
                        $sobre++;
                    }
                }
            }
        }
        unset($x);
        return ['filas' => array_values($filas), 'presupuesto' => round($tp, 2), 'gastado' => round($tg, 2), 'sobre' => $sobre, 'con_presupuesto' => $con];
    }

    /**
     * Lo que hay que pagar: compras a cuenta corriente con saldo, por proveedor con la antigüedad, y el detalle por vencimiento.
     *
     * @return array{proveedores:array<int,array<string,mixed>>,detalle:array<int,array<string,mixed>>,totales:array<string,float>}
     */
    public static function aPagar(array $f, ?string $hoy = null): array
    {
        $hoy ??= date('Y-m-d');
        $w = ["c.estado = 'V'", "c.forma = 'C'", 'c.saldo > 0.005'];
        $p = [];
        if ((int) ($f['proveedor'] ?? 0) > 0) {
            $w[] = 'c.proveedor_id = ?';
            $p[] = (int) $f['proveedor'];
        }
        if (($h = Valida::fecha((string) ($f['vence_hasta'] ?? ''))) !== null) {
            $w[] = 'COALESCE(c.vence, c.fecha) <= ?';
            $p[] = $h;
        }
        $st = Db::pdo()->prepare('SELECT c.*, p.razon_social, p.codigo AS proveedor_codigo, p.cbu_alias, DATEDIFF(?, COALESCE(c.vence, c.fecha)) AS dias
            FROM compras c JOIN proveedores p ON p.id = c.proveedor_id WHERE ' . implode(' AND ', $w) . ' ORDER BY COALESCE(c.vence, c.fecha), c.id');
        $st->execute([$hoy, ...$p]);
        $det = $st->fetchAll();
        $prov = [];
        $t = ['saldo' => 0.0, 'por_vencer' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'mas60' => 0.0, 'semana' => 0.0];
        $enSemana = 0;
        foreach ($det as &$x) {
            $s = (float) $x['saldo'] * ($x['tipo'] === 'NC' ? -1 : 1);
            $x['saldo_signo'] = $s;
            $dias = (int) $x['dias'];
            $tramo = $dias <= 0 ? 'por_vencer' : ($dias <= 30 ? 'd30' : ($dias <= 60 ? 'd60' : 'mas60'));
            $x['tramo'] = $tramo;
            $id = (int) $x['proveedor_id'];
            $prov[$id] ??= ['id' => $id, 'codigo' => (int) $x['proveedor_codigo'], 'razon_social' => $x['razon_social'], 'cbu_alias' => $x['cbu_alias'], 'saldo' => 0.0,
                'por_vencer' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'mas60' => 0.0, 'comprobantes' => 0, 'proximo' => null];
            $prov[$id]['saldo'] += $s;
            $prov[$id][$tramo] += $s;
            $prov[$id]['comprobantes']++;
            if ($prov[$id]['proximo'] === null && $x['vence'] !== null) {
                $prov[$id]['proximo'] = $x['vence'];
            }
            $t['saldo'] += $s;
            $t[$tramo] += $s;
            if ($dias <= 0 && $dias >= -7 && $x['tipo'] !== 'NC') {
                $t['semana'] += $s;
                $enSemana++;
            }
        }
        unset($x);
        $prov = array_values($prov);
        usort($prov, static fn ($a, $b) => $b['saldo'] <=> $a['saldo']);
        $t = array_map(static fn ($v) => round($v, 2), $t);
        $t['vence_semana_cant'] = $enSemana;
        return ['proveedores' => $prov, 'detalle' => $det, 'totales' => $t];
    }

    /**
     * Subdiario de IVA compras de un período (el período de IVA de cada compra, no su fecha). Sin los gastos sin comprobante.
     *
     * @return array{filas:array<int,array<string,mixed>>,totales:array<string,float>}
     */
    public static function ivaCompras(string $periodo): array
    {
        $al = [];
        foreach (ComprasService::ALICUOTAS as $k => $a) {
            $al[] = "(SELECT COALESCE(SUM(i.neto), 0) FROM compras_iva i WHERE i.compra_id = c.id AND i.alicuota = {$a}) AS neto_" . str_replace('.', '_', (string) $k);
            $al[] = "(SELECT COALESCE(SUM(i.iva), 0) FROM compras_iva i WHERE i.compra_id = c.id AND i.alicuota = {$a}) AS iva_" . str_replace('.', '_', (string) $k);
        }
        $tr = [];
        foreach (['IVA' => 'perc_iva', 'IIBB' => 'perc_iibb'] as $tipo => $col) {
            $tr[] = "(SELECT COALESCE(SUM(t.importe), 0) FROM compras_tributos t WHERE t.compra_id = c.id AND t.tipo = '{$tipo}') AS {$col}";
        }
        $tr[] = "(SELECT COALESCE(SUM(t.importe), 0) FROM compras_tributos t WHERE t.compra_id = c.id AND t.tipo NOT IN ('IVA', 'IIBB')) AS perc_otras";
        $st = Db::pdo()->prepare('SELECT c.id, c.fecha, c.tipo, c.letra, c.punto_venta, c.numero, c.gravado, c.no_gravado, c.exento, c.iva, c.total, c.computa_iva,
                p.razon_social, p.cuit, ' . implode(', ', array_merge($al, $tr)) . "
            FROM compras c JOIN proveedores p ON p.id = c.proveedor_id WHERE c.estado = 'V' AND c.tipo <> 'GS' AND c.periodo_iva = ? ORDER BY c.fecha, c.id");
        $st->execute([$periodo]);
        $filas = $st->fetchAll();
        $cols = ['gravado', 'no_gravado', 'exento', 'iva', 'total', 'perc_iva', 'perc_iibb', 'perc_otras', 'credito'];
        foreach (array_keys(ComprasService::ALICUOTAS) as $k) {
            $cols[] = 'neto_' . str_replace('.', '_', (string) $k);
            $cols[] = 'iva_' . str_replace('.', '_', (string) $k);
        }
        $t = array_fill_keys($cols, 0.0);
        foreach ($filas as &$x) {
            $sg = $x['tipo'] === 'NC' ? -1 : 1;
            $x['credito'] = (int) $x['computa_iva'] === 1 ? (float) $x['iva'] : 0.0;
            foreach ($cols as $k) {
                $x[$k] = (float) $x[$k] * $sg;
                $t[$k] += $x[$k];
            }
        }
        unset($x);
        return ['filas' => $filas, 'totales' => array_map(static fn ($v) => round($v, 2), $t)];
    }

    // ---------------------------------------------------------------- tablero

    /**
     * Todo lo del tablero de un mes: indicadores, presupuesto contra gastado, gasto por centro de los últimos meses, ventas contra compras y conceptos.
     *
     * @return array<string,mixed>
     */
    public static function tablero(string $periodo, int $meses = 6): array
    {
        $pdo = Db::pdo();
        $ini = $periodo . '-01';
        $fin = date('Y-m-t', strtotime($ini));
        $desde = date('Y-m-01', strtotime($ini . ' -' . ($meses - 1) . ' months'));
        $lista = [];
        for ($i = 0; $i < $meses; $i++) {
            $lista[] = date('Y-m', strtotime($desde . ' +' . $i . ' months'));
        }
        $costoMes = static function (string $ym) use ($pdo): float {
            $st = $pdo->prepare("SELECT COALESCE(SUM(IF(tipo = 'NC', -1, 1) * costo), 0) FROM compras WHERE estado = 'V' AND DATE_FORMAT(fecha, '%Y-%m') = ?");
            $st->execute([$ym]);
            return round((float) $st->fetchColumn(), 2);
        };
        $gastado = $costoMes($periodo);
        $anterior = $costoMes(date('Y-m', strtotime($ini . ' -1 month')));
        $pr = self::presupuestoReal($periodo);
        $ap = self::aPagar([]);
        $revisar = (int) $pdo->query("SELECT COUNT(*) FROM compras WHERE estado = 'V' AND a_revisar = 1")->fetchColumn();

        // gasto por centro principal, mes a mes (los 5 de más gasto y «Otros»)
        $st = $pdo->prepare("SELECT COALESCE(pc.id, cc.id) AS centro, MAX(COALESCE(pc.nombre, cc.nombre)) AS nombre, DATE_FORMAT(c.fecha, '%Y-%m') AS ym,
                SUM(" . self::SG . " * r.importe) AS importe FROM compras c JOIN compras_reparto r ON r.compra_id = c.id JOIN centros_costo cc ON cc.id = r.centro_id
                LEFT JOIN centros_costo pc ON pc.id = cc.padre_id WHERE c.estado = 'V' AND c.fecha BETWEEN ? AND ? GROUP BY centro, ym");
        $st->execute([$desde, $fin]);
        $porCentro = [];
        foreach ($st->fetchAll() as $x) {
            $id = (int) $x['centro'];
            $porCentro[$id] ??= ['nombre' => (string) $x['nombre'], 'total' => 0.0, 'valores' => array_fill_keys($lista, 0.0)];
            $porCentro[$id]['valores'][$x['ym']] = round((float) $x['importe'], 2);
            $porCentro[$id]['total'] += (float) $x['importe'];
        }
        uasort($porCentro, static fn ($a, $b) => $b['total'] <=> $a['total']);
        $series = [];
        $otros = array_fill_keys($lista, 0.0);
        $hayOtros = false;
        foreach (array_values($porCentro) as $i => $c) {
            if ($i < 5) {
                $series[] = ['nombre' => $c['nombre'], 'valores' => array_values($c['valores'])];
            } else {
                $hayOtros = true;
                foreach ($c['valores'] as $k => $v) {
                    $otros[$k] += $v;
                }
            }
        }
        if ($hayOtros) {
            $series[] = ['nombre' => 'Otros centros', 'valores' => array_values(array_map(static fn ($v) => round($v, 2), $otros))];
        }

        // ventas (venta neta de la estadística) contra compras, mes a mes
        $ventas = array_fill_keys($lista, 0.0);
        try {
            $r = \App\Modules\Listados\Estadistica::resumen(['desde' => $desde, 'hasta' => $fin, 'agrupar' => 'mes']);
            foreach ($r['filas'] as $x) {
                if (isset($ventas[$x['grupo']])) {
                    $ventas[$x['grupo']] = round((float) $x['neto'], 2);
                }
            }
        } catch (\Throwable) {
            // sin estadística (tablas viejas): el gráfico muestra solo las compras
        }
        $compras = array_map($costoMes, $lista);

        // conceptos del mes (los 7 primeros y «Otros»)
        $st = $pdo->prepare('SELECT cg.nombre, SUM(' . self::SG . " * r.importe) AS importe FROM compras c JOIN compras_reparto r ON r.compra_id = c.id
            JOIN conceptos_gasto cg ON cg.id = r.concepto_id WHERE c.estado = 'V' AND c.fecha BETWEEN ? AND ? GROUP BY cg.id, cg.nombre ORDER BY importe DESC");
        $st->execute([$ini, $fin]);
        $conceptos = [];
        $resto = 0.0;
        foreach ($st->fetchAll() as $i => $x) {
            if ($i < 7) {
                $conceptos[] = ['nombre' => $x['nombre'], 'importe' => round((float) $x['importe'], 2)];
            } else {
                $resto += (float) $x['importe'];
            }
        }
        if (abs($resto) > 0.005) {
            $conceptos[] = ['nombre' => 'Otros conceptos', 'importe' => round($resto, 2)];
        }

        $presCentros = array_values(array_filter($pr['filas'], static fn ($x) => $x['nivel'] === 0 && ($x['presupuesto'] > 0.005 || abs($x['gastado']) > 0.005)));
        $sobreNombres = array_values(array_map(static fn ($x) => $x['nombre'], array_filter($presCentros, static fn ($x) => $x['presupuesto'] > 0.005 && $x['gastado'] > $x['presupuesto'] + 0.005)));
        return [
            'periodo' => $periodo, 'meses' => $lista, 'etiquetas' => array_map([self::class, 'mesCorto'], $lista),
            'kpi' => ['gastado' => $gastado, 'anterior' => $anterior, 'variacion' => abs($anterior) > 0.005 ? ($gastado - $anterior) / abs($anterior) * 100 : null,
                'deuda' => $ap['totales']['saldo'], 'deuda_comprobantes' => count($ap['detalle']), 'vencido' => round($ap['totales']['d30'] + $ap['totales']['d60'] + $ap['totales']['mas60'], 2),
                'semana' => $ap['totales']['semana'], 'semana_cant' => $ap['totales']['vence_semana_cant'], 'sobre' => $pr['sobre'], 'con_presupuesto' => $pr['con_presupuesto'],
                'sobre_nombres' => $sobreNombres, 'revisar' => $revisar],
            'presupuesto' => array_map(static fn ($x) => ['nombre' => $x['nombre'], 'gastado' => $x['gastado'], 'presupuesto' => $x['presupuesto']], $presCentros),
            'centros' => $series, 'ventas' => array_values($ventas), 'compras' => $compras, 'conceptos' => $conceptos,
        ];
    }
}
