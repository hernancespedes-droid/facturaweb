<?php
declare(strict_types=1);

namespace App\Modules\Cambios;

use App\Core\Db;
use App\Core\Valida;
use App\Modules\Comprobantes\Calculadora;
use App\Modules\Comprobantes\ComprobantesService;
use App\Modules\Comprobantes\Tipos;
use App\Modules\Stock\StockService;
use PDO;

/**
 * Cambio de mercadería de un presupuesto o una factura (modo ticket de cambio, pedido de Hernán 18/11).
 *
 * Un cambio es un comprobante interno CM (letra X, numeración propia): lo que el cliente devuelve vuelve al stock, lo que se lleva sale, y SOLO se cobra la
 * diferencia (de contado, con vuelto si corresponde, o a cuenta corriente). Se liga al comprobante original y no se puede devolver dos veces lo mismo.
 *
 * DECISIÓN D-47 (Claude, a confirmar por Hernán): el cambio es interno (no fiscal): no toca ARCA, el subdiario de IVA ni el Libro IVA Digital, ni siquiera
 * sobre una factura. Se devuelve cada renglón al precio que pagó el cliente (con IVA y con el descuento general que tuvo); lo que se lleva se valúa al precio
 * de lista actual con IVA incluido (se puede corregir el precio de cada renglón). Si lo nuevo vale menos que lo devuelto no hay cambio: hay que sumar
 * productos hasta igualar (para devolver dinero se hace una nota de crédito). Si hay diferencia a cobrar sobre una factura y hace falta facturarla, se
 * emite una factura aparte.
 */
final class CambiosService
{
    private const CANAL = 'W';
    private PDO $pdo;
    private static ?bool $habilitado = null;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    /** ¿Está tildado «habilita modo ticket de cambio» en Parámetros? */
    public static function habilitado(): bool
    {
        if (self::$habilitado === null) {
            try {
                self::$habilitado = (int) Db::pdo()->query('SELECT ticket_cambio FROM parametros WHERE id = 1')->fetchColumn() === 1;
            } catch (\Throwable) {
                self::$habilitado = false;
            }
        }
        return self::$habilitado;
    }

    public static function reset(): void
    {
        self::$habilitado = null;
    }

    /** Código que lleva impreso el ticket (y su QR): se lee escribiéndolo o con la pistola. Ej. «PR-X-0004-00000012». */
    public static function codigo(array $h): string
    {
        return $h['tipo'] . '-' . $h['letra'] . '-' . sprintf('%04d-%08d', $h['prefijo'], Tipos::numeroVisible($h));
    }

    // ------------------------------------------------------------------ leer el ticket

