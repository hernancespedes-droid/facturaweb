<?php
declare(strict_types=1);

namespace App\Modules\Tablero;

use App\Core\Db;
use App\Core\Menu;
use App\Core\Modulos;
use App\Modules\Auth\Auth;
use App\Modules\Comprobantes\Calculadora;
use App\Modules\Listados\Estadistica;

/**
 * Tablero de inicio (0.40.0): cómo viene el negocio en números y gráficos, y lo que hay que atender.
 *
 * DECISIÓN D-90 (Claude, aprobada por Hernán el 21/11/2026): las cifras de dinero del tablero (ventas, cobros, rankings) se ven solo con `listados.ver`
 * (son las mismas que los listados); quien solo tiene `comprobantes.ver` ve cuántos comprobantes se hicieron, sin importes. El margen, solo con
 * `listados.costos`; la deuda y los cheques con `ctacte.ver`; el stock con `stock.ver`; el turno con `caja.turno`; ARCA con `arca.administrar`;
 * Mercado Pago con `mercadopago.cobrar`. Cada parte respeta además el módulo encendido de la instalación (`Core\Modulos`).
 *
 * DECISIÓN D-91 (Claude, aprobada por Hernán el 21/11/2026): «venta» es lo mismo que «lo vendido» de la caja diaria: el total final de cada comprobante
 * (`subdiario.neto`, que en el esquema O&S es el total con IVA) de FC, ND, PR y CM menos NC y NX, sin la factura que sale de un presupuesto
 * (solo_subdiario = 1) ni lo anulado. Los rankings salen de la estadística (también con IVA); el margen es el neto sin IVA contra el costo.
 * La comparación es contra el mismo tramo del período anterior: hoy contra el mismo día de la semana pasada, el mes en curso contra los mismos
 * días del mes anterior, el mes pasado contra el anterior y los últimos 12 meses contra los 12 de antes.
 *
 * DECISIÓN D-92 (Claude, aprobada por Hernán el 21/11/2026): cheques «por vencer» = en cartera con vencimiento de hoy a 7 días; deuda vencida = débitos con
 * saldo cuyo vencimiento (o fecha, si no tiene) ya pasó; stock bajo = artículos activos que controlan stock con mínimo cargado y saldo menor.
 */
final class Tablero
{
    public const PERIODOS = ['hoy' => 'Hoy', 'mes' => 'Este mes', 'anterior' => 'Mes pasado', '12m' => '12 meses'];

    /** Tipos que suman venta; las NC y NX restan. */
    private const VENTA = "s.estado = '' AND s.solo_subdiario = 0 AND s.tipo IN ('FC', 'ND', 'PR', 'CM', 'NC', 'NX')";
    private const SIGNO = "IF(s.tipo IN ('NC', 'NX'), -1, 1)";

    /** Grupos de forma de pago para «Cómo te pagaron» (tipo de `forma_de_pagos` => grupo); lo demás va a «Otros». */
    private const GRUPOS_PAGO = [1 => 'Efectivo', 3 => 'Tarjetas', 10 => 'Billeteras virtuales', 5 => 'Cheques'];

    /** Accesos rápidos: rutas del menú, en este orden; se muestran las primeras 6 que el usuario puede usar. */
    private const ACCESOS = ['/comprobantes/nuevo?tipo=FC', '/comprobantes/nuevo?tipo=PR', '/kiosco', '/recibos/nuevo', '/caja', '/listados/estadistica',
        '/clientes', '/articulos', '/comprobantes', '/cuenta-corriente', '/stock'];

    /**
     * Tarjetas que agregan otros módulos al tablero (por ejemplo compras): ['modulo' => 'compras', 'permiso' => 'compras.ver', 'vista' => 'compras/_tablero'].
     * La vista recibe $tb (estos datos) y se dibuja en su propia fila.
     */
    public const EXTRAS = [];

