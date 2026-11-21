<?php
declare(strict_types=1);

namespace App\Modules\Compras;

use App\Core\Db;
use App\Core\Modulos;
use App\Core\Orden;
use App\Core\Valida;
use App\Core\ExcelListado;
use App\Modules\Caja\CajaService;
use App\Modules\Comprobantes\Calculadora;
use App\Modules\Comprobantes\Tipos;
use PDO;

/**
 * Compras y gastos: armar() valida y calcula, grabar() lo guarda todo en una transacción (con la clave de operación contra el doble envío),
 * anular() y pagar(). Los importes se guardan positivos; la nota de crédito resta en los listados.
 *
 * DECISIÓN D-111: el costo que se reparte entre centros es el total sin las percepciones (son pagos a cuenta de impuestos) y, si el crédito de IVA
 * se computa, también sin el IVA. El IVA se computa cuando la empresa es Responsable inscripto y el comprobante es A o M (no un gasto sin comprobante).
 * DECISIÓN D-112: el IVA de cada alícuota se propone calculado pero se puede corregir hasta $ 1 (o el 0,5 % del neto) para que coincida con el impreso;
 * el total calculado tiene que dar igual (±1 centavo) al total impreso del comprobante.
 * DECISIÓN D-113: el pago en efectivo sale de la caja del turno de quien carga (egreso en caja_movimientos, sin el tope de Parámetros, que es para
 * movimientos manuales); sin turno abierto hay que elegir otro medio o cuenta corriente. Una nota de crédito en efectivo es un ingreso a la caja.
 * Si el cliente no tiene el módulo de caja, el efectivo se registra como pagado sin movimiento de caja.
 */
final class ComprasService
{
    public const TIPOS = ['FC' => 'Factura', 'ND' => 'Nota de débito', 'NC' => 'Nota de crédito', 'TQ' => 'Tique', 'GS' => 'Gasto sin comprobante'];
    public const LETRAS = ['A', 'B', 'C', 'M'];
    public const ALICUOTAS = ['21' => 21.0, '10.5' => 10.5, '27' => 27.0, '5' => 5.0, '2.5' => 2.5];
    public const TRIBUTOS = ['IVA' => 'Percepción de IVA', 'IIBB' => 'Percepción de Ingresos Brutos', 'GAN' => 'Percepción de Ganancias', 'MUN' => 'Tasa municipal', 'OTR' => 'Otro impuesto'];
    public const FORMAS = ['E' => 'Efectivo de la caja', 'O' => 'Transferencia u otro medio (ya pagado)', 'C' => 'Cuenta corriente (a pagar)'];
    public const MEDIOS = ['E' => 'Efectivo de la caja', 'T' => 'Transferencia', 'O' => 'Otro medio'];
    public const ADJUNTO_MAX = 5 * 1024 * 1024;
    public const ADJUNTOS_TIPOS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    /** ¿La empresa computa crédito fiscal? (Responsable inscripto) */
    public function empresaInscripta(): bool
    {
        $iva = $this->pdo->query('SELECT iva FROM parametros WHERE id = 1')->fetchColumn();
        return Tipos::condicionEmisor($iva === false ? null : (int) $iva) === 'RI';
    }

    public static function discrimina(string $tipo, string $letra): bool
    {
        return $tipo !== 'GS' && in_array($letra, ['A', 'M'], true);
    }

    public static function signo(string $tipo): int
    {
        return $tipo === 'NC' ? -1 : 1;
    }

    public static function titulo(array $c): string
    {
        $t = self::TIPOS[$c['tipo']] ?? $c['tipo'];
        if ($c['tipo'] === 'GS') {
            return $t . ((int) $c['numero'] > 0 ? ' ' . (int) $c['numero'] : '');
        }
        return $t . ' ' . $c['letra'] . ' ' . sprintf('%05d-%08d', (int) $c['punto_venta'], (int) $c['numero']);
    }

    // ---------------------------------------------------------------- armar