    /**
     * Presupuestos y facturas que coinciden con lo escrito: el código del ticket («PR-X-0004-00000012»), «0004-12», o solo el número.
     * Siempre se devuelven todas las coincidencias: el usuario elige.
     * @return array<int,array<string,mixed>>
     */
    public function buscar(string $q): array
    {
        $q = mb_strtoupper(trim($q));
        $q = (string) preg_replace('/^TC[:\s]+/', '', $q);
        if ($q === '') {
            return [];
        }
        $w = ["s.tipo IN ('FC', 'PR')", "s.estado = ''"];
        $p = [];
        if (preg_match('/\b(PR|FC)\b/', $q, $m) === 1 || preg_match('/^(PRESUPUESTO|FACTURA)/', $q, $m2) === 1) {
            $t = $m[1] ?? (str_starts_with($q, 'PRESUPUESTO') ? 'PR' : 'FC');
            $w[] = 's.tipo = ?';
            $p[] = $t;
        }
        $nro = null;
        if (preg_match('/(\d{1,5})\s*-\s*(\d{1,8})\s*$/', $q, $m) === 1) {
            $w[] = 's.prefijo = ?';
            $p[] = (int) $m[1];
            $nro = (int) $m[2];
        } elseif (preg_match('/(\d{1,8})\s*$/', $q, $m) === 1) {
            $nro = (int) $m[1];
        }
        if ($nro === null || $nro <= 0) {
            return [];
        }
        $w[] = Tipos::sqlNumeroVisible('s') . ' = ?';
        $p[] = $nro;
        $st = $this->pdo->prepare('SELECT s.fe_clave, s.tipo, s.letra, s.prefijo, s.comprobante, s.fe_nrocbte, s.fe_ccae, s.fecha, s.neto, s.solo_subdiario,
                COALESCE(s.cliente_razon_social, c.razon_social) AS razon_social
            FROM subdiario s LEFT JOIN clientes c ON c.codigo = s.cliente WHERE ' . implode(' AND ', $w) . ' ORDER BY s.fecha DESC, s.fe_clave DESC LIMIT 30');
        $st->execute($p);
        return $st->fetchAll();
    }

    /**
     * El comprobante original con sus renglones y cuánto se puede devolver de cada uno, o el motivo por el que no se puede hacer el cambio.
     * @return string|array{h:array<string,mixed>,items:array<int,array<string,mixed>>,cliente:array<string,mixed>}
     */
    public function original(int $id, bool $bloquear = false): string|array
    {
        $st = $this->pdo->prepare('SELECT * FROM subdiario WHERE fe_clave = ?' . ($bloquear ? ' FOR UPDATE' : ''));
        $st->execute([$id]);
        $h = $st->fetch();
        if ($h === false || !in_array($h['tipo'], ['FC', 'PR'], true) || $h['estado'] !== '') {
            return 'Solo se cambia mercadería de presupuestos y facturas vigentes.';
        }
        if ((int) $h['solo_subdiario'] === 1) {
            return 'Esta factura salió de un presupuesto y no movió el stock: hacé el cambio sobre el presupuesto.';
        }
        $acre = $this->pdo->prepare(
            "SELECT COALESCE(SUM(n.neto), 0) FROM subdiario n WHERE n.tipo = ? AND n.estado = '' AND n.sucursal = ? AND n.letra = ? AND n.fe_tipocbte_asoc = ? AND n.fe_nrocbte_asoc = ?
               AND (n.fe_fecha_asoc IS NULL OR n.fe_fecha_asoc = ?)"
        );
        $acre->execute([$h['tipo'] === 'PR' ? 'NX' : 'NC', $h['sucursal'], $h['letra'], $h['tipo'], Tipos::numeroVisible($h), $h['fecha']]);
        if ((float) $acre->fetchColumn() >= (float) $h['neto'] - 0.004) {
            return 'Este comprobante ya está acreditado por completo: no se puede hacer un cambio.';
        }
        $st = $this->pdo->prepare('SELECT * FROM estadistica WHERE subdiario_id = ? ORDER BY orden, fe_clave');
        $st->execute([$id]);
        $lineas = $st->fetchAll();
        $previo = [];
        if ($lineas !== []) {
            $ids = array_map(static fn (array $l) => (int) $l['fe_clave'], $lineas);
            $q = $this->pdo->prepare("SELECT e.linea_origen, COALESCE(SUM(-e.unidades), 0) FROM estadistica e JOIN subdiario s ON s.fe_clave = e.subdiario_id
                WHERE s.tipo = 'CM' AND s.estado = '' AND e.unidades < 0 AND e.linea_origen IN (" . implode(', ', array_fill(0, count($ids), '?')) . ') GROUP BY e.linea_origen');
            $q->execute($ids);
            foreach ($q->fetchAll(PDO::FETCH_NUM) as [$l, $suma]) {
                $previo[(int) $l] = (float) $suma;
            }
        }
        $desc = (float) $h['porcentaje_descuento'];
        $items = [];
        foreach ($lineas as $l) {
            $uni = (float) $l['unidades'];
            $alic = (float) $l['por_iva'];
            $factor = (1 - $desc / 100) * ($h['letra'] === 'A' && $alic > 0 ? 1 + $alic / 100 : 1);
            $items[] = ['id' => (int) $l['fe_clave'], 'articulo' => (string) ($l['articulo'] ?? ''), 'descripcion' => (string) $l['observaciones'], 'unidades' => $uni,
                'devuelto' => $previo[(int) $l['fe_clave']] ?? 0.0, 'disponible' => max(0.0, round($uni - ($previo[(int) $l['fe_clave']] ?? 0.0), 3)),
                'unitario' => $uni > 0 ? round((float) $l['total_item'] / $uni * $factor, 4) : 0.0, 'alicuota' => $alic, 'devolvible' => ($l['articulo'] ?? '') !== '' && $uni > 0];
        }
        $c = $this->pdo->prepare('SELECT * FROM clientes WHERE codigo = ?');
        $c->execute([$h['cliente']]);
        return ['h' => $h, 'items' => $items, 'cliente' => $c->fetch() ?: []];
    }

    // ------------------------------------------------------------------ armar y emitir

    /**
     * Valida y calcula el cambio. $in: original, devolver[renglón => cantidad], nuevos[][articulo, cantidad, precio], observaciones y, si hay diferencia a cobrar,
     * forma_pago, fpago, pago_nro, pago_banco, pago_vto, recibido, excede_ok.
     *
     * @return array{ok:bool,errores:array<string,string>,cambio:?array<string,mixed>}
     */
    public function armar(array $in, bool $puedeExcederCredito = false): array
    {
        $err = [];
        $o = $this->original((int) ($in['original'] ?? 0));
        if (is_string($o)) {
            return ['ok' => false, 'errores' => ['original' => $o], 'cambio' => null];
        }
        $h = $o['h'];
        $svc = new ComprobantesService();
        $suc = $svc->sucursales()[(int) $h['sucursal']] ?? null;
        if ($suc === null) {
            $err['original'] = 'La sucursal del comprobante ya no tiene punto de venta.';
        }

        // Lo que se devuelve
        $porId = [];
        foreach ($o['items'] as $it) {
            $porId[$it['id']] = $it;
        }
        $devueltos = [];
        $totDev = 0.0;
        foreach ((array) ($in['devolver'] ?? []) as $id => $txt) {
            $txt = trim((string) $txt);
            if ($txt === '') {
                continue;
            }
            $q = Valida::decimal($txt);
            $it = $porId[(int) $id] ?? null;
            if ($it === null) {
                $err['devolver'] = 'Un renglón no es del comprobante.';
            } elseif ($q === null || $q < 0) {
                $err['devolver'] = 'Una cantidad a devolver no es válida.';
            } elseif ($q > 0) {
                if (!$it['devolvible']) {
                    $err['devolver'] = 'El renglón «' . $it['descripcion'] . '» no es mercadería que vuelva al stock.';
                } elseif ($q > $it['disponible'] + 0.0004) {
                    $err['devolver'] = 'De «' . $it['descripcion'] . '» se pueden devolver como máximo ' . rtrim(rtrim(number_format($it['disponible'], 3, ',', '.'), '0'), ',') . '.';
                } else {
                    $importe = Calculadora::r2($q * $it['unitario']);
                    $devueltos[] = ['linea' => $it['id'], 'articulo' => $it['articulo'], 'descripcion' => $it['descripcion'], 'cantidad' => round($q, 3),
                        'unitario' => $it['unitario'], 'importe' => $importe, 'alicuota' => $it['alicuota']];
                    $totDev = Calculadora::r2($totDev + $importe);
                }
            }
        }
        if ($devueltos === [] && !isset($err['devolver'])) {
            $err['devolver'] = 'Elegí qué producto devuelve el cliente (y cuántos).';
        }

        // Lo que se lleva
        $alic = $svc->alicuotas();
        $nuevos = [];
        $totNue = 0.0;
        $errNue = [];
        $n = 0;
        foreach ((array) ($in['nuevos'] ?? []) as $r) {
            $r = is_array($r) ? $r : [];
            $cod = trim((string) ($r['articulo'] ?? ''));
            $cantTxt = trim((string) ($r['cantidad'] ?? ''));
            $precioTxt = trim((string) ($r['precio'] ?? ''));
            if ($cod === '' && $cantTxt === '' && $precioTxt === '') {
                continue;
            }
            $n++;
            $art = (new StockService($this->pdo))->resolverArticulo($cod);
            $cant = Valida::decimal($cantTxt === '' ? '1' : $cantTxt);
            if ($art === null || $art['estado'] !== 'AC') {
                $errNue[] = "Producto {$n}: elegí un artículo de la lista.";
                continue;
            }
            if ($cant === null || $cant <= 0 || $cant > 999999999.0) {
                $errNue[] = "Producto {$n}: la cantidad tiene que ser mayor que cero.";
                continue;
            }
            $precio = $precioTxt === '' ? $this->precioLista($art) : Valida::decimal($precioTxt);
            if ($precio === null || $precio < 0 || $precio > 99999999999.0) {
                $errNue[] = "Producto {$n}: el precio no es válido.";
                continue;
            }
            $importe = Calculadora::r2($cant * $precio);
            $nuevos[] = ['articulo' => (string) $art['codigo'], 'descripcion' => (string) $art['descripcion'], 'cantidad' => round($cant, 3), 'precio' => round($precio, 4),
                'importe' => $importe, 'alicuota' => (float) ($alic[(string) $art['alicuota']] ?? 0.0), 'codigo_de_barras' => $art['codigo_de_barras'] ?? null];
            $totNue = Calculadora::r2($totNue + $importe);
        }
        if ($nuevos === [] && $errNue === []) {
            $errNue[] = 'Elegí el producto que se lleva el cliente. (Para devolver el dinero se hace una nota de crédito.)';
        }
        if ($errNue !== []) {
            $err['nuevos'] = implode("\n", $errNue);
        }

        $dif = Calculadora::r2($totNue - $totDev);
        if (!isset($err['devolver']) && !isset($err['nuevos']) && $dif < -0.004) {
            $err['nuevos'] = 'Lo que se lleva (' . plata($totNue) . ') vale menos que lo que se devuelve (' . plata($totDev) . '): faltan ' . plata(-$dif) . '. Sumá productos hasta igualar; para devolver dinero se hace una nota de crédito.';
        }

        // Cómo se paga la diferencia: una o varias formas de pago (como en las ventas); a cuenta corriente solo si el cliente tiene crédito
        $forma = null;
        $pagos = null;
        $excede = false;
        $cliente = $o['cliente'];
        if ($dif > 0.004 && !isset($err['devolver']) && !isset($err['nuevos'])) {
            if (trim((string) ($in['forma_pago'] ?? '')) === '' && trim((string) ($in['fpago'] ?? '')) === '' && !$svc->hayLineasDePago($in['pagos'] ?? null)) {
                $err['forma_pago'] = 'Elegí cómo se paga la diferencia: contado o cuenta corriente.';
            } else {
                $pagos = $svc->resolverPagos($in, $dif, $err);
                if ($pagos !== null) {
                    $forma = $pagos['cuenta'] > 0.004 ? 2 : 1;
                    if ($pagos['cuenta'] > 0.004 && $cliente !== [] && ($ex = $svc->creditoExcedido($cliente, $pagos['cuenta'])) !== null) {
                        $excede = true;
                        if (!($puedeExcederCredito && ($in['excede_ok'] ?? '') === '1')) {
                            $err['credito'] = 'La diferencia a cuenta corriente excede el crédito del cliente (límite ' . plata($ex['limite']) . ', deuda actual ' . plata($ex['deuda']) . '). '
                                . ($puedeExcederCredito ? 'Tildá "Autorizo exceder el crédito" para hacer el cambio.' : 'Pedile a un administrador que lo autorice.');
                        }
                    }
                }
            }
        }
        $obs = trim((string) ($in['observaciones'] ?? ''));
        if (mb_strlen($obs) > 200) {
            $err['observaciones'] = 'Las observaciones admiten hasta 200 letras.';
        }
        if ($err !== []) {
            return ['ok' => false, 'errores' => $err, 'cambio' => null];
        }
        return ['ok' => true, 'errores' => [], 'cambio' => [
            'original' => $h, 'cliente' => $cliente, 'sucursal' => (int) $h['sucursal'], 'prefijo' => (int) $h['prefijo'], 'fecha' => date('Y-m-d'),
            'devueltos' => $devueltos, 'nuevos' => $nuevos, 'devuelto_total' => $totDev, 'nuevo_total' => $totNue, 'diferencia' => max(0.0, $dif),
            'forma_pago' => $forma, 'pagos' => $pagos['lineas'] ?? [], 'pago_cuenta' => $pagos['cuenta'] ?? 0.0, 'vuelto' => $pagos['vuelto'] ?? 0.0, 'excede_credito' => $excede, 'observaciones' => $obs,
        ]];
    }

    /** Precio de lista de un artículo (con IVA incluido, D-47), pasado a pesos si está en dólares. @param array<string,mixed> $a */
    private function precioLista(array $a): float
    {
        $precio = (float) $a['precio_unitario'] > 0 ? (float) $a['precio_unitario'] : (float) $a['precio_de_venta_1'];
        return $a['moneda'] === 'u$s' ? $precio * (new ComprobantesService())->cotizacionDolar(date('Y-m-d')) : $precio;
    }

    /**
     * Graba el cambio en UNA transacción: comprobante CM con sus renglones (lo devuelto en negativo), stock, y la diferencia en caja o cuenta corriente.
     * La clave de la operación evita emitir dos veces.
     *
     * @param array<string,mixed> $c el 'cambio' de armar()
     * @return array{id:int,numero:int,repetido:bool,vuelto:float}
     */
    public function emitir(array $c, string $operacion, int $usuarioId): array
    {
        if (preg_match('/^[0-9a-f]{32}$/', $operacion) !== 1) {
            throw new \InvalidArgumentException('Falta la clave de la operación.');
        }
        $st = $this->pdo->prepare('SELECT fe_clave, comprobante FROM subdiario WHERE operacion = ?');
        $st->execute([$operacion]);
        if (($f = $st->fetch()) !== false) {
            return ['id' => (int) $f['fe_clave'], 'numero' => (int) $f['comprobante'], 'repetido' => true, 'vuelto' => 0.0];
        }
        \App\Modules\Licencia\Licencia::exigirParaEmitir();
        $h = $c['original'];
        $cli = $c['cliente'];
        $ahora = date('Y-m-d H:i:s');
        $dif = (float) $c['diferencia'];
        $this->pdo->beginTransaction();
        try {
            // el original queda bloqueado: dos cambios simultáneos no devuelven lo mismo dos veces
            $o = $this->original((int) $h['fe_clave'], true);
            if (is_string($o)) {
                throw new CambioInvalido($o);
            }
            $disp = [];
            foreach ($o['items'] as $it) {
                $disp[$it['id']] = $it['disponible'];
            }
            foreach ($c['devueltos'] as $d) {
                if ($d['cantidad'] > ($disp[$d['linea']] ?? 0.0) + 0.0004) {
                    throw new CambioInvalido('Lo que se quiere devolver de «' . $d['descripcion'] . '» ya no está disponible (otro cambio lo devolvió). Volvé a leer el ticket.');
                }
            }
            $s = $this->pdo->prepare('SELECT ultimo_cambio FROM sucursales WHERE codigo = ? FOR UPDATE');
            $s->execute([$c['sucursal']]);
            $ultimo = $s->fetchColumn();
            if ($ultimo === false) {
                throw new \RuntimeException('La sucursal ya no existe.');
            }
            $numero = (int) $ultimo + 1;
            $existe = $this->pdo->prepare("SELECT 1 FROM subdiario WHERE tipo = 'CM' AND letra = 'X' AND sucursal = ? AND comprobante = ?");
            while (true) {
                $existe->execute([$c['sucursal'], $numero]);
                if ($existe->fetchColumn() === false) {
                    break;
                }
                $numero++;
            }
            $this->pdo->prepare('UPDATE sucursales SET ultimo_cambio = GREATEST(ultimo_cambio, ?), actualizacion = ? WHERE codigo = ?')->execute([$numero, $ahora, $c['sucursal']]);
            $forma = $c['forma_pago'];
            $obs = trim('Cambio de ' . Tipos::titulo($h['tipo'], $h['letra']) . ' ' . sprintf('%04d-%08d', $h['prefijo'], Tipos::numeroVisible($h))
                . ' del ' . date('d/m/Y', (int) strtotime($h['fecha'])) . '. ' . $c['observaciones']);
            $this->insertar('subdiario', [
                'fecha' => $c['fecha'], 'tipo' => 'CM', 'letra' => 'X', 'sucursal' => $c['sucursal'], 'prefijo' => $c['prefijo'], 'comprobante' => $numero, 'cliente' => $h['cliente'],
                'bruto' => $dif, 'neto_grabado' => $dif, 'neto_exento' => 0, 'iva_1' => 0, 'bonificacion' => 0, 'neto' => $dif, 'porcentaje_descuento' => 0,
                'observaciones' => mb_substr($obs, 0, 255), 'canal' => self::CANAL, 'terminal' => 1, 'iva_cliente' => (int) $h['iva_cliente'], 'cuit_cliente' => $h['cuit_cliente'],
                'vendedor' => $h['vendedor'], 'forma_pago' => $forma ?? 1, 'actualizacion' => $ahora, 'nrointerno' => $numero, 'tipoafipoys' => 0,
                'cliente_razon_social' => $h['cliente_razon_social'], 'cliente_domicilio' => $h['cliente_domicilio'], 'cliente_localidad' => $h['cliente_localidad'],
                'creado_por' => $usuarioId > 0 ? $usuarioId : null, 'operacion' => $operacion, 'es_prueba' => 0, 'fe_entorno' => '', 'cambio_de' => (int) $h['fe_clave'],
            ]);
            $id = (int) $this->pdo->lastInsertId();

            $orden = 0;
            foreach ($c['devueltos'] as $d) {
                $uni = $d['unitario'];
                $this->insertar('estadistica', [
                    'subdiario_id' => $id, 'fecha' => $c['fecha'], 'tipo' => 'CM', 'letra' => 'X', 'sucursal' => $c['sucursal'], 'prefijo' => $c['prefijo'], 'comprobante' => $numero,
                    'orden' => ++$orden, 'articulo' => $d['articulo'], 'unidades' => -$d['cantidad'], 'unidades_ori' => -$d['cantidad'], 'lista' => 'E', 'precio' => $uni,
                    'precio_siniva' => $d['alicuota'] > 0 ? round($uni / (1 + $d['alicuota'] / 100), 4) : $uni, 'observaciones' => mb_substr('DEVUELVE: ' . $d['descripcion'], 0, 255),
                    'total_item' => -$d['importe'], 'total_siniva' => -($d['alicuota'] > 0 ? Calculadora::r2($d['importe'] / (1 + $d['alicuota'] / 100)) : $d['importe']),
                    'cliente' => $h['cliente'], 'grabado' => $d['alicuota'] > 0 ? 1 : 0, 'por_iva' => $d['alicuota'], 'actualizacion' => $ahora, 'linea_origen' => $d['linea'],
                ]);
            }
            foreach ($c['nuevos'] as $nv) {
                $this->insertar('estadistica', [
                    'subdiario_id' => $id, 'fecha' => $c['fecha'], 'tipo' => 'CM', 'letra' => 'X', 'sucursal' => $c['sucursal'], 'prefijo' => $c['prefijo'], 'comprobante' => $numero,
                    'orden' => ++$orden, 'articulo' => $nv['articulo'], 'codigo_de_barras' => $nv['codigo_de_barras'], 'unidades' => $nv['cantidad'], 'unidades_ori' => $nv['cantidad'], 'lista' => 'E',
                    'precio' => $nv['precio'], 'precio_siniva' => $nv['alicuota'] > 0 ? round($nv['precio'] / (1 + $nv['alicuota'] / 100), 4) : $nv['precio'],
                    'observaciones' => mb_substr('LLEVA: ' . $nv['descripcion'], 0, 255), 'total_item' => $nv['importe'],
                    'total_siniva' => $nv['alicuota'] > 0 ? Calculadora::r2($nv['importe'] / (1 + $nv['alicuota'] / 100)) : $nv['importe'],
                    'cliente' => $h['cliente'], 'grabado' => $nv['alicuota'] > 0 ? 1 : 0, 'por_iva' => $nv['alicuota'], 'actualizacion' => $ahora,
                ]);
            }
            (new StockService($this->pdo))->porCambio($id, $c['devueltos'], $c['nuevos'], $usuarioId > 0 ? $usuarioId : null);

            $vuelto = 0.0;
            if ($dif > 0.004) {
                $ccCodigo = $this->pdo->query('SELECT codigo FROM forma_de_pagos WHERE tipo = 6 ORDER BY id LIMIT 1')->fetchColumn() ?: 'CC';
                $cuenta = 0.0;
                foreach ($c['pagos'] as $pago) { // una fila de caja por forma de pago
                    $esCuenta = (int) $pago['tipo'] === 6;
                    $cuenta += $esCuenta ? (float) $pago['importe'] : 0.0;
                    $vuelto += (float) ($pago['vuelto'] ?? 0);
                    $this->insertar('caja', [
                        'subdiario_id' => $id, 'fecha' => $c['fecha'], 'tipo' => 'CM', 'letra' => 'X', 'canal' => self::CANAL, 'terminal' => 1, 'sucursal' => $c['sucursal'],
                        'prefijo' => $c['prefijo'], 'comprobante' => $numero, 'cliente' => $h['cliente'], 'p_fpago' => $esCuenta ? $ccCodigo : $pago['codigo'],
                        'p_nro' => $pago['nro'] ?? null, 'p_fecvto' => $esCuenta ? $c['fecha'] : ($pago['vencimiento'] ?? null), 'p_banco' => (int) ($pago['banco'] ?? 0),
                        'p_importe' => $pago['importe'], 'a_pagar' => $pago['importe'], 'actualizacion' => $ahora,
                        'p_recibido' => $esCuenta ? null : ($pago['recibido'] ?? $pago['importe']), 'p_vuelto' => $pago['vuelto'] ?? 0,
                    ]);
                }
                if ($cuenta > 0.004) {
                    $this->insertar('ctact', [
                        'subdiario_id' => $id, 'cliente' => $h['cliente'], 'fecha' => $c['fecha'], 'tipo' => 'CM', 'tico' => Tipos::tico('CM'), 'letra' => 'X', 'sucursal' => $c['sucursal'],
                        'prefijo' => $c['prefijo'], 'numero' => $numero, 'neto' => $cuenta, 'descuento' => 0, 'saldo' => $cuenta, 'vencimiento' => $c['fecha'],
                        'valor_dolar' => (new ComprobantesService())->cotizacionDolar($c['fecha']), 'actualizacion' => $ahora,
                    ]);
                }
            }
            $this->pdo->commit();
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_subdiario_operacion')) {
                $st->execute([$operacion]);
                if (($f = $st->fetch()) !== false) {
                    return ['id' => (int) $f['fe_clave'], 'numero' => (int) $f['comprobante'], 'repetido' => true, 'vuelto' => 0.0];
                }
            }
            throw $e;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return ['id' => $id, 'numero' => $numero, 'repetido' => false, 'vuelto' => $vuelto];
    }

    /** @param array<string,mixed> $fila */
    private function insertar(string $tabla, array $fila): void
    {
        $cols = array_keys($fila);
        $this->pdo->prepare("INSERT INTO `{$tabla}` (`" . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($fila));
    }
}