    private const MESES = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    private const MESES_CORTO = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    private const DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    /**
     * Fechas del período y de la comparación.
     * @return array{clave:string,desde:string,hasta:string,cmp_desde:string,cmp_hasta:string,titulo:string,vs:string,mes:string}
     */
    public static function periodo(string $clave, ?string $hoy = null): array
    {
        $h = new \DateTimeImmutable($hoy ?? date('Y-m-d'));
        $clave = isset(self::PERIODOS[$clave]) ? $clave : 'mes';
        $ini = $h->modify('first day of this month');
        $f = static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d');
        switch ($clave) {
            case 'hoy':
                $c = $h->modify('-7 days');
                return ['clave' => $clave, 'desde' => $f($h), 'hasta' => $f($h), 'cmp_desde' => $f($c), 'cmp_hasta' => $f($c),
                    'titulo' => 'Ventas de hoy', 'vs' => 'vs. el ' . self::DIAS[(int) $c->format('w')] . ' pasado', 'mes' => $f($ini)];
            case 'anterior':
                $a = $ini->modify('-1 month');
                $b = $a->modify('-1 month');
                return ['clave' => $clave, 'desde' => $f($a), 'hasta' => $f($a->modify('last day of this month')),
                    'cmp_desde' => $f($b), 'cmp_hasta' => $f($b->modify('last day of this month')),
                    'titulo' => 'Ventas de ' . self::MESES[(int) $a->format('n')], 'vs' => 'vs. ' . self::MESES[(int) $b->format('n')], 'mes' => $f($a)];
            case '12m':
                $d = $ini->modify('-11 months');
                return ['clave' => $clave, 'desde' => $f($d), 'hasta' => $f($h), 'cmp_desde' => $f($d->modify('-12 months')), 'cmp_hasta' => $f($h->modify('-12 months')),
                    'titulo' => 'Ventas de los últimos 12 meses', 'vs' => 'vs. los 12 meses anteriores', 'mes' => $f($ini)];
            default:
                $dia = (int) $h->format('j');
                $prev = $ini->modify('-1 month');
                $finPrev = $prev->modify('+' . (min($dia, (int) $prev->format('t')) - 1) . ' days');
                return ['clave' => 'mes', 'desde' => $f($ini), 'hasta' => $f($h), 'cmp_desde' => $f($prev), 'cmp_hasta' => $f($finPrev),
                    'titulo' => 'Ventas de ' . self::MESES[(int) $h->format('n')] . ($dia < (int) $h->format('t') ? ' (al día ' . $dia . ')' : ''),
                    'vs' => 'vs. los primeros ' . $dia . ' días de ' . self::MESES[(int) $prev->format('n')], 'mes' => $f($ini)];
        }
    }

    /** Venta (total con IVA, NC restadas) y cantidad de comprobantes de venta (sin NC/NX) entre dos fechas. @return array{neto:float,ventas:int,comprobantes:int} */
    public static function ventas(string $desde, string $hasta, int $sucursal = 0): array
    {
        [$w, $p] = self::where($desde, $hasta, $sucursal);
        $st = Db::pdo()->prepare('SELECT COALESCE(SUM(s.neto * ' . self::SIGNO . "), 0), SUM(s.tipo NOT IN ('NC', 'NX')), COUNT(*) FROM subdiario s WHERE {$w}");
        $st->execute($p);
        $r = $st->fetch(\PDO::FETCH_NUM);
        return ['neto' => Calculadora::r2((float) $r[0]), 'ventas' => (int) $r[1], 'comprobantes' => (int) $r[2]];
    }

