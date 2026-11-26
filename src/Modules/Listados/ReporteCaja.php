<?php
declare(strict_types=1);

namespace App\Modules\Listados;

use App\Core\Db;
use App\Modules\Comprobantes\Calculadora;
use App\Modules\Comprobantes\Tipos;
use App\Modules\CuentaCorriente\CuentaCorrienteService;

/**
 * Reporte de caja para imprimir, pensado para alguien sin conocimientos contables: qué se vendió, cómo se cobró, a dónde fue cada peso
 * (cajón, banco por la tarjeta, Mercado Pago, cartera de cheques, cuenta corriente), lo que salió de la caja y el detalle de cada cobro.
 * Usa los mismos números que la caja diaria (`CajaDiaria::resumen`, D-45, D-148): el reporte no calcula distinto, solo explica y detalla.
 *
 * DECISIÓN D-152 (Claude, a confirmar por Hernán): el reporte es una hoja A4 aparte (`/listados/caja-diaria/imprimir`) con frases simples y
 * «a dónde fue» por tipo de forma de pago; los cheques se muestran con su estado de hoy (en cartera, depositado, entregado, rechazado); los pagos
 * a proveedores y lo que entró a Mercado Pago aparecen solo si hubo en el período. La cuenta corriente se muestra como «lo que quedó debiendo»,
 * nunca como dinero cobrado.
 */
final class ReporteCaja
{
    /** A dónde va el dinero de cada tipo de forma de pago (forma_de_pagos.tipo), dicho en simple. */
    public const DESTINO = [
        1 => 'Queda en el cajón (ver «El efectivo, paso a paso»)',
        2 => 'Vales o tickets guardados en el cajón; se cobran después a quien los emite',
        3 => 'Lo deposita la tarjeta en el banco en unos días (menos su comisión)',
        4 => 'Dólares guardados en el cajón, aparte de los pesos',
        5 => 'Cheques en la cartera (ver «Cheques recibidos»)',
        7 => 'No es dinero: es un ajuste de cuenta',
        8 => 'No es dinero: se usó un saldo a favor del cliente',
        9 => 'No es dinero: gastos descontados',
        10 => 'Entró a la cuenta de la billetera virtual (Mercado Pago u otra)',
    ];

    /** Cómo se pagó a un proveedor (ordenes_pago_medios.medio). */
    public const MEDIOS_PAGO = ['E' => 'Efectivo de la caja', 'T' => 'Transferencia bancaria', 'Q' => 'Cheque recibido de un cliente', 'P' => 'Cheque propio', 'R' => 'Retención', 'O' => 'Otro medio'];