    /**
     * Valida y calcula lo que llega del formulario. No graba nada.
     *
     * @param array<string,mixed> $d
     * @return array{ok:bool,errores:array<string,string>,compra?:array<string,mixed>}
     */
    public function armar(array $d): array
    {
        $e = [];
        $c = [];
        $prov = (new ProveedoresService())->obtener((int) ($d['proveedor'] ?? 0));
        if ($prov === null || (int) $prov['activo'] !== 1) {
            $e['proveedor'] = 'Elegí el proveedor de la lista.';
        }
        $c['proveedor_id'] = $prov === null ? 0 : (int) $prov['id'];
        $c['tipo'] = (string) ($d['tipo'] ?? 'FC');
        if (!isset(self::TIPOS[$c['tipo']])) {
            $e['tipo'] = 'Elegí el tipo de comprobante.';
            $c['tipo'] = 'FC';
        }
        $gs = $c['tipo'] === 'GS';
        $c['letra'] = $gs ? '' : strtoupper(trim((string) ($d['letra'] ?? '')));
        if (!$gs && !in_array($c['letra'], self::LETRAS, true)) {
            $e['letra'] = 'Elegí la letra (A, B, C o M).';
        }
        $pv = trim((string) ($d['punto_venta'] ?? ''));
        $nro = trim((string) ($d['numero'] ?? ''));
        $c['punto_venta'] = ctype_digit($pv) ? (int) $pv : ($pv === '' ? 0 : -1);
        $c['numero'] = ctype_digit($nro) ? (int) $nro : ($nro === '' ? 0 : -1);
        if (!$gs && ($c['punto_venta'] < 1 || $c['punto_venta'] > 99999)) {
            $e['punto_venta'] = 'El punto de venta va de 1 a 99999.';
        }
        if (!$gs && ($c['numero'] < 1 || $c['numero'] > 99999999)) {
            $e['numero'] = 'El número va de 1 a 99999999.';
        }
        if ($gs && ($c['punto_venta'] < 0 || $c['numero'] < 0 || $c['numero'] > 99999999)) {
            $e['numero'] = 'El número de referencia tiene que ser un número (o vacío).';
        }
        $c['fecha'] = Valida::fecha((string) ($d['fecha'] ?? '')) ?? '';
        if ($c['fecha'] === '') {
            $e['fecha'] = 'La fecha no es válida.';
        } elseif ($c['fecha'] > date('Y-m-d')) {
            $e['fecha'] = 'La fecha no puede ser posterior a hoy.';
        }
        $per = trim((string) ($d['periodo_iva'] ?? ''));
        if (preg_match('#^(\d{1,2})/(\d{4})$#', $per, $m) === 1) {
            $per = sprintf('%04d-%02d', (int) $m[2], (int) $m[1]);
        }
        $c['periodo_iva'] = $per === '' && $c['fecha'] !== '' ? substr($c['fecha'], 0, 7) : $per;
        if (!CentrosService::periodoValido($c['periodo_iva'])) {
            $e['periodo_iva'] = 'El período de IVA es mes/año (por ejemplo 11/2026).';
        } elseif ($c['fecha'] !== '' && $c['periodo_iva'] < substr($c['fecha'], 0, 7)) {
            $e['periodo_iva'] = 'El período de IVA no puede ser anterior al mes del comprobante.';
        }
        $c['cae'] = preg_replace('/\D/', '', (string) ($d['cae'] ?? '')) ?? '';
        if (strlen($c['cae']) > 20) {
            $e['cae'] = 'El CAE tiene 14 números.';
        }
        $c['observaciones'] = trim((string) ($d['observaciones'] ?? ''));
        if (mb_strlen($c['observaciones']) > 255) {
            $e['observaciones'] = 'Hasta 255 letras.';
        }

        // ------- importes
        $num = static function (mixed $v): ?float {
            $t = trim((string) $v);
            if ($t === '') {
                return 0.0;
            }
            $x = Valida::decimal($t);
            return $x === null || $x < 0 ? null : Calculadora::r2($x);
        };
        $discrimina = self::discrimina($c['tipo'], $c['letra']);
        $c['computa_iva'] = $discrimina && $this->empresaInscripta() ? 1 : 0;
        $c['alicuotas'] = [];
        $c['gravado'] = $c['iva'] = $c['no_gravado'] = $c['exento'] = 0.0;
        if ($discrimina) {
            $netos = is_array($d['neto'] ?? null) ? $d['neto'] : [];
            $ivas = is_array($d['iva'] ?? null) ? $d['iva'] : [];
            foreach (self::ALICUOTAS as $k => $a) {
                $neto = $num($netos[$k] ?? '');
                if ($neto === null) {
                    $e['neto'] = 'El neto al ' . self::pct($a) . ' no es un importe válido.';
                    continue;
                }
                if ($neto < 0.005) {
                    continue;
                }
                $calc = Calculadora::r2($neto * $a / 100);
                $txt = trim((string) ($ivas[$k] ?? ''));
                $iva = $txt === '' ? $calc : $num($txt);
                if ($iva === null || abs($iva - $calc) > max(1.0, $neto * 0.005)) {
                    $e['neto'] = 'El IVA al ' . self::pct($a) . ' no corresponde a ese neto (daría ' . plata_dec($calc) . ').';
                    continue;
                }
                $c['alicuotas'][] = ['alicuota' => $a, 'neto' => $neto, 'iva' => $iva];
                $c['gravado'] += $neto;
                $c['iva'] += $iva;
            }
            foreach (['no_gravado', 'exento'] as $k) {
                $v = $num($d[$k] ?? '');
                if ($v === null) {
                    $e[$k] = 'No es un importe válido.';
                } else {
                    $c[$k] = $v;
                }
            }
        } else {
            $v = $num($d['importe'] ?? '');
            if ($v === null) {
                $e['importe'] = 'No es un importe válido.';
            } else {
                $c['no_gravado'] = $v; // sin IVA discriminado: todo el importe (B, C, tique o gasto)
            }
        }
        $c['tributos'] = [];
        $c['percepciones'] = 0.0;
        foreach ((is_array($d['trib'] ?? null) ? $d['trib'] : []) as $t) {
            if (!is_array($t)) {
                continue;
            }
            $imp = $num($t['importe'] ?? '');
            $tipoT = (string) ($t['tipo'] ?? '');
            if ($imp === 0.0) {
                continue;
            }
            if ($imp === null || !isset(self::TRIBUTOS[$tipoT])) {
                $e['trib'] = 'Revisá las percepciones: cada una necesita el impuesto y un importe válido.';
                continue;
            }
            $det = mb_substr(trim((string) ($t['detalle'] ?? '')), 0, 60);
            $c['tributos'][] = ['tipo' => $tipoT, 'detalle' => $det, 'importe' => $imp];
            $c['percepciones'] += $imp;
        }
        foreach (['gravado', 'iva', 'percepciones'] as $k) {
            $c[$k] = Calculadora::r2($c[$k]);
        }
        $c['total'] = Calculadora::r2($c['gravado'] + $c['iva'] + $c['no_gravado'] + $c['exento'] + $c['percepciones']);
        if ($c['total'] < 0.01 && !isset($e['importe']) && !isset($e['neto'])) {
            $e[$discrimina ? 'neto' : 'importe'] = 'Cargá los importes del comprobante.';
        }
        $impreso = trim((string) ($d['total_impreso'] ?? ''));
        if ($impreso !== '') {
            $ti = Valida::decimal($impreso);
            if ($ti === null || abs(Calculadora::r2($ti) - $c['total']) > 0.011) {
                $e['total_impreso'] = 'El total calculado (' . plata_dec($c['total']) . ') no coincide con el total impreso. Revisá los importes.';
            }
        }
        $c['costo'] = Calculadora::r2($c['total'] - $c['percepciones'] - ($c['computa_iva'] ? $c['iva'] : 0.0));

        // ------- reparto
        $cs = new CentrosService();
        $centros = $cs->centros(true);
        $conceptos = $cs->conceptos(true);
        $rep = [];
        foreach ((is_array($d['rep'] ?? null) ? $d['rep'] : []) as $r) {
            if (!is_array($r) || (($r['centro'] ?? '') === '' && trim((string) ($r['porcentaje'] ?? '')) === '')) {
                continue;
            }
            $rep[] = ['centro' => (int) ($r['centro'] ?? 0), 'concepto' => (int) ($r['concepto'] ?? 0), 'porcentaje' => Valida::decimal((string) ($r['porcentaje'] ?? ''))];
        }
        if ($rep === []) { // un solo centro (lo que propone el proveedor o General)
            $rep[] = ['centro' => (int) ($d['centro_id'] ?? 0) ?: (int) ($prov['centro_id'] ?? 0) ?: $cs->general(),
                'concepto' => (int) ($d['concepto_id'] ?? 0) ?: (int) ($prov['concepto_id'] ?? 0) ?: $cs->conceptoPorOmision(), 'porcentaje' => 100.0];
        }
        $suma = 0.0;
        foreach ($rep as $i => $r) {
            if (!isset($centros[$r['centro']])) {
                $e['rep'] = 'Elegí un centro de costos activo en cada renglón del reparto.';
            } elseif (!isset($conceptos[$r['concepto']])) {
                $e['rep'] = 'Elegí un concepto activo en cada renglón del reparto.';
            } elseif ($r['porcentaje'] === null || $r['porcentaje'] <= 0 || $r['porcentaje'] > 100) {
                $e['rep'] = 'Cada porcentaje del reparto tiene que ser mayor que 0 y hasta 100.';
            }
            $suma += (float) $r['porcentaje'];
        }
        if (!isset($e['rep']) && abs($suma - 100) > 0.005) {
            $e['rep'] = 'El reparto suma ' . number_format($suma, 2, ',', '.') . ' %: tiene que sumar 100 %.';
        }
        $c['reparto'] = [];
        if (!isset($e['rep'])) {
            $resto = $c['costo'];
            $n = count($rep);
            foreach ($rep as $i => $r) {
                $imp = $i === $n - 1 ? Calculadora::r2($resto) : Calculadora::r2($c['costo'] * (float) $r['porcentaje'] / 100);
                $resto -= $imp;
                $c['reparto'][] = ['centro_id' => $r['centro'], 'concepto_id' => $r['concepto'], 'porcentaje' => round((float) $r['porcentaje'], 2), 'importe' => $imp,
                    'centro' => $centros[$r['centro']]['completo'], 'concepto' => $conceptos[$r['concepto']]['nombre']];
            }
        }

        // ------- forma de pago
        $c['forma'] = (string) ($d['forma'] ?? 'C');
        if (!isset(self::FORMAS[$c['forma']])) {
            $e['forma'] = 'Elegí cómo se paga.';
        }
        $c['vence'] = null;
        $c['detalle_pago'] = mb_substr(trim((string) ($d['detalle_pago'] ?? '')), 0, 120);
        if ($c['forma'] === 'C') {
            $v = trim((string) ($d['vence'] ?? ''));
            $c['vence'] = $v === '' ? ($c['fecha'] !== '' ? date('Y-m-d', strtotime($c['fecha'] . ' +' . (int) ($prov['plazo_dias'] ?? 0) . ' days')) : null) : Valida::fecha($v);
            if ($v !== '' && $c['vence'] === null) {
                $e['vence'] = 'La fecha de vencimiento no es válida.';
            } elseif ($c['vence'] !== null && $c['fecha'] !== '' && $c['vence'] < $c['fecha']) {
                $e['vence'] = 'El vencimiento no puede ser anterior a la fecha del comprobante.';
            }
        }
        $c['a_revisar'] = ($d['a_revisar'] ?? '') === '1' ? 1 : 0;

        // ------- artículos (DECISIÓN D-118)
        $c['items'] = [];
        $c['deposito_id'] = null;
        $c['actualizar_costo'] = ($d['actualizar_costo'] ?? '') === '1';
        if (($d['con_articulos'] ?? '') === '1') {
            $r = $this->armarItems($d, $c);
            if (isset($r['error'])) {
                $e['items'] = $r['error'];
            } else {
                $c['items'] = $r['items'];
                $c['deposito_id'] = $r['deposito'];
            }
        }

        // ------- repetido (se vuelve a controlar al grabar, con el proveedor bloqueado)
        if ($e === [] && !$gs && $this->repetida($c) !== null) {
            $e['numero'] = 'Ese comprobante de este proveedor ya está cargado (' . self::titulo($c) . ').';
        }
        $c['proveedor'] = $prov;
        return ['ok' => $e === [], 'errores' => $e, 'compra' => $c];
    }