    /** Venta neta por día. @return array<string,float> fecha => neto */
    public static function porDia(string $desde, string $hasta, int $sucursal = 0): array
    {
        [$w, $p] = self::where($desde, $hasta, $sucursal);
        $st = Db::pdo()->prepare('SELECT s.fecha, SUM(s.neto * ' . self::SIGNO . ") FROM subdiario s WHERE {$w} GROUP BY s.fecha");
        $st->execute($p);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_NUM) as [$d, $v]) {
            $out[(string) $d] = Calculadora::r2((float) $v);
        }
        return $out;
    }

    /** Venta neta de los 12 meses que terminan en el mes de $hasta. @return array<int,array{mes:string,neto:float,parcial:bool}> */
    public static function porMes(string $hasta, int $sucursal = 0, ?string $hoy = null): array
    {
        $fin = (new \DateTimeImmutable($hasta))->modify('first day of this month');
        $ini = $fin->modify('-11 months');
        [$w, $p] = self::where($ini->format('Y-m-d'), $fin->modify('last day of this month')->format('Y-m-d'), $sucursal);
        $st = Db::pdo()->prepare("SELECT DATE_FORMAT(s.fecha, '%Y-%m'), SUM(s.neto * " . self::SIGNO . ") FROM subdiario s WHERE {$w} GROUP BY 1");
        $st->execute($p);
        $v = [];
        foreach ($st->fetchAll(\PDO::FETCH_NUM) as [$m, $x]) {
            $v[(string) $m] = (float) $x;
        }
        $mesHoy = substr($hoy ?? date('Y-m-d'), 0, 7);
        $out = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $ini->modify("+{$i} months")->format('Y-m');
            $out[] = ['mes' => $m, 'neto' => Calculadora::r2($v[$m] ?? 0.0), 'parcial' => $m === $mesHoy];
        }
        return $out;
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private static function where(string $desde, string $hasta, int $sucursal): array
    {
        $w = self::VENTA . ' AND s.fecha BETWEEN ? AND ?';
        $p = [$desde, $hasta];
        if ($sucursal > 0) {
            $w .= ' AND s.sucursal = ?';
            $p[] = $sucursal;
        }
        return [$w, $p];
    }

    /** Lo cobrado por grupo de forma de pago (como la caja diaria: sin cuenta corriente). @return array<int,array{nombre:string,importe:float}> */
    public static function cobros(string $desde, string $hasta, int $sucursal = 0): array
    {
        $sql = 'SELECT t.tipo, COALESCE(SUM(c.p_importe), 0) FROM caja c JOIN forma_de_pagos t ON t.codigo = c.p_fpago WHERE c.fecha BETWEEN ? AND ? AND t.tipo <> 6';
        $p = [$desde, $hasta];
        if ($sucursal > 0) {
            $sql .= ' AND c.sucursal = ?';
            $p[] = $sucursal;
        }
        $st = Db::pdo()->prepare($sql . ' GROUP BY t.tipo');
        $st->execute($p);
        $g = array_fill_keys(array_values(self::GRUPOS_PAGO), 0.0) + ['Otros' => 0.0];
        foreach ($st->fetchAll(\PDO::FETCH_NUM) as [$tipo, $imp]) {
            $g[self::GRUPOS_PAGO[(int) $tipo] ?? 'Otros'] += (float) $imp;
        }
        $out = [];
        foreach ($g as $n => $v) {
            $out[] = ['nombre' => $n, 'importe' => Calculadora::r2($v)];
        }
        return $out;
    }

    /** Lo que deben los clientes (débitos menos créditos con saldo) y la parte vencida. @return array{total:float,vencido:float,clientes_vencidos:int} */
    public static function deuda(?string $hoy = null): array
    {
        $st = Db::pdo()->prepare('SELECT COALESCE(SUM(IF(tico < 7, saldo, -saldo)), 0), COALESCE(SUM(IF(tico < 7 AND COALESCE(vencimiento, fecha) < ?, saldo, 0)), 0),
            COUNT(DISTINCT IF(tico < 7 AND COALESCE(vencimiento, fecha) < ?, cliente, NULL)) FROM ctact WHERE saldo > 0.004');
        $h = $hoy ?? date('Y-m-d');
        $st->execute([$h, $h]);
        $r = $st->fetch(\PDO::FETCH_NUM);
        return ['total' => Calculadora::r2((float) $r[0]), 'vencido' => Calculadora::r2((float) $r[1]), 'clientes_vencidos' => (int) $r[2]];
    }

    /**
     * Todo lo que dibuja la pantalla de inicio, según lo que puede el usuario.
     * @param array<string,mixed> $q periodo, sucursal
     * @return array<string,mixed>
     */
    public static function datos(array $q, ?string $hoy = null): array
    {
        $hoy ??= date('Y-m-d');
        $pdo = Db::pdo();
        $per = self::periodo((string) ($q['periodo'] ?? 'mes'), $hoy);
        $sucursales = $pdo->query('SELECT codigo, descripcion FROM sucursales ORDER BY codigo')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $suc = ctype_digit((string) ($q['sucursal'] ?? '')) && isset($sucursales[(int) $q['sucursal']]) ? (int) $q['sucursal'] : 0;
        $ver = static fn (string $permiso, ?string $modulo = null): bool => Auth::puede($permiso) && ($modulo === null || Modulos::activo($modulo));
        $tb = ['periodo' => $per, 'sucursal' => $suc, 'sucursales' => $sucursales, 'tiles' => [], 'alertas' => [], 'hoy' => $hoy,
            'ventas' => null, 'acumulado' => null, 'meses' => null, 'rankings' => [], 'cobros' => null, 'conteo' => null];

        if ($ver('listados.ver', 'listados')) {
            $act = self::ventas($per['desde'], $per['hasta'], $suc);
            $ant = self::ventas($per['cmp_desde'], $per['cmp_hasta'], $suc);
            $tb['ventas'] = ['actual' => $act, 'anterior' => $ant, 'delta' => $ant['neto'] > 0 ? ($act['neto'] / $ant['neto'] - 1) * 100 : null];
            $tb['meses'] = self::porMes($per['clave'] === 'anterior' ? $per['hasta'] : $hoy, $suc, $hoy);
            $tb['acumulado'] = self::acumulado($per['mes'], $hoy, $suc);
            if ($per['clave'] !== 'hoy') {
                $h = self::ventas($hoy, $hoy, $suc);
                $tb['tiles'][] = ['label' => 'Hoy', 'valor' => self::pesos($h['neto']), 'sub' => self::cantidad($h['comprobantes'], 'comprobante', 'comprobantes'), 'url' => '/listados/ventas-diarias'];
            } else {
                $tb['tiles'][] = ['label' => 'Comprobantes de hoy', 'valor' => number_format($act['comprobantes'], 0, ',', '.'), 'sub' => self::cantidad($act['ventas'], 'venta', 'ventas'), 'url' => '/listados/ventas-diarias'];
            }
            $tb['tiles'][] = ['label' => 'Ticket promedio', 'valor' => $act['ventas'] > 0 ? self::pesos($act['neto'] / $act['ventas']) : '—',
                'sub' => self::cantidad($act['ventas'], 'venta', 'ventas') . ' en el período', 'url' => '/listados/ventas-diarias'];
            $f = ['desde' => self::dmy($per['desde']), 'hasta' => self::dmy($per['hasta']), 'sucursal' => $suc > 0 ? (string) $suc : ''];
            foreach (['articulo' => 'Lo más vendido', 'cliente' => 'Mejores clientes'] as $ag => $titulo) {
                $r = Estadistica::resumen($f + ['agrupar' => $ag, 'primeros' => '6', 'por' => 'importe']);
                $filas = array_values(array_filter($r['filas'], static fn ($x) => (float) $x['neto'] > 0.004));
                $tb['rankings'][] = ['titulo' => $titulo, 'sub' => 'Los primeros 6, por importe con IVA', 'filas' => $filas,
                    'url' => '/listados/estadistica?' . http_build_query($f + ['agrupar' => $ag])];
            }
            if ($ver('listados.costos')) {
                $r = Estadistica::resumen($f + ['agrupar' => 'mes', 'primeros' => '1']);
                $pct = $r['totales']['margen_pct'];
                $tb['tiles'][] = ['label' => 'Margen bruto', 'valor' => $pct === null ? '—' : number_format((float) $pct, 1, ',', '.') . ' %',
                    'sub' => $pct === null ? 'Sin ventas en el período' : self::pesos((float) $r['totales']['margen']) . ' sobre el neto', 'url' => '/listados/rentabilidad?' . http_build_query($f)];
            }
            if (Modulos::activo('caja')) {
                $c = self::cobros($per['desde'], $per['hasta'], $suc);
                $tot = array_sum(array_column($c, 'importe'));
                $tb['cobros'] = ['grupos' => $c, 'total' => Calculadora::r2($tot)];
                $tb['tiles'][] = ['label' => 'Cobrado', 'valor' => self::pesos($tot), 'sub' => 'Contado y recibos, sin cuenta corriente',
                    'url' => '/listados/caja-diaria?' . http_build_query(['desde' => self::dmy($per['desde']), 'hasta' => self::dmy($per['hasta'])])];
            }
        } elseif ($ver('comprobantes.ver')) {
            $tb['conteo'] = self::ventas($per['desde'], $per['hasta'], $suc);
            $tb['tiles'][] = ['label' => 'Comprobantes', 'valor' => number_format($tb['conteo']['comprobantes'], 0, ',', '.'), 'sub' => self::PERIODOS[$per['clave']], 'url' => '/comprobantes'];
        }

        if ($ver('ctacte.ver', 'cuenta_corriente')) {
            $d = self::deuda($hoy);
            $tb['tiles'][] = ['label' => 'Te deben los clientes', 'valor' => self::pesos($d['total']), 'sub' => self::pesos($d['vencido']) . ' vencido', 'url' => '/cuenta-corriente'];
            if ($d['clientes_vencidos'] > 0) {
                $tb['alertas'][] = ['nivel' => 'info', 'icono' => 'persona', 'titulo' => 'Deuda vencida de ' . self::cantidad($d['clientes_vencidos'], 'cliente', 'clientes'),
                    'sub' => self::pesos($d['vencido']) . ' en total', 'cta' => 'Ver deuda', 'url' => '/cuenta-corriente'];
            }
            $st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(c.p_importe), 0) FROM caja c JOIN forma_de_pagos t ON t.codigo = c.p_fpago
                WHERE t.tipo = 5 AND c.cheque_estado = '' AND c.p_fecvto BETWEEN ? AND ?");
            $st->execute([$hoy, date('Y-m-d', strtotime($hoy . ' +7 days'))]);
            [$n, $imp] = $st->fetch(\PDO::FETCH_NUM);
            if ((int) $n > 0) {
                $tb['alertas'][] = ['nivel' => 'aviso', 'icono' => 'reloj', 'titulo' => self::cantidad((int) $n, 'cheque vence', 'cheques vencen') . ' en los próximos 7 días',
                    'sub' => self::pesos((float) $imp) . ' en cartera', 'cta' => 'Ver cheques',
                    'url' => '/cheques?' . http_build_query(['desde' => self::dmy($hoy), 'hasta' => self::dmy(date('Y-m-d', strtotime($hoy . ' +7 days')))])];
            }
        }

        if ($ver('caja.turno', 'caja')) {
            $st = $pdo->prepare("SELECT abierto_en FROM caja_turnos WHERE usuario_id = ? AND estado = 'A' ORDER BY id DESC LIMIT 1");
            $st->execute([(int) (Auth::usuario()['id'] ?? 0)]);
            $ab = $st->fetchColumn();
            $tb['tiles'][] = $ab === false
                ? ['label' => 'Tu turno de caja', 'valor' => 'Cerrado', 'sub' => 'Abrilo para empezar a cobrar', 'url' => '/caja']
                : ['label' => 'Tu turno de caja', 'valor' => 'Abierto', 'sub' => 'Desde ' . (substr((string) $ab, 0, 10) === $hoy ? 'las ' . substr((string) $ab, 11, 5) : 'el ' . self::dmy(substr((string) $ab, 0, 10)) . ' ' . substr((string) $ab, 11, 5)), 'url' => '/caja'];
        }

        if ($ver('arca.administrar')) {
            $n = (int) $pdo->query("SELECT COUNT(*) FROM afip_operaciones WHERE estado IN ('aprobada', 'error', 'pendiente') AND creado_en < NOW() - INTERVAL 2 MINUTE")->fetchColumn();
            if ($n > 0) {
                array_unshift($tb['alertas'], ['nivel' => 'grave', 'icono' => 'alerta', 'titulo' => self::cantidad($n, 'pedido a ARCA sin terminar', 'pedidos a ARCA sin terminar'),
                    'sub' => 'Puede haber un CAE aprobado y no grabado', 'cta' => 'Revisar', 'url' => '/arca']);
            }
        }

        if ($ver('mercadopago.cobrar', 'mercadopago')) {
            $r = $pdo->query("SELECT SUM(estado = 'error_emision'), SUM(estado = 'pendiente' AND vence_en > NOW()) FROM mp_cobros")->fetch(\PDO::FETCH_NUM);
            if ((int) $r[0] > 0) {
                array_unshift($tb['alertas'], ['nivel' => 'grave', 'icono' => 'alerta', 'titulo' => self::cantidad((int) $r[0], 'cobro de Mercado Pago sin emitir', 'cobros de Mercado Pago sin emitir'),
                    'sub' => 'Se acreditaron pero falta el comprobante', 'cta' => 'Ver cobros', 'url' => '/mercadopago']);
            }
            if ((int) $r[1] > 0) {
                $tb['alertas'][] = ['nivel' => 'info', 'icono' => 'billete', 'titulo' => self::cantidad((int) $r[1], 'cobro de Mercado Pago esperando el pago', 'cobros de Mercado Pago esperando el pago'),
                    'sub' => 'Se emiten solos al acreditarse', 'cta' => 'Ver cobros', 'url' => '/mercadopago'];
            }
        }

        if ($ver('stock.ver', 'stock')) {
            $n = (int) $pdo->query("SELECT COUNT(*) FROM (SELECT a.codigo FROM articulos a LEFT JOIN stock_saldos s ON s.articulo = a.codigo
                WHERE a.estado = 'AC' AND a.controla_stock = 1 AND a.stock_minimo_unidades > 0 GROUP BY a.codigo, a.stock_minimo_unidades
                HAVING COALESCE(SUM(s.saldo), 0) < a.stock_minimo_unidades) t")->fetchColumn();
            if ($n > 0) {
                $tb['alertas'][] = ['nivel' => 'aviso', 'icono' => 'caja', 'titulo' => self::cantidad($n, 'artículo bajo el stock mínimo', 'artículos bajo el stock mínimo'),
                    'sub' => 'Sumando todos los depósitos', 'cta' => 'Ver stock', 'url' => '/stock?bajo=1&todos=1'];
            }
        }

        $tb['accesos'] = self::accesos();
        $tb['extras'] = array_values(array_filter(self::EXTRAS, static fn (array $x): bool => $ver($x['permiso'], $x['modulo'])));
        return $tb;
    }

    /** Venta acumulada día a día del mes de $mes y del anterior (para el gráfico de líneas). @return array<string,mixed> */
    public static function acumulado(string $mes, string $hoy, int $sucursal = 0): array
    {
        $ini = (new \DateTimeImmutable($mes))->modify('first day of this month');
        $prev = $ini->modify('-1 month');
        $fin = $ini->modify('last day of this month');
        $hasta = min($fin->format('Y-m-d'), $hoy);
        $dias = $hasta < $ini->format('Y-m-d') ? 0 : (int) (new \DateTimeImmutable($hasta))->format('j');
        $v = self::porDia($prev->format('Y-m-d'), $fin->format('Y-m-d'), $sucursal);
        $serie = static function (\DateTimeImmutable $d0, int $n) use ($v): array {
            $s = 0.0;
            $out = [];
            for ($i = 0; $i < $n; $i++) {
                $s += $v[$d0->modify("+{$i} days")->format('Y-m-d')] ?? 0.0;
                $out[] = Calculadora::r2($s);
            }
            return $out;
        };
        return [
            'actual' => $serie($ini, $dias), 'anterior' => $serie($prev, (int) $prev->format('t')), 'dias_mes' => (int) $fin->format('j'),
            'nombre' => self::MESES[(int) $ini->format('n')], 'nombre_anterior' => self::MESES[(int) $prev->format('n')],
        ];
    }

    /** Accesos rápidos que el usuario puede usar (del menú). @return array<int,array{texto:string,ruta:string}> */
    public static function accesos(): array
    {
        $porRuta = [];
        foreach (Menu::grupos() as $items) {
            foreach ($items as $o) {
                $porRuta[$o['ruta']] = $o['texto'];
            }
        }
        $out = [];
        foreach (self::ACCESOS as $r) {
            if (isset($porRuta[$r]) && count($out) < 6) {
                $out[] = ['texto' => $porRuta[$r], 'ruta' => $r];
            }
        }
        return $out;
    }

    public static function mesCorto(string $ym): string
    {
        return self::MESES_CORTO[(int) substr($ym, 5, 2)];
    }

    public static function mesLargo(string $ym): string
    {
        return self::MESES[(int) substr($ym, 5, 2)] . ' ' . substr($ym, 0, 4);
    }

    public static function pesos(float $v): string
    {
        return ($v < 0 ? '−$ ' : '$ ') . number_format(abs(round($v)), 0, ',', '.');
    }

    /** Importe corto para ejes y rótulos: $ 18,7 M · $ 845 mil · $ 900. */
    public static function corto(float $v): string
    {
        $a = abs($v);
        $s = $v < 0 ? '−' : '';
        if ($a >= 1e6) {
            return $s . '$ ' . number_format($a / 1e6, $a >= 1e8 ? 0 : 1, ',', '.') . ' M';
        }
        if ($a >= 1e3) {
            return $s . '$ ' . number_format($a / 1e3, 0, ',', '.') . ' mil';
        }
        return $s . '$ ' . number_format($a, 0, ',', '.');
    }

    private static function cantidad(int $n, string $uno, string $varios): string
    {
        return number_format($n, 0, ',', '.') . ' ' . ($n === 1 ? $uno : $varios);
    }

    private static function dmy(string $ymd): string
    {
        return substr($ymd, 8, 2) . '/' . substr($ymd, 5, 2) . '/' . substr($ymd, 0, 4);
    }
}