    /** @param array<string,string> $f desde, hasta @return array<string,mixed> */
    public static function datos(array $f): array
    {
        [$d, $h] = CajaDiaria::fechas($f);
        $r = CajaDiaria::resumen($f);
        $pdo = Db::pdo();

        // cada forma de pago con a dónde fue
        $medios = [];
        foreach ($r['cobrado'] as $c) {
            $medios[] = ['forma' => (string) $c['descripcion'], 'tipo' => (int) $c['tipo'], 'cantidad' => (int) $c['cantidad'], 'ventas' => (float) $c['ventas'],
                'recibos' => (float) $c['recibos'], 'total' => (float) $c['total'], 'destino' => self::DESTINO[(int) $c['tipo']] ?? 'Otros'];
        }

        // detalle de cada cobro (sin cuenta corriente), agrupado por forma de pago
        $nro = 'IF(s.fe_clave IS NULL, c.comprobante, ' . Tipos::sqlNumeroVisible('s') . ')';
        $st = $pdo->prepare("SELECT c.fecha, c.creado_en, c.tipo, c.letra, c.prefijo, {$nro} AS numero, c.cobra_id, c.p_importe, c.p_vuelto, COALESCE(c.p_recibido, c.p_importe) AS recibido,
                t.descripcion AS forma, t.tipo AS tipo_forma, COALESCE(s.cliente_razon_social, k.razon_social, '') AS cliente, COALESCE(u.nombre, '') AS usuario
            FROM caja c JOIN forma_de_pagos t ON t.codigo = c.p_fpago LEFT JOIN subdiario s ON s.fe_clave = c.subdiario_id LEFT JOIN clientes k ON k.codigo = c.cliente
            LEFT JOIN usuarios u ON u.id = c.usuario_id WHERE c.fecha BETWEEN ? AND ? AND t.tipo <> 6 ORDER BY t.tipo, t.descripcion, c.fecha, c.creado_en, c.fe_clave");
        $st->execute([$d, $h]);
        $cobros = [];
        foreach ($st->fetchAll() as $x) {
            $x['comprobante'] = self::comprobante($x);
            $cobros[(string) $x['forma']][] = $x;
        }

        // lo que quedó debiendo cada cliente: la parte de cada venta que fue a cuenta corriente (no es dinero)
        $st = $pdo->prepare("SELECT c.fecha, c.tipo, c.letra, c.prefijo, " . Tipos::sqlNumeroVisible('s') . " AS numero, NULL AS cobra_id, c.p_importe, COALESCE(s.cliente_razon_social, k.razon_social, '') AS cliente
            FROM caja c JOIN forma_de_pagos t ON t.codigo = c.p_fpago JOIN subdiario s ON s.fe_clave = c.subdiario_id LEFT JOIN clientes k ON k.codigo = c.cliente
            WHERE c.fecha BETWEEN ? AND ? AND t.tipo = 6 AND s.estado = '' AND s.solo_subdiario = 0 AND s.tipo IN ('FC', 'ND', 'PR', 'CM') ORDER BY c.fecha, c.fe_clave");
        $st->execute([$d, $h]);
        $fiado = array_map(static fn (array $x) => $x + ['comprobante' => self::comprobante($x)], $st->fetchAll());

        // cheques recibidos en el período, con su estado de hoy
        $st = $pdo->prepare("SELECT c.fecha, c.p_nro, c.p_fecvto, c.p_importe, c.cheque_estado, c.cheque_fecha, c.cheque_obs, COALESCE(b.descripcion, c.p_banco, '') AS banco,
                COALESCE(k.razon_social, '') AS cliente
            FROM caja c JOIN forma_de_pagos t ON t.codigo = c.p_fpago LEFT JOIN bancos b ON b.codigo = c.p_banco LEFT JOIN clientes k ON k.codigo = c.cliente
            WHERE c.fecha BETWEEN ? AND ? AND t.tipo = 5 ORDER BY c.fecha, c.fe_clave");
        $st->execute([$d, $h]);
        $cheques = $st->fetchAll();
        $chequesEstado = [];
        foreach ($cheques as $ch) {
            $et = CuentaCorrienteService::ESTADOS_CHEQUE[(string) $ch['cheque_estado']] ?? 'Otro';
            $chequesEstado[$et] = Calculadora::r2(($chequesEstado[$et] ?? 0.0) + (float) $ch['p_importe']);
        }

        // pagos a proveedores del período (órdenes de pago vigentes), por medio
        $pagos = [];
        $pagosMedio = [];
        if (self::existe('ordenes_pago')) {
            $st = $pdo->prepare("SELECT o.numero, o.fecha, p.razon_social AS proveedor, m.medio, m.importe, m.detalle, m.numero AS nro_medio
                FROM ordenes_pago o JOIN ordenes_pago_medios m ON m.orden_id = o.id LEFT JOIN proveedores p ON p.id = o.proveedor_id
                WHERE o.estado = 'V' AND o.fecha BETWEEN ? AND ? ORDER BY o.fecha, o.numero, m.id");
            $st->execute([$d, $h]);
            $pagos = $st->fetchAll();
            foreach ($pagos as $p) {
                $k = self::MEDIOS_PAGO[(string) $p['medio']] ?? 'Otro medio';
                $pagosMedio[$k] = Calculadora::r2(($pagosMedio[$k] ?? 0.0) + (float) $p['importe']);
            }
        }

        // todo lo que entró a la cuenta de Mercado Pago (ventas cobradas por Mercado Pago y transferencias que no son ventas)
        $mp = ['cantidad' => 0, 'monto' => 0.0, 'neto' => 0.0, 'sin_venta' => 0.0];
        if (self::existe('mp_ingresos')) {
            $st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(i.monto), 0), COALESCE(SUM(COALESCE(i.neto, i.monto)), 0), COALESCE(SUM(IF(c.id IS NULL, i.monto, 0)), 0)
                FROM mp_ingresos i LEFT JOIN mp_cobros c ON i.referencia <> '' AND c.token = i.referencia WHERE i.estado = 'approved' AND DATE(i.fecha) BETWEEN ? AND ?");
            $st->execute([$d, $h]);
            [$n, $monto, $neto, $sinVenta] = $st->fetch(\PDO::FETCH_NUM);
            $mp = ['cantidad' => (int) $n, 'monto' => (float) $monto, 'neto' => (float) $neto, 'sin_venta' => (float) $sinVenta];
        }

        // turnos de caja del período: quién abrió, con cuánto, y el arqueo al cerrar
        $st = $pdo->prepare('SELECT t.id, t.abierto_en, t.cerrado_en, t.estado, t.fondo_inicial, t.esperado, t.contado, t.diferencia, t.observaciones, COALESCE(u.nombre, \'\') AS usuario
            FROM caja_turnos t LEFT JOIN usuarios u ON u.id = t.usuario_id WHERE DATE(t.abierto_en) BETWEEN ? AND ? ORDER BY t.abierto_en, t.id');
        $st->execute([$d, $h]);
        $turnos = $st->fetchAll();

        $manuales = CajaDiaria::manuales($f);
        $ef = $r['efectivo'];
        return [
            'empresa' => trim((string) $pdo->query('SELECT empresa FROM parametros WHERE id = 1')->fetchColumn()) ?: \App\Modules\Configuracion\Apariencia::nombreSistema(),
            'desde' => $d, 'hasta' => $h, 'r' => $r, 'medios' => $medios, 'cobros' => $cobros, 'fiado' => $fiado, 'cheques' => $cheques, 'cheques_estado' => $chequesEstado,
            'pagos' => $pagos, 'pagos_medio' => $pagosMedio, 'mp' => $mp, 'turnos' => $turnos,
            'ingresos' => array_values(array_filter($manuales, static fn (array $x) => $x['tipo'] === 'I')),
            'egresos' => array_values(array_filter($manuales, static fn (array $x) => $x['tipo'] === 'E')),
            // dinero que entró de verdad (sin la cuenta corriente) y efectivo que salió del cajón
            'entro' => Calculadora::r2($r['total_cobrado'] + $ef['ingresos']),
            'salio' => Calculadora::r2($ef['egresos']),
        ];
    }

    /** @param array<string,mixed> $x fila de caja con tipo, letra, prefijo, numero y cobra_id */
    private static function comprobante(array $x): string
    {
        $nro = sprintf('%04d-%08d', (int) $x['prefijo'], (int) $x['numero']);
        if ($x['cobra_id'] !== null) {
            return 'Recibo X ' . $nro;
        }
        return Tipos::titulo((string) $x['tipo'], (string) $x['letra']) . ' ' . $nro;
    }

    private static function existe(string $tabla): bool
    {
        $st = Db::pdo()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $st->execute([$tabla]);
        return (int) $st->fetchColumn() > 0;
    }
}