    /**
     * Renglones con artículo: cantidad y costo unitario (sin IVA si el comprobante discrimina, con IVA si no). No tienen que sumar todo el comprobante
     * (puede haber flete u otros gastos), pero no pueden pasarse de lo que se compró.
     * @return array{items?:array<int,array<string,mixed>>,deposito?:?int,error?:string}
     */
    private function armarItems(array $d, array $c): array
    {
        $items = [];
        $buscar = $this->pdo->prepare("SELECT codigo, descripcion, costo, moneda, controla_stock FROM articulos WHERE codigo = ? AND estado = 'AC'");
        foreach ((is_array($d['items'] ?? null) ? $d['items'] : []) as $it) {
            if (!is_array($it)) {
                continue;
            }
            $cod = mb_strtoupper(trim((string) ($it['articulo'] ?? '')));
            $cantTxt = trim((string) ($it['cantidad'] ?? ''));
            $costoTxt = trim((string) ($it['costo'] ?? ''));
            if ($cod === '' && $cantTxt === '' && $costoTxt === '') {
                continue;
            }
            $buscar->execute([$cod]);
            $a = $buscar->fetch();
            if (!$a) {
                return ['error' => 'El artículo «' . $cod . '» no existe o está dado de baja: elegilo de la lista.'];
            }
            $cant = Valida::decimal($cantTxt);
            $costo = Valida::decimal($costoTxt);
            if ($cant === null || $cant <= 0 || $cant > 9999999) {
                return ['error' => 'La cantidad de ' . $a['codigo'] . ' tiene que ser mayor que cero.'];
            }
            if ($costo === null || $costo < 0) {
                return ['error' => 'El costo unitario de ' . $a['codigo'] . ' no es válido.'];
            }
            $items[] = ['articulo' => (string) $a['codigo'], 'descripcion' => mb_substr((string) $a['descripcion'], 0, 120), 'cantidad' => round($cant, 3),
                'costo_unitario' => round($costo, 4), 'total' => Calculadora::r2($cant * $costo), 'moneda' => (string) $a['moneda'],
                'controla_stock' => (int) $a['controla_stock'], 'costo_actual' => (float) $a['costo']];
        }
        if ($items === []) {
            return ['error' => 'Cargá al menos un artículo (o destildá «Con artículos»).'];
        }
        $base = Calculadora::r2($c['gravado'] + $c['no_gravado'] + $c['exento']);
        $suma = Calculadora::r2(array_sum(array_column($items, 'total')));
        if ($suma - $base > 1.0) {
            return ['error' => 'Los artículos suman ' . plata_dec($suma) . ' y el comprobante es de ' . plata_dec($base) . ($c['computa_iva'] || self::discrimina($c['tipo'], $c['letra']) ? ' sin IVA' : '')
                . '. Revisá cantidades y costos (el costo unitario va ' . (self::discrimina($c['tipo'], $c['letra']) ? 'sin IVA' : 'con IVA') . ').'];
        }
        $stock = new \App\Modules\Stock\StockService($this->pdo);
        $dep = (int) ($d['deposito'] ?? 0);
        $deps = array_column($stock->depositos(), null, 'id');
        if (!isset($deps[$dep])) {
            $dep = $stock->depositoPrincipal();
        }
        return ['items' => $items, 'deposito' => $dep ?: null];
    }

    private static function pct(float $a): string
    {
        return rtrim(rtrim(number_format($a, 2, ',', ''), '0'), ',') . ' %';
    }

    /** Id de la compra vigente con el mismo proveedor, tipo, letra y número, o null. */
    private function repetida(array $c): ?int
    {
        $st = $this->pdo->prepare("SELECT id FROM compras WHERE proveedor_id = ? AND tipo = ? AND letra = ? AND punto_venta = ? AND numero = ? AND estado = 'V' LIMIT 1");
        $st->execute([$c['proveedor_id'], $c['tipo'], $c['letra'], $c['punto_venta'], $c['numero']]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    // ---------------------------------------------------------------- grabar

    /**
     * Graba la compra armada, en una transacción. Con la misma clave de operación devuelve la compra ya grabada (doble clic, F5).
     *
     * @param array<string,mixed> $c lo que devolvió armar()
     * @param array<int,array{name:string,type:string,tmp_name:string,error:int,size:int}> $archivos adjuntos ya normalizados
     * @return array{ok:bool,error?:string,id?:int,repetida?:bool}
     */
    public function grabar(array $c, int $usuarioId, ?string $operacion, array $archivos = []): array
    {
        $operacion = $operacion !== null && preg_match('/^[a-f0-9]{32}$/', $operacion) === 1 ? $operacion : null;
        if ($operacion !== null) {
            $st = $this->pdo->prepare('SELECT id FROM compras WHERE operacion = ?');
            $st->execute([$operacion]);
            $ya = $st->fetchColumn();
            if ($ya !== false) {
                return ['ok' => true, 'id' => (int) $ya, 'repetida' => true];
            }
        }
        $adj = [];
        foreach ($archivos as $f) {
            $r = self::leerAdjunto($f);
            if (isset($r['error'])) {
                return ['ok' => false, 'error' => $r['error']];
            }
            if ($r !== []) {
                $adj[] = $r;
            }
        }
        if (count($adj) > 5) {
            return ['ok' => false, 'error' => 'Se pueden adjuntar hasta 5 archivos por compra.'];
        }
        $turno = null;
        $usaCaja = $c['forma'] === 'E' && Modulos::activo('caja');
        if ($usaCaja) {
            $turno = (new CajaService())->turnoAbierto($usuarioId);
            if ($turno === null) {
                return ['ok' => false, 'error' => 'Para pagar en efectivo abrí tu turno de caja (Caja › Mi caja), o elegí otro medio o cuenta corriente.'];
            }
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('SELECT id FROM proveedores WHERE id = ? FOR UPDATE')->execute([$c['proveedor_id']]);
            if ($c['tipo'] !== 'GS' && $this->repetida($c) !== null) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'Ese comprobante de este proveedor ya está cargado (' . self::titulo($c) . ').'];
            }
            $saldo = $c['forma'] === 'C' ? $c['total'] : 0.0;
            $this->pdo->prepare('INSERT INTO compras (proveedor_id, tipo, letra, punto_venta, numero, fecha, periodo_iva, gravado, no_gravado, exento, iva, percepciones, total, costo,
                    computa_iva, forma, vence, saldo, cae, observaciones, a_revisar, usuario_id, creado_en, operacion) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?)')
                ->execute([$c['proveedor_id'], $c['tipo'], $c['letra'], max(0, $c['punto_venta']), max(0, $c['numero']), $c['fecha'], $c['periodo_iva'], $c['gravado'],
                    $c['no_gravado'], $c['exento'], $c['iva'], $c['percepciones'], $c['total'], $c['costo'], $c['computa_iva'], $c['forma'], $c['vence'], $saldo, $c['cae'],
                    $c['observaciones'], $c['a_revisar'], $usuarioId ?: null, $operacion]);
            $id = (int) $this->pdo->lastInsertId();
            $ins = $this->pdo->prepare('INSERT INTO compras_iva (compra_id, alicuota, neto, iva) VALUES (?, ?, ?, ?)');
            foreach ($c['alicuotas'] as $a) {
                $ins->execute([$id, $a['alicuota'], $a['neto'], $a['iva']]);
            }
            $ins = $this->pdo->prepare('INSERT INTO compras_tributos (compra_id, tipo, detalle, importe) VALUES (?, ?, ?, ?)');
            foreach ($c['tributos'] as $t) {
                $ins->execute([$id, $t['tipo'], $t['detalle'], $t['importe']]);
            }
            $ins = $this->pdo->prepare('INSERT INTO compras_reparto (compra_id, centro_id, concepto_id, porcentaje, importe) VALUES (?, ?, ?, ?, ?)');
            foreach ($c['reparto'] as $r) {
                $ins->execute([$id, $r['centro_id'], $r['concepto_id'], $r['porcentaje'], $r['importe']]);
            }
            $ins = $this->pdo->prepare('INSERT INTO compras_adjuntos (compra_id, nombre, mime, tamano, contenido, creado_en) VALUES (?, ?, ?, ?, ?, NOW())');
            foreach ($adj as $a) {
                $ins->bindValue(1, $id, PDO::PARAM_INT);
                $ins->bindValue(2, $a['nombre']);
                $ins->bindValue(3, $a['mime']);
                $ins->bindValue(4, strlen($a['contenido']), PDO::PARAM_INT);
                $ins->bindValue(5, $a['contenido'], PDO::PARAM_LOB);
                $ins->execute();
            }
            if (($c['items'] ?? []) !== []) {
                $this->grabarItems($id, $c, $usuarioId);
            }
            if ($c['forma'] !== 'C') {
                $this->registrarPago($id, $c, $c['forma'] === 'E' ? 'E' : 'O', $c['total'], $c['detalle_pago'], $usuarioId, $turno, $c['fecha']);
            }
            $this->pdo->commit();
            return ['ok' => true, 'id' => $id];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Dentro de la transacción: renglones, stock (la nota de crédito devuelve) y costo del artículo.
     * DECISIÓN D-118: el costo del artículo se reemplaza por el costo unitario de la compra (sin IVA si se computa el crédito; con IVA si no) solo si el
     * artículo está en pesos, la compra no es una nota de crédito y se tildó «Actualizar el costo». No cambia los precios de venta. El stock se mueve
     * solo con el control de stock encendido y en los artículos que lo controlan.
     */
    private function grabarItems(int $compraId, array $c, int $usuarioId): void
    {
        $stock = new \App\Modules\Stock\StockService($this->pdo);
        $mueve = \App\Modules\Stock\StockService::activo() && $c['deposito_id'] !== null;
        $nc = $c['tipo'] === 'NC';
        $titulo = self::titulo($c) . ' · ' . ($c['proveedor']['razon_social'] ?? '');
        $ins = $this->pdo->prepare('INSERT INTO compras_items (compra_id, articulo, descripcion, cantidad, costo_unitario, total, deposito_id, costo_anterior, stock_mov_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $items = $c['items'];
        usort($items, static fn ($a, $b) => strcmp($a['articulo'], $b['articulo'])); // mismo orden de bloqueo que las ventas
        foreach ($items as $it) {
            $mov = null;
            if ($mueve && $it['controla_stock'] === 1) {
                $mov = $stock->mover($it['articulo'], (int) $c['deposito_id'], ($nc ? -1 : 1) * $it['cantidad'], $nc ? 'DEVCOMPRA' : 'COMPRA', null, $usuarioId ?: null, mb_substr($titulo, 0, 255), 'compra:' . $compraId);
            }
            $anterior = null;
            if ($c['actualizar_costo'] && !$nc && $it['moneda'] === '$') {
                $q = $this->pdo->prepare('SELECT costo FROM articulos WHERE codigo = ? FOR UPDATE');
                $q->execute([$it['articulo']]);
                $anterior = (float) $q->fetchColumn();
                $this->pdo->prepare('UPDATE articulos SET costo = ? WHERE codigo = ?')->execute([$it['costo_unitario'], $it['articulo']]);
            }
            $ins->execute([$compraId, $it['articulo'], $it['descripcion'], $it['cantidad'], $it['costo_unitario'], $it['total'], $c['deposito_id'], $anterior, $mov]);
        }
    }

    /** Pago dentro de la transacción: el efectivo mueve la caja (egreso; ingreso si es una nota de crédito). */
    private function registrarPago(int $compraId, array $c, string $medio, float $importe, string $detalle, int $usuarioId, ?array $turno, string $fecha): void
    {
        $mov = null;
        if ($medio === 'E' && $turno !== null) {
            $nc = $c['tipo'] === 'NC';
            $motivo = mb_substr(($nc ? 'Devolución de ' : 'Pago de ') . self::titulo($c) . ' · ' . ($c['proveedor']['razon_social'] ?? ''), 0, 200);
            $this->pdo->prepare('INSERT INTO caja_movimientos (creado_en, usuario_id, turno_id, tipo, importe, motivo) VALUES (NOW(), ?, ?, ?, ?, ?)')
                ->execute([$usuarioId, $turno['id'], $nc ? 'I' : 'E', $importe, $motivo]);
            $mov = (int) $this->pdo->lastInsertId();
            (new CajaService())->auditar($nc ? 'ingreso' : 'egreso', 'movimiento', $mov, plata_dec($importe) . ' · ' . $motivo, $usuarioId);
        }
        $this->pdo->prepare('INSERT INTO compras_pagos (compra_id, fecha, medio, importe, detalle, caja_movimiento_id, usuario_id, creado_en) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$compraId, $fecha, $medio, $importe, $detalle, $mov, $usuarioId ?: null]);
    }

    /**
     * Normaliza $_FILES['adjuntos'] (uno o varios) a una lista de archivos.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function archivosSubidos(mixed $f): array
    {
        if (!is_array($f) || !isset($f['name'])) {
            return [];
        }
        if (!is_array($f['name'])) {
            return [$f];
        }
        $out = [];
        foreach (array_keys($f['name']) as $i) {
            $out[] = ['name' => $f['name'][$i], 'type' => $f['type'][$i] ?? '', 'tmp_name' => $f['tmp_name'][$i] ?? '', 'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $f['size'][$i] ?? 0];
        }
        return $out;
    }

    /** @return array{nombre?:string,mime?:string,contenido?:string,error?:string} vacío si no se subió nada */
    private static function leerAdjunto(array $f): array
    {
        $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            return [];
        }
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || (int) ($f['size'] ?? 0) > self::ADJUNTO_MAX) {
            return ['error' => 'Cada adjunto puede pesar hasta 5 MB.'];
        }
        $tmp = (string) ($f['tmp_name'] ?? '');
        if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp)) {
            return ['error' => 'No se pudo recibir el adjunto. Probá de nuevo.'];
        }
        $bin = (string) file_get_contents($tmp);
        if (strlen($bin) > self::ADJUNTO_MAX) {
            return ['error' => 'Cada adjunto puede pesar hasta 5 MB.'];
        }
        $mime = self::mimeDe($bin);
        if ($mime === null) {
            return ['error' => 'Los adjuntos pueden ser fotos (JPG, PNG, WebP) o PDF.'];
        }
        $nombre = preg_replace('/[^\w.\- áéíóúñÁÉÍÓÚÑ]/u', '_', basename((string) ($f['name'] ?? 'adjunto'))) ?? 'adjunto';
        return ['nombre' => mb_substr($nombre !== '' ? $nombre : 'adjunto', 0, 120), 'mime' => $mime, 'contenido' => $bin];
    }

    /** Tipo por los primeros bytes (no se confía en lo que dice el navegador). */
    public static function mimeDe(string $bin): ?string
    {
        return match (true) {
            str_starts_with($bin, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bin, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($bin, 'RIFF') && substr($bin, 8, 4) === 'WEBP' => 'image/webp',
            str_starts_with($bin, '%PDF-') => 'application/pdf',
            default => null,
        };
    }

    // ---------------------------------------------------------------- consultar

    /** @return array<string,mixed>|null la compra con proveedor, alícuotas, percepciones, reparto, adjuntos y pagos */
    public function obtener(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT c.*, p.razon_social, p.cuit, p.codigo AS proveedor_codigo, u.nombre AS usuario, ua.nombre AS anulo
            FROM compras c JOIN proveedores p ON p.id = c.proveedor_id LEFT JOIN usuarios u ON u.id = c.usuario_id LEFT JOIN usuarios ua ON ua.id = c.anulada_por WHERE c.id = ?');
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) {
            return null;
        }
        $q = function (string $sql) use ($id): array {
            $s = $this->pdo->prepare($sql);
            $s->execute([$id]);
            return $s->fetchAll();
        };
        $c['alicuotas'] = $q('SELECT alicuota, neto, iva FROM compras_iva WHERE compra_id = ? ORDER BY alicuota DESC');
        $c['tributos'] = $q('SELECT tipo, detalle, importe FROM compras_tributos WHERE compra_id = ? ORDER BY id');
        $c['reparto'] = $q('SELECT r.*, cc.nombre AS centro, pc.nombre AS centro_padre, cg.nombre AS concepto FROM compras_reparto r JOIN centros_costo cc ON cc.id = r.centro_id
            LEFT JOIN centros_costo pc ON pc.id = cc.padre_id JOIN conceptos_gasto cg ON cg.id = r.concepto_id WHERE r.compra_id = ? ORDER BY r.id');
        $c['adjuntos'] = $q('SELECT id, nombre, mime, tamano, creado_en FROM compras_adjuntos WHERE compra_id = ? ORDER BY id');
        $c['pagos'] = $q('SELECT pg.*, u.nombre AS usuario FROM compras_pagos pg LEFT JOIN usuarios u ON u.id = pg.usuario_id WHERE pg.compra_id = ? ORDER BY pg.id');
        $c['items'] = $q('SELECT i.*, d.nombre AS deposito FROM compras_items i LEFT JOIN depositos d ON d.id = i.deposito_id WHERE i.compra_id = ? ORDER BY i.id');
        $c['ordenes'] = (new OrdenesPagoService($this->pdo))->deCompra($id);
        return $c;
    }

    /** @return array{nombre:string,mime:string,contenido:string}|null */
    public function adjunto(int $compraId, int $adjuntoId): ?array
    {
        $st = $this->pdo->prepare('SELECT nombre, mime, contenido FROM compras_adjuntos WHERE id = ? AND compra_id = ?');
        $st->execute([$adjuntoId, $compraId]);
        return $st->fetch() ?: null;
    }

    public const ORDEN = ['fecha' => 'c.fecha', 'comprobante' => 'c.tipo, c.letra, c.punto_venta, c.numero', 'proveedor' => 'p.razon_social', 'total' => 'c.total',
        'costo' => 'c.costo', 'vence' => 'c.vence IS NULL, c.vence', 'saldo' => 'c.saldo'];

    /** @return array{0:string,1:array<int,mixed>} */
    private function filtro(array $f): array
    {
        $w = ['1=1'];
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
            $w[] = 'EXISTS (SELECT 1 FROM compras_reparto r JOIN centros_costo cc ON cc.id = r.centro_id WHERE r.compra_id = c.id AND (cc.id = ? OR cc.padre_id = ?))';
            $p[] = (int) $f['centro'];
            $p[] = (int) $f['centro'];
        }
        if ((int) ($f['concepto'] ?? 0) > 0) {
            $w[] = 'EXISTS (SELECT 1 FROM compras_reparto r WHERE r.compra_id = c.id AND r.concepto_id = ?)';
            $p[] = (int) $f['concepto'];
        }
        if (isset(self::TIPOS[(string) ($f['tipo'] ?? '')])) {
            $w[] = 'c.tipo = ?';
            $p[] = (string) $f['tipo'];
        }
        if (isset(self::FORMAS[(string) ($f['forma'] ?? '')])) {
            $w[] = 'c.forma = ?';
            $p[] = (string) $f['forma'];
        }
        $estado = (string) ($f['estado'] ?? '');
        if ($estado === 'V' || $estado === 'A') {
            $w[] = 'c.estado = ?';
            $p[] = $estado;
        } elseif ($estado === 'impagas') {
            $w[] = "c.estado = 'V' AND c.forma = 'C' AND c.saldo > 0.005";
        } elseif ($estado === 'revisar') {
            $w[] = "c.estado = 'V' AND c.a_revisar = 1";
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $w[] = '(p.razon_social LIKE ? OR c.observaciones LIKE ? OR CAST(c.numero AS CHAR) = ?)';
            array_push($p, '%' . $q . '%', '%' . $q . '%', ltrim($q, '0'));
        }
        return [implode(' AND ', $w), $p];
    }

    /** @return array{filas:array<int,array<string,mixed>>,total:int,totales:array<string,float>} */
    public function listar(array $f, int $pagina, int $porPagina): array
    {
        [$where, $params] = $this->filtro($f);
        $base = "FROM compras c JOIN proveedores p ON p.id = c.proveedor_id WHERE {$where}";
        $st = $this->pdo->prepare("SELECT COUNT(*), COALESCE(SUM(IF(c.estado = 'V', IF(c.tipo = 'NC', -1, 1) * c.total, 0)), 0),
                COALESCE(SUM(IF(c.estado = 'V', IF(c.tipo = 'NC', -1, 1) * c.costo, 0)), 0), COALESCE(SUM(IF(c.estado = 'V', IF(c.tipo = 'NC', -1, 1) * c.saldo, 0)), 0) {$base}");
        $st->execute($params);
        [$total, $tt, $tc, $ts] = $st->fetch(PDO::FETCH_NUM);
        $orden = Orden::sql(self::ORDEN, (string) ($f['orden'] ?? ''), 'c.fecha DESC, c.id DESC');
        $st = $this->pdo->prepare("SELECT c.*, p.razon_social, p.cuit,
                (SELECT GROUP_CONCAT(DISTINCT cc.nombre ORDER BY cc.nombre SEPARATOR ', ') FROM compras_reparto r JOIN centros_costo cc ON cc.id = r.centro_id WHERE r.compra_id = c.id) AS centros,
                (SELECT COUNT(*) FROM compras_adjuntos a WHERE a.compra_id = c.id) AS adjuntos
            {$base} ORDER BY {$orden}" . ExcelListado::limite($f + ['pagina' => $pagina], $porPagina));
        $st->execute($params);
        return ['filas' => $st->fetchAll(), 'total' => (int) $total, 'totales' => ['total' => (float) $tt, 'costo' => (float) $tc, 'saldo' => (float) $ts]];
    }

    // ---------------------------------------------------------------- pagar y anular

    /**
     * Paga (todo o parte) una compra a cuenta corriente. En la entrega B esto pasa a las órdenes de pago.
     *
     * @return array{ok:bool,error?:string}
     */
    public function pagar(int $compraId, int $usuarioId, string $medio, string $importeTxt, string $detalle, string $fechaTxt = ''): array
    {
        if (!isset(self::MEDIOS[$medio])) {
            return ['ok' => false, 'error' => 'Elegí con qué se paga.'];
        }
        $fecha = trim($fechaTxt) === '' ? date('Y-m-d') : Valida::fecha($fechaTxt);
        if ($fecha === null || $fecha > date('Y-m-d')) {
            return ['ok' => false, 'error' => 'La fecha del pago no es válida.'];
        }
        $detalle = mb_substr(trim($detalle), 0, 120);
        $turno = null;
        if ($medio === 'E' && Modulos::activo('caja')) {
            $turno = (new CajaService())->turnoAbierto($usuarioId);
            if ($turno === null) {
                return ['ok' => false, 'error' => 'Para pagar en efectivo abrí tu turno de caja (Caja › Mi caja).'];
            }
        }
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare('SELECT c.*, p.razon_social FROM compras c JOIN proveedores p ON p.id = c.proveedor_id WHERE c.id = ? FOR UPDATE');
            $st->execute([$compraId]);
            $c = $st->fetch();
            $error = match (true) {
                !$c => 'No existe esa compra.',
                $c['estado'] !== 'V' => 'La compra está anulada.',
                $c['forma'] !== 'C' => 'Esa compra no es a cuenta corriente: ya está pagada.',
                $c['tipo'] === 'NC' => 'Una nota de crédito no se paga: se descuenta al pagarle al proveedor (órdenes de pago, próxima entrega).',
                (float) $c['saldo'] < 0.005 => 'Esa compra ya está pagada.',
                default => null,
            };
            if ($error !== null) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => $error];
            }
            $imp = trim($importeTxt) === '' ? (float) $c['saldo'] : Valida::decimal($importeTxt);
            if ($imp === null || $imp <= 0 || Calculadora::r2($imp) - (float) $c['saldo'] > 0.005) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'El importe tiene que ser mayor que cero y no más que el saldo (' . plata_dec((float) $c['saldo']) . ').'];
            }
            $imp = Calculadora::r2($imp);
            $c['proveedor'] = ['razon_social' => $c['razon_social']];
            $this->registrarPago($compraId, $c, $medio, $imp, $detalle, $usuarioId, $turno, $fecha);
            $this->pdo->prepare('UPDATE compras SET saldo = saldo - ? WHERE id = ?')->execute([$imp, $compraId]);
            $this->pdo->commit();
            return ['ok' => true];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Anula una compra: queda en los listados como anulada, sin saldo, y sus pagos en efectivo vuelven a la caja con un contra-asiento.
     *
     * @return array{ok:bool,error?:string}
     */
    public function anular(int $compraId, int $usuarioId, string $motivo): array
    {
        $motivo = trim($motivo);
        if ($motivo === '' || mb_strlen($motivo) > 160) {
            return ['ok' => false, 'error' => 'Escribí el motivo de la anulación (hasta 160 letras).'];
        }
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare('SELECT * FROM compras WHERE id = ? FOR UPDATE');
            $st->execute([$compraId]);
            $c = $st->fetch();
            if (!$c || $c['estado'] !== 'V') {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => $c ? 'La compra ya está anulada.' : 'No existe esa compra.'];
            }
            $op = $this->pdo->prepare("SELECT o.numero FROM ordenes_pago_imputaciones i JOIN ordenes_pago o ON o.id = i.orden_id WHERE i.compra_id = ? AND o.estado = 'V' LIMIT 1");
            $op->execute([$compraId]);
            if (($nOp = $op->fetchColumn()) !== false) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'La compra está en la ' . OrdenesPagoService::numeroVisible((int) $nOp) . ': anulá primero la orden de pago.'];
            }
            $pg = $this->pdo->prepare('SELECT * FROM compras_pagos WHERE compra_id = ? AND anulado = 0 AND caja_movimiento_id IS NOT NULL');
            $pg->execute([$compraId]);
            $enCaja = $pg->fetchAll();
            $turno = null;
            if ($enCaja !== []) {
                $turno = (new CajaService())->turnoAbierto($usuarioId);
                if ($turno === null) {
                    $this->pdo->rollBack();
                    return ['ok' => false, 'error' => 'La compra se pagó con efectivo de la caja: abrí tu turno para que la anulación devuelva el dinero.'];
                }
            }
            $caja = new CajaService();
            foreach ($enCaja as $p) {
                $m = $this->pdo->prepare('SELECT * FROM caja_movimientos WHERE id = ?');
                $m->execute([$p['caja_movimiento_id']]);
                $mov = $m->fetch();
                if (!$mov) {
                    continue;
                }
                $this->pdo->prepare('INSERT INTO caja_movimientos (creado_en, usuario_id, turno_id, tipo, importe, motivo, anula_id) VALUES (NOW(), ?, ?, ?, ?, ?, ?)')
                    ->execute([$usuarioId, $turno['id'], $mov['tipo'] === 'I' ? 'E' : 'I', $mov['importe'], mb_substr('Anulación de compra ' . self::titulo($c) . ': ' . $motivo, 0, 200), $mov['id']]);
                $caja->auditar('anulacion', 'movimiento', (int) $mov['id'], 'Anulación de compra ' . $compraId . ' · ' . $motivo, $usuarioId);
            }
            $this->pdo->prepare('UPDATE compras_pagos SET anulado = 1 WHERE compra_id = ?')->execute([$compraId]);
            // el stock que movió vuelve (el costo del artículo no se toca: puede haber cambiado después)
            $it = $this->pdo->prepare('SELECT i.articulo, i.cantidad, i.deposito_id FROM compras_items i WHERE i.compra_id = ? AND i.stock_mov_id IS NOT NULL ORDER BY i.articulo');
            $it->execute([$compraId]);
            $stock = new \App\Modules\Stock\StockService($this->pdo);
            foreach ($it->fetchAll() as $i) {
                $stock->mover((string) $i['articulo'], (int) $i['deposito_id'], ($c['tipo'] === 'NC' ? 1 : -1) * (float) $i['cantidad'], 'ANUCOMPRA', null, $usuarioId ?: null,
                    mb_substr('Anulación de ' . self::titulo($c) . ': ' . $motivo, 0, 255), 'compra:' . $compraId);
            }
            $this->pdo->prepare("UPDATE compras SET estado = 'A', saldo = 0, anulada_en = NOW(), anulada_por = ?, motivo_anulacion = ? WHERE id = ?")
                ->execute([$usuarioId ?: null, $motivo, $compraId]);
            $this->pdo->commit();
            return ['ok' => true];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Marca una compra como revisada (la que entró «a revisar»). */
    public function revisada(int $compraId): void
    {
        $this->pdo->prepare('UPDATE compras SET a_revisar = 0 WHERE id = ?')->execute([$compraId]);
    }

    /** ¿Ese movimiento de caja es el pago de una compra? (la caja no lo deja anular suelto) */
    public static function esPagoDeCompra(int $movimientoId): bool
    {
        $st = Db::pdo()->prepare('SELECT COUNT(*) FROM compras_pagos WHERE caja_movimiento_id = ?');
        $st->execute([$movimientoId]);
        return (int) $st->fetchColumn() > 0;
    }
}
