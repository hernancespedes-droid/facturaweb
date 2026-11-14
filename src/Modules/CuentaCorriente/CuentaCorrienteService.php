<?php
declare(strict_types=1);

namespace App\Modules\CuentaCorriente;

use App\Core\Db;
use App\Core\Valida;
use App\Modules\Comprobantes\Tipos;
use PDO;

/**
 * Cuenta corriente de clientes y recibos (etapa 4).
 *
 * ctact tiene una fila por comprobante a cuenta; `saldo` es lo que falta cancelar. Débitos (tico < 7): factura 1, nota de débito 2, ajuste de
 * débito 3. Créditos (tico >= 7): recibo 7, nota de crédito 8, ajuste de crédito 9. Un recibo cancela débitos (imputaciones) y lo que sobra
 * queda como saldo a favor (anticipo) en su propia fila de ctact, que después se puede imputar a mano.
 *
 * Todo lo que escribe va en UNA transacción, con las filas de ctact bloqueadas (FOR UPDATE) y el saldo vuelto a verificar adentro, así dos
 * usuarios no cobran dos veces la misma factura. El número de recibo sale de sucursales.ultimo_recibo con la fila bloqueada.
 *
 * DECISIÓN D-28 (Claude, a confirmar por Hernán): el recibo lleva letra "X" (no fiscal); bruto = lo que se cancela + anticipo; neto = bruto − descuento
 *   por pronto pago − retenciones = lo que se cobra y tiene que coincidir con los medios de pago; en ctact descuento = bruto − neto.
 * DECISIÓN D-29: un recibo se puede anular mientras sus cheques sigan en cartera y su anticipo no se haya imputado; se deshacen las imputaciones,
 *   se vuelve la deuda a su saldo y se borran sus medios de pago. Queda el recibo marcado como anulado.
 * DECISIÓN D-30: las notas de crédito y anticipos se aplican con "Imputación manual" (antes o después del recibo); el recibo no mezcla notas de crédito.
 */
final class CuentaCorrienteService
{
    public const LETRA_RECIBO = 'X';
    private const CANAL = 'W';
    public const TIPOS = ['FC' => 'Factura', 'NC' => 'Nota de crédito', 'ND' => 'Nota de débito', 'RC' => 'Recibo', 'AD' => 'Ajuste de débito', 'AC' => 'Ajuste de crédito'];
    /** DECISIÓN D-31: nombres de las 5 retenciones del VFP (cobra.cod_rt1..5, sin tabla de nombres): a confirmar con OYS. */
    public const RETENCIONES = [1 => 'Ganancias', 2 => 'IVA', 3 => 'Ingresos Brutos', 4 => 'SUSS', 5 => 'Otras'];
    public const AJUSTES = ['AD' => ['Ajuste de débito (aumenta la deuda)', 3], 'AC' => ['Ajuste de crédito (la disminuye)', 9]];
    public const ESTADOS_CHEQUE = ['' => 'En cartera', 'D' => 'Depositado', 'E' => 'Entregado a un tercero', 'R' => 'Rechazado'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::pdo();
    }

    public static function redondear(float $v): float
    {
        return round($v + ($v >= 0 ? 1e-9 : -1e-9), 2);
    }

    // ------------------------------------------------------------------ consulta

    /** @return array<string,mixed>|null */
    public function cliente(int $codigo): ?array
    {
        $st = $this->pdo->prepare('SELECT codigo, razon_social, cuit, iva, activo, localidad FROM clientes WHERE codigo = ?');
        $st->execute([$codigo]);
        return $st->fetch() ?: null;
    }

    /** Número con el que se conoce el comprobante de una fila de ctact (el de ARCA si lo tiene). $c = alias de ctact, $s = alias de subdiario. */
    private static function sqlNumeroVisible(string $c = 'c', string $s = 's'): string
    {
        return "IF({$s}.fe_clave IS NULL, {$c}.numero, " . Tipos::sqlNumeroVisible($s) . ')';
    }

    /** Comprobantes con saldo de un cliente. @return array{debitos:array<int,array<string,mixed>>,creditos:array<int,array<string,mixed>>} */
    public function pendientes(int $cliente): array
    {
        $st = $this->pdo->prepare('SELECT c.fe_clave, c.tipo, c.tico, c.letra, c.prefijo, c.numero, ' . self::sqlNumeroVisible() . ' AS numero_visible, c.fecha, c.vencimiento, c.neto, c.descuento, c.saldo
            FROM ctact c LEFT JOIN subdiario s ON s.fe_clave = c.subdiario_id
            WHERE c.cliente = ? AND c.saldo > 0.004 ORDER BY c.fecha, c.fe_clave');
        $st->execute([$cliente]);
        $out = ['debitos' => [], 'creditos' => []];
        foreach ($st->fetchAll() as $f) {
            $out[(int) $f['tico'] < 7 ? 'debitos' : 'creditos'][] = $f;
        }
        return $out;
    }

    /** Saldo del cliente: lo que debe (positivo) o tiene a favor (negativo). */
    public function saldo(int $cliente): float
    {
        $st = $this->pdo->prepare('SELECT COALESCE(SUM(IF(tico < 7, saldo, -saldo)), 0) FROM ctact WHERE cliente = ? AND saldo > 0.004');
        $st->execute([$cliente]);
        return self::redondear((float) $st->fetchColumn());
    }

    /**
     * Movimientos de la cuenta corriente con saldo acumulado. Debe = débitos; Haber = créditos (recibo: bruto cancelado).
     * @return array{inicial:float,filas:array<int,array<string,mixed>>,debe:float,haber:float,final:float}
     */
    public function movimientos(int $cliente, ?string $desde, ?string $hasta): array
    {
        $inicial = 0.0;
        if ($desde !== null) {
            $st = $this->pdo->prepare('SELECT COALESCE(SUM(IF(tico < 7, neto + descuento, -(neto + descuento))), 0) FROM ctact WHERE cliente = ? AND fecha < ?');
            $st->execute([$cliente, $desde]);
            $inicial = self::redondear((float) $st->fetchColumn());
        }
        $sql = 'SELECT c.fe_clave, c.fecha, c.tipo, c.tico, c.letra, c.prefijo, c.numero, ' . self::sqlNumeroVisible() . ' AS numero_visible, c.neto, c.descuento, c.saldo, c.vencimiento, c.cobra_id, c.observaciones
            FROM ctact c LEFT JOIN subdiario s ON s.fe_clave = c.subdiario_id WHERE c.cliente = ?';
        $p = [$cliente];
        if ($desde !== null) {
            $sql .= ' AND c.fecha >= ?';
            $p[] = $desde;
        }
        if ($hasta !== null) {
            $sql .= ' AND c.fecha <= ?';
            $p[] = $hasta;
        }
        $st = $this->pdo->prepare($sql . ' ORDER BY c.fecha, c.fe_clave');
        $st->execute($p);
        $acum = $inicial;
        $debe = $haber = 0.0;
        $filas = [];
        foreach ($st->fetchAll() as $f) {
            $importe = (float) $f['neto'] + (float) $f['descuento'];
            if ((int) $f['tico'] < 7) {
                $f['debe'] = $importe;
                $f['haber'] = 0.0;
                $acum += $importe;
                $debe += $importe;
            } else {
                $f['debe'] = 0.0;
                $f['haber'] = $importe;
                $acum -= $importe;
                $haber += $importe;
            }
            $f['acumulado'] = self::redondear($acum);
            $filas[] = $f;
        }
        return ['inicial' => $inicial, 'filas' => $filas, 'debe' => self::redondear($debe), 'haber' => self::redondear($haber), 'final' => self::redondear($acum)];
    }

    /** Qué se canceló con una fila de ctact (o qué la canceló). @return array<int,array<string,mixed>> */
    public function imputacionesDe(int $ctactId): array
    {
        $st = $this->pdo->prepare('SELECT i.*, d.tipo AS tipo_db, d.letra AS letra_db, d.prefijo AS prefijo_db, d.numero AS numero_db, cr.tipo AS tipo_cr, cr.numero AS numero_cr, cr.prefijo AS prefijo_cr
            FROM imputaciones i LEFT JOIN ctact d ON d.fe_clave = i.ctact_db LEFT JOIN ctact cr ON cr.fe_clave = i.ctact_cr
            WHERE i.ctact_db = ? OR i.ctact_cr = ? ORDER BY i.id');
        $st->execute([$ctactId, $ctactId]);
        return $st->fetchAll();
    }

    /** @return array<int,array<string,mixed>> formas de pago que se pueden usar en un recibo (no cta. cte. ni nota de crédito) */
    public function formasDePago(): array
    {
        return $this->pdo->query('SELECT codigo, descripcion, tipo FROM forma_de_pagos WHERE tipo NOT IN (6, 8) ORDER BY descripcion')->fetchAll();
    }

    // ------------------------------------------------------------------ recibos

    /**
     * Valida y calcula un recibo sin grabar nada.
     * @param array<string,mixed> $in cliente, fecha, sucursal, imputar[ctact_id => importe], anticipo, descuento, ret[1..5], pagos[][fpago,importe,nro,banco,vto], observaciones
     * @return array{errores:array<string,string>,recibo:array<string,mixed>}
     */
    public function armarRecibo(array $in): array
    {
        $err = [];
        $cliente = $this->cliente((int) ($in['cliente'] ?? 0));
        if ($cliente === null) {
            $err['cliente'] = 'Elegí el cliente.';
        }
        $hoy = date('Y-m-d');
        $fecha = Valida::fecha((string) ($in['fecha'] ?? '')) ?? null;
        if ($fecha === null) {
            $err['fecha'] = 'La fecha no es válida.';
        } elseif ($fecha > $hoy) {
            $err['fecha'] = 'La fecha del recibo no puede ser futura.';
        }
        $sucs = (new \App\Modules\Comprobantes\ComprobantesService($this->pdo))->sucursales();
        $codSuc = (int) ($in['sucursal'] ?? (count($sucs) === 1 ? array_key_first($sucs) : 0));
        if (!isset($sucs[$codSuc])) {
            $err['sucursal'] = 'Elegí la sucursal (punto de venta).';
        }

        // Deuda que se cancela: cada renglón contra su saldo actual
        $imputar = [];
        $totalImput = 0.0;
        $pend = $cliente !== null ? array_column($this->pendientes((int) $cliente['codigo'])['debitos'], null, 'fe_clave') : [];
        foreach ((array) ($in['imputar'] ?? []) as $id => $txt) {
            $txt = trim((string) $txt);
            if ($txt === '') {
                continue;
            }
            $imp = Valida::decimal($txt);
            if ($imp === null || $imp < 0) {
                $err['imputar'] = 'Un importe a cancelar no es válido.';
                continue;
            }
            $imp = self::redondear($imp);
            if ($imp <= 0) {
                continue;
            }
            if (!isset($pend[(int) $id])) {
                $err['imputar'] = 'Un comprobante ya no tiene saldo o no es de este cliente. Actualizá la pantalla.';
                continue;
            }
            if ($imp > (float) $pend[(int) $id]['saldo'] + 0.004) {
                $err['imputar'] = 'No se puede cancelar más que el saldo de ' . self::TIPOS[$pend[(int) $id]['tipo']] . ' ' . $pend[(int) $id]['numero_visible'] . '.';
                continue;
            }
            $imputar[(int) $id] = $imp;
            $totalImput += $imp;
        }
        $totalImput = self::redondear($totalImput);

        $anticipoTxt = trim((string) ($in['anticipo'] ?? ''));
        $anticipo = $anticipoTxt === '' ? 0.0 : Valida::decimal($anticipoTxt);
        if ($anticipo === null || $anticipo < 0) {
            $err['anticipo'] = 'El importe a cuenta no es válido.';
            $anticipo = 0.0;
        }
        $anticipo = self::redondear($anticipo);
        $bruto = self::redondear($totalImput + $anticipo);
        if ($bruto <= 0 && !isset($err['imputar'])) {
            $err['imputar'] = 'Indicá cuánto cobrás: tildá comprobantes o cargá un importe a cuenta.';
        }

        $dtoTxt = trim((string) ($in['descuento'] ?? ''));
        $dto = $dtoTxt === '' ? 0.0 : Valida::decimal($dtoTxt);
        if ($dto === null || $dto < 0) {
            $err['descuento'] = 'El descuento no es válido.';
            $dto = 0.0;
        }
        $dto = self::redondear($dto);
        $ret = [];
        $totalRet = 0.0;
        foreach (self::RETENCIONES as $cod => $nombre) {
            $t = trim((string) (((array) ($in['ret'] ?? []))[$cod] ?? ''));
            $v = $t === '' ? 0.0 : Valida::decimal($t);
            if ($v === null || $v < 0) {
                $err['ret'] = "La retención «{$nombre}» no es válida.";
                $v = 0.0;
            }
            $ret[$cod] = self::redondear($v);
            $totalRet += $ret[$cod];
        }
        $totalRet = self::redondear($totalRet);
        $neto = self::redondear($bruto - $dto - $totalRet);
        if ($neto < 0) {
            $err['descuento'] = 'El descuento y las retenciones no pueden superar lo que se cancela.';
        }

        // Medios de pago
        $formas = array_column($this->formasDePago(), null, 'codigo');
        $pagos = [];
        $totalPagos = 0.0;
        foreach ((array) ($in['pagos'] ?? []) as $i => $p) {
            $p = (array) $p;
            $impTxt = trim((string) ($p['importe'] ?? ''));
            if ($impTxt === '' && trim((string) ($p['fpago'] ?? '')) === '') {
                continue;
            }
            $imp = Valida::decimal($impTxt);
            $fp = (string) ($p['fpago'] ?? '');
            if (!isset($formas[$fp])) {
                $err['pagos'] = 'Elegí la forma de pago de cada renglón.';
                continue;
            }
            if ($imp === null || $imp <= 0) {
                $err['pagos'] = 'El importe de cada medio de pago tiene que ser mayor que cero.';
                continue;
            }
            $linea = ['codigo' => $fp, 'descripcion' => $formas[$fp]['descripcion'], 'tipo' => (int) $formas[$fp]['tipo'], 'importe' => self::redondear($imp),
                'nro' => trim((string) ($p['nro'] ?? '')), 'banco' => (int) ($p['banco'] ?? 0), 'vencimiento' => null];
            if ($linea['tipo'] === 5) { // cheque: número, banco y fecha de cobro
                $vto = Valida::fecha((string) ($p['vto'] ?? ''));
                if ($linea['nro'] === '' || $linea['banco'] <= 0 || $vto === null) {
                    $err['pagos'] = 'Cada cheque necesita número, banco y fecha de cobro.';
                    continue;
                }
                $linea['vencimiento'] = $vto;
            }
            if (strlen($linea['nro']) > 20) {
                $err['pagos'] = 'El número de un medio de pago es demasiado largo (hasta 20).';
                continue;
            }
            $pagos[] = $linea;
            $totalPagos += $linea['importe'];
        }
        $totalPagos = self::redondear($totalPagos);
        if (!isset($err['pagos']) && !isset($err['descuento']) && !isset($err['imputar']) && abs($totalPagos - $neto) > 0.004) {
            $err['pagos'] = 'Los medios de pago (' . plata_dec($totalPagos) . ') tienen que sumar lo que se cobra (' . plata_dec($neto) . ').';
        }
        if ($neto > 0 && $pagos === [] && !isset($err['pagos'])) {
            $err['pagos'] = 'Cargá cómo te pagan.';
        }
        foreach ($pagos as $i => $p) { // el mismo cheque no se carga dos veces
            if ($p['tipo'] === 5) {
                foreach (array_slice($pagos, $i + 1) as $q) {
                    if ($q['tipo'] === 5 && $q['nro'] === $p['nro'] && $q['banco'] === $p['banco'] && !isset($err['pagos'])) {
                        $err['pagos'] = 'El cheque ' . $p['nro'] . ' está cargado dos veces.';
                    }
                }
            }
        }
        $obs = trim((string) ($in['observaciones'] ?? ''));
        if (mb_strlen($obs) > 255) {
            $err['observaciones'] = 'Las observaciones son demasiado largas (hasta 255).';
        }

        // Descuento por pronto pago: se separa el IVA como el VFP (cobra.dto_bruto / dto_iva) con la alícuota 1 de la sucursal
        $dtoIva = 0.0;
        if ($dto > 0 && isset($sucs[$codSuc]) && $cliente !== null && in_array((int) $cliente['iva'], [1, 3, 7], true) && (float) $sucs[$codSuc]['iva_1'] > 0) {
            $dtoIva = self::redondear($dto - $dto / (1 + (float) $sucs[$codSuc]['iva_1'] / 100));
        }
        return ['errores' => $err, 'recibo' => [
            'cliente' => $cliente, 'fecha' => $fecha, 'sucursal' => $codSuc, 'prefijo' => (int) ($sucs[$codSuc]['prefijo'] ?? 0),
            'imputar' => $imputar, 'total_imputado' => $totalImput, 'anticipo' => $anticipo, 'bruto' => $bruto, 'descuento' => $dto, 'dto_iva' => $dtoIva,
            'retenciones' => $ret, 'total_retenciones' => $totalRet, 'neto' => $neto, 'pagos' => $pagos, 'total_pagos' => $totalPagos, 'observaciones' => $obs,
            'pendientes' => $pend,
        ]];
    }

    /**
     * Graba el recibo (cobra, ctact, imputaciones y caja) en UNA transacción. Misma $operacion = mismo recibo (doble envío).
     * @param array<string,mixed> $r el 'recibo' de armarRecibo()
     * @return array{id:int,numero:int,repetido:bool}
     * @throws CuentaCorrienteError
     */
    public function emitirRecibo(array $r, string $operacion, int $usuarioId): array
    {
        if (preg_match('/^[0-9a-f]{32}$/', $operacion) !== 1) {
            throw new \InvalidArgumentException('Falta la clave de la operación.');
        }
        $previo = $this->reciboPorOperacion($operacion);
        if ($previo !== null) {
            return $previo + ['repetido' => true];
        }
        $cli = $r['cliente'];
        $ahora = date('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare('SELECT ultimo_recibo FROM sucursales WHERE codigo = ? FOR UPDATE');
            $st->execute([$r['sucursal']]);
            $ultimo = $st->fetchColumn();
            if ($ultimo === false) {
                throw new CuentaCorrienteError('La sucursal ya no existe.');
            }
            $numero = (int) $ultimo + 1;
            $existe = $this->pdo->prepare('SELECT 1 FROM cobra WHERE sucursal = ? AND recibo = ?');
            while (true) { // como el VFP: si el número ya está usado, sigue con el próximo
                $existe->execute([$r['sucursal'], $numero]);
                if ($existe->fetchColumn() === false) {
                    break;
                }
                $numero++;
            }
            $this->pdo->prepare('UPDATE sucursales SET ultimo_recibo = GREATEST(ultimo_recibo, ?), actualizacion = ? WHERE codigo = ?')->execute([$numero, $ahora, $r['sucursal']]);

            // Los comprobantes a cancelar se bloquean y se vuelve a mirar su saldo (otro usuario pudo cobrarlos)
            $filas = $this->bloquear(array_keys($r['imputar']), (int) $cli['codigo'], true);
            foreach ($r['imputar'] as $id => $imp) {
                if ($imp > (float) $filas[$id]['saldo'] + 0.004) {
                    throw new CuentaCorrienteError('El saldo de ' . self::TIPOS[$filas[$id]['tipo']] . ' ' . $filas[$id]['numero'] . ' cambió mientras cargabas el recibo. Volvé a armarlo.');
                }
            }

            $rt = $r['retenciones'];
            $this->pdo->prepare('INSERT INTO cobra (cliente, fecha, sucursal, prefijo, recibo, bruto, neto, dto_bruto, dto_iva, cod_rt1, imp_rt1, cod_rt2, imp_rt2, cod_rt3, imp_rt3, cod_rt4, imp_rt4, cod_rt5, imp_rt5,
                    observaciones, usuario_id, operacion, actualizacion) VALUES (?,?,?,?,?,?,?,?,?,1,?,2,?,3,?,4,?,5,?,?,?,?,?)')
                ->execute([$cli['codigo'], $r['fecha'], $r['sucursal'], $r['prefijo'], $numero, $r['bruto'], $r['neto'], self::redondear($r['descuento'] - $r['dto_iva']), $r['dto_iva'],
                    $rt[1], $rt[2], $rt[3], $rt[4], $rt[5], $r['observaciones'] !== '' ? $r['observaciones'] : null, $usuarioId > 0 ? $usuarioId : null, $operacion, $ahora]);
            $cobraId = (int) $this->pdo->lastInsertId();

            // El recibo en la cuenta corriente: su saldo es el anticipo (lo que no se aplicó a deuda)
            $this->pdo->prepare('INSERT INTO ctact (cobra_id, cliente, fecha, tipo, tico, letra, sucursal, prefijo, numero, neto, descuento, saldo, vencimiento, valor_dolar, actualizacion, observaciones)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$cobraId, $cli['codigo'], $r['fecha'], 'RC', 7, self::LETRA_RECIBO, $r['sucursal'], $r['prefijo'], $numero, $r['neto'], self::redondear($r['bruto'] - $r['neto']),
                    $r['anticipo'], $r['fecha'], (new \App\Modules\Comprobantes\ComprobantesService($this->pdo))->cotizacionDolar($r['fecha']), $ahora, $r['observaciones'] !== '' ? $r['observaciones'] : null]);
            $rcId = (int) $this->pdo->lastInsertId();

            foreach ($r['imputar'] as $id => $imp) {
                $this->imputarFila($filas[$id], ['fe_clave' => $rcId, 'tipo' => 'RC', 'tico' => 7, 'numero' => $numero, 'fecha' => $r['fecha']], $imp, (int) $cli['codigo'], $cobraId, $numero, $usuarioId, $ahora, false);
            }

            foreach ($r['pagos'] as $p) {
                $this->pdo->prepare('INSERT INTO caja (cobra_id, fecha, tipo, letra, canal, terminal, sucursal, prefijo, comprobante, cliente, p_fpago, p_cuotas, p_nro, p_fecvto, p_banco, p_cotiz, p_importe, a_pagar, actualizacion)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$cobraId, $r['fecha'], 'RC', self::LETRA_RECIBO, self::CANAL, 1, $r['sucursal'], $r['prefijo'], $numero, $cli['codigo'], $p['codigo'], 0,
                        $p['nro'] !== '' ? $p['nro'] : null, $p['vencimiento'], $p['banco'], 1, $p['importe'], $p['importe'], $ahora]);
            }
            $this->pdo->commit();
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_cobra_operacion')) {
                $previo = $this->reciboPorOperacion($operacion);
                if ($previo !== null) {
                    return $previo + ['repetido' => true];
                }
            }
            throw $e;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return ['id' => $cobraId, 'numero' => $numero, 'repetido' => false];
    }

    /** @return array{id:int,numero:int}|null */
    private function reciboPorOperacion(string $operacion): ?array
    {
        $st = $this->pdo->prepare('SELECT id, recibo FROM cobra WHERE operacion = ?');
        $st->execute([$operacion]);
        $f = $st->fetch();
        return $f ? ['id' => (int) $f['id'], 'numero' => (int) $f['recibo']] : null;
    }

    /**
     * Bloquea filas de ctact del cliente y las devuelve por id. Con $soloDebitos solo acepta débitos con saldo; si no, créditos.
     * @param int[] $ids @return array<int,array<string,mixed>>
     */
    private function bloquear(array $ids, int $cliente, bool $debitos): array
    {
        if ($ids === []) {
            return [];
        }
        sort($ids); // siempre en el mismo orden: evita bloqueos cruzados entre dos usuarios
        $st = $this->pdo->prepare('SELECT * FROM ctact WHERE fe_clave IN (' . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll() as $f) {
            $esDebito = (int) $f['tico'] < 7;
            if ((int) $f['cliente'] !== $cliente || $esDebito !== $debitos || (float) $f['saldo'] <= 0.004) {
                throw new CuentaCorrienteError('Un comprobante ya no tiene saldo o no corresponde a este cliente. Volvé a cargar la pantalla.');
            }
            $out[(int) $f['fe_clave']] = $f;
        }
        if (count($out) !== count($ids)) {
            throw new CuentaCorrienteError('Un comprobante ya no existe. Volvé a cargar la pantalla.');
        }
        return $out;
    }

    /**
     * Descuenta $importe del saldo del débito y deja la imputación. El crédito ($cr) ya bajó su saldo (recibo nuevo) o se baja acá.
     * @param array<string,mixed> $db fila de ctact @param array<string,mixed> $cr fila (o datos) del crédito
     */
    private function imputarFila(array $db, array $cr, float $importe, int $cliente, ?int $cobraId, int $recibo, int $usuarioId, string $ahora, bool $bajarCredito): void
    {
        $this->pdo->prepare('UPDATE ctact SET saldo = ROUND(saldo - ?, 2), actualizacion = ? WHERE fe_clave = ?')->execute([$importe, $ahora, $db['fe_clave']]);
        if ($bajarCredito) {
            $this->pdo->prepare('UPDATE ctact SET saldo = ROUND(saldo - ?, 2), actualizacion = ? WHERE fe_clave = ?')->execute([$importe, $ahora, $cr['fe_clave']]);
        }
        $this->pdo->prepare('INSERT INTO imputaciones (clientes, tipodb, ticodb, letradb, nrcodb, fecodb, tipocr, ticocr, nrcocr, fecocr, importe, recibo, ctact_db, ctact_cr, cobra_id, usuario_id, actualizacion)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$cliente, $db['tipo'], $db['tico'], $db['letra'], $db['numero'], $db['fecha'], $cr['tipo'], $cr['tico'], $cr['numero'], $cr['fecha'], $importe, $recibo,
                $db['fe_clave'], $cr['fe_clave'], $cobraId, $usuarioId > 0 ? $usuarioId : null, $ahora]);
    }

    // ------------------------------------------------------------------ imputación manual

    /**
     * Cancela deuda con saldos a favor (notas de crédito, anticipos de recibos, ajustes de crédito). Lo que se aplica de un lado tiene que ser
     * igual a lo del otro. @param array<int,float|string> $creditos ctact_id => importe @param array<int,float|string> $debitos ctact_id => importe
     * @return array{ok:bool,error?:string,cantidad?:int,total?:float}
     */
    public function imputarManual(int $cliente, array $creditos, array $debitos, int $usuarioId): array
    {
        $cr = $db = [];
        $totCr = $totDb = 0.0;
        foreach (['cr' => $creditos, 'db' => $debitos] as $lado => $lista) {
            foreach ($lista as $id => $txt) {
                $v = is_string($txt) ? Valida::decimal($txt === '' ? '0' : $txt) : (float) $txt;
                if ($v === null || $v < 0) {
                    return ['ok' => false, 'error' => 'Un importe no es válido.'];
                }
                $v = self::redondear($v);
                if ($v <= 0) {
                    continue;
                }
                if ($lado === 'cr') {
                    $cr[(int) $id] = $v;
                    $totCr += $v;
                } else {
                    $db[(int) $id] = $v;
                    $totDb += $v;
                }
            }
        }
        $totCr = self::redondear($totCr);
        $totDb = self::redondear($totDb);
        if ($cr === [] || $db === []) {
            return ['ok' => false, 'error' => 'Elegí al menos un saldo a favor y un comprobante de deuda.'];
        }
        if (abs($totCr - $totDb) > 0.004) {
            return ['ok' => false, 'error' => 'Lo que aplicás de los saldos a favor (' . plata_dec($totCr) . ') tiene que ser igual a lo que cancelás de la deuda (' . plata_dec($totDb) . ').'];
        }
        $ahora = date('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $filasCr = $this->bloquear(array_keys($cr), $cliente, false);
            $filasDb = $this->bloquear(array_keys($db), $cliente, true);
            foreach ($cr as $id => $v) {
                if ($v > (float) $filasCr[$id]['saldo'] + 0.004) {
                    throw new CuentaCorrienteError('Se aplica más que el saldo a favor de ' . self::TIPOS[$filasCr[$id]['tipo']] . ' ' . $filasCr[$id]['numero'] . '.');
                }
            }
            foreach ($db as $id => $v) {
                if ($v > (float) $filasDb[$id]['saldo'] + 0.004) {
                    throw new CuentaCorrienteError('Se cancela más que el saldo de ' . self::TIPOS[$filasDb[$id]['tipo']] . ' ' . $filasDb[$id]['numero'] . '.');
                }
            }
            // Se cruzan de a pares, en orden: cada par es una fila de imputaciones
            $n = 0;
            $restoCr = $cr;
            foreach ($db as $idDb => $faltaDb) {
                foreach ($restoCr as $idCr => $disp) {
                    if ($faltaDb <= 0.004) {
                        break;
                    }
                    if ($disp <= 0.004) {
                        continue;
                    }
                    $x = self::redondear(min($faltaDb, $disp));
                    $this->imputarFila($filasDb[$idDb], $filasCr[$idCr], $x, $cliente, null, (int) ($filasCr[$idCr]['tipo'] === 'RC' ? $filasCr[$idCr]['numero'] : 0), $usuarioId, $ahora, true);
                    $faltaDb = self::redondear($faltaDb - $x);
                    $restoCr[$idCr] = self::redondear($disp - $x);
                    $n++;
                }
            }
            $this->pdo->commit();
        } catch (CuentaCorrienteError $e) {
            $this->pdo->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return ['ok' => true, 'cantidad' => $n, 'total' => $totDb];
    }

    // ------------------------------------------------------------------ ajustes

    /**
     * Ajuste de cuenta corriente: AD suma deuda, AC la disminuye (queda como saldo a favor para imputar). Numera por sucursal.
     * @return array{ok:bool,error?:string,id?:int,numero?:int}
     */
    public function ajuste(string $tipo, int $cliente, string $fechaTxt, string $importeTxt, int $sucursal, string $obs, int $usuarioId): array
    {
        if (!isset(self::AJUSTES[$tipo])) {
            return ['ok' => false, 'error' => 'Elegí el tipo de ajuste.'];
        }
        $cli = $this->cliente($cliente);
        $fecha = Valida::fecha($fechaTxt);
        $imp = Valida::decimal($importeTxt);
        $obs = trim($obs);
        if ($cli === null) {
            return ['ok' => false, 'error' => 'Elegí el cliente.'];
        }
        if ($fecha === null || $fecha > date('Y-m-d')) {
            return ['ok' => false, 'error' => 'La fecha no es válida (no puede ser futura).'];
        }
        if ($imp === null || self::redondear($imp) <= 0) {
            return ['ok' => false, 'error' => 'El importe tiene que ser mayor que cero.'];
        }
        if ($obs === '' || mb_strlen($obs) > 255) {
            return ['ok' => false, 'error' => 'Escribí el motivo del ajuste (hasta 255 letras).'];
        }
        $imp = self::redondear($imp);
        $ahora = date('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare('SELECT prefijo FROM sucursales WHERE codigo = ? FOR UPDATE');
            $st->execute([$sucursal]);
            $prefijo = $st->fetchColumn();
            if ($prefijo === false) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'Elegí la sucursal.'];
            }
            $st = $this->pdo->prepare('SELECT COALESCE(MAX(numero), 0) + 1 FROM ctact WHERE tipo = ? AND sucursal = ?');
            $st->execute([$tipo, $sucursal]);
            $numero = (int) $st->fetchColumn();
            $this->pdo->prepare('INSERT INTO ctact (cliente, fecha, tipo, tico, letra, sucursal, prefijo, numero, neto, descuento, saldo, vencimiento, valor_dolar, actualizacion, observaciones)
                VALUES (?,?,?,?,?,?,?,?,?,0,?,?,?,?,?)')
                ->execute([$cliente, $fecha, $tipo, self::AJUSTES[$tipo][1], self::LETRA_RECIBO, $sucursal, (int) $prefijo, $numero, $imp, $imp, $fecha,
                    (new \App\Modules\Comprobantes\ComprobantesService($this->pdo))->cotizacionDolar($fecha), $ahora, $obs]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return ['ok' => true, 'id' => $id, 'numero' => $numero];
    }

    // ------------------------------------------------------------------ anular un recibo

    /** @return array{ok:bool,error?:string} */
    public function anularRecibo(int $cobraId, int $usuarioId): array
    {
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare('SELECT * FROM cobra WHERE id = ? FOR UPDATE');
            $st->execute([$cobraId]);
            $cobra = $st->fetch();
            if (!$cobra) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'No existe el recibo.'];
            }
            if ($cobra['estado'] === 'A') {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'El recibo ya está anulado.'];
            }
            $st = $this->pdo->prepare('SELECT * FROM ctact WHERE cobra_id = ? FOR UPDATE');
            $st->execute([$cobraId]);
            $rc = $st->fetch();
            if (!$rc) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'El recibo no tiene su fila de cuenta corriente (viene de una migración): no se puede anular desde la web.'];
            }
            $st = $this->pdo->prepare('SELECT COUNT(*) FROM imputaciones WHERE ctact_cr = ? AND (cobra_id IS NULL OR cobra_id <> ?)');
            $st->execute([$rc['fe_clave'], $cobraId]);
            if ((int) $st->fetchColumn() > 0) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'El saldo a favor de este recibo ya se imputó a otras facturas. No se puede anular.'];
            }
            $st = $this->pdo->prepare("SELECT COUNT(*) FROM caja WHERE cobra_id = ? AND cheque_estado <> ''");
            $st->execute([$cobraId]);
            if ((int) $st->fetchColumn() > 0) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'Un cheque de este recibo ya no está en cartera (depositado, entregado o rechazado). No se puede anular.'];
            }
            $ahora = date('Y-m-d H:i:s');
            $st = $this->pdo->prepare('SELECT ctact_db, importe FROM imputaciones WHERE cobra_id = ?');
            $st->execute([$cobraId]);
            foreach ($st->fetchAll() as $i) {
                $this->pdo->prepare('UPDATE ctact SET saldo = ROUND(saldo + ?, 2), actualizacion = ? WHERE fe_clave = ?')->execute([$i['importe'], $ahora, $i['ctact_db']]);
            }
            $this->pdo->prepare('DELETE FROM imputaciones WHERE cobra_id = ?')->execute([$cobraId]);
            $this->pdo->prepare('DELETE FROM caja WHERE cobra_id = ?')->execute([$cobraId]);
            $this->pdo->prepare("UPDATE ctact SET neto = 0, descuento = 0, saldo = 0, observaciones = 'ANULADO', actualizacion = ? WHERE fe_clave = ?")->execute([$ahora, $rc['fe_clave']]);
            $this->pdo->prepare("UPDATE cobra SET estado = 'A', actualizacion = ? WHERE id = ?")->execute([$ahora, $cobraId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return ['ok' => true];
    }

    // ------------------------------------------------------------------ ver / imprimir un recibo

    /** @return array<string,mixed>|null cabecera, cliente, emisor, comprobantes cancelados y medios de pago */
    public function recibo(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM cobra WHERE id = ?');
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) {
            return null;
        }
        $sc = $this->pdo->prepare('SELECT codigo, razon_social, cuit, domicilio, localidad, iva FROM clientes WHERE codigo = ?');
        $sc->execute([$c['cliente']]);
        $c['cliente_datos'] = $sc->fetch() ?: ['codigo' => $c['cliente'], 'razon_social' => '(cliente borrado)', 'cuit' => null, 'domicilio' => '', 'localidad' => '', 'iva' => 0];
        $c['emisor'] = $this->pdo->query('SELECT * FROM parametros WHERE id = 1')->fetch() ?: [];
        $st = $this->pdo->prepare('SELECT i.*, ' . self::sqlNumeroVisible('d') . ' AS numero_visible FROM imputaciones i LEFT JOIN ctact d ON d.fe_clave = i.ctact_db LEFT JOIN subdiario s ON s.fe_clave = d.subdiario_id
            WHERE i.cobra_id = ? ORDER BY i.id');
        $st->execute([$id]);
        $c['imputaciones'] = $st->fetchAll();
        $st = $this->pdo->prepare('SELECT c.*, f.descripcion AS fpago_descripcion, f.tipo AS fpago_tipo, b.descripcion AS banco_descripcion FROM caja c LEFT JOIN forma_de_pagos f ON f.codigo = c.p_fpago LEFT JOIN bancos b ON b.codigo = c.p_banco
            WHERE c.cobra_id = ? ORDER BY c.fe_clave');
        $st->execute([$id]);
        $c['pagos'] = $st->fetchAll();
        $st = $this->pdo->prepare('SELECT fe_clave, saldo FROM ctact WHERE cobra_id = ?');
        $st->execute([$id]);
        $rc = $st->fetch();
        $c['anticipo'] = $rc ? (float) $rc['saldo'] : 0.0;
        $c['ctact_id'] = $rc ? (int) $rc['fe_clave'] : null;
        $c['retenciones'] = [];
        foreach (self::RETENCIONES as $cod => $nombre) {
            if ((float) $c["imp_rt{$cod}"] > 0) {
                $c['retenciones'][$nombre] = (float) $c["imp_rt{$cod}"];
            }
        }
        $c['descuento'] = self::redondear((float) $c['dto_bruto'] + (float) $c['dto_iva']);
        return $c;
    }

    /** @return array{filas:array<int,array<string,mixed>>,total:int} recibos de un cliente o de todos */
    public function recibos(array $f, int $pagina, int $porPagina): array
    {
        $where = ['1 = 1'];
        $p = [];
        if (($d = Valida::fecha((string) ($f['desde'] ?? ''))) !== null) {
            $where[] = 'c.fecha >= ?';
            $p[] = $d;
        }
        if (($h = Valida::fecha((string) ($f['hasta'] ?? ''))) !== null) {
            $where[] = 'c.fecha <= ?';
            $p[] = $h;
        }
        $q = trim((string) ($f['cliente'] ?? ''));
        if ($q !== '') {
            if (ctype_digit($q)) {
                $where[] = '(c.cliente = ? OR c.recibo = ?)';
                array_push($p, (int) $q, (int) $q);
            } else {
                $where[] = 'k.razon_social LIKE ?';
                $p[] = '%' . $q . '%';
            }
        }
        $w = implode(' AND ', $where);
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM cobra c LEFT JOIN clientes k ON k.codigo = c.cliente WHERE {$w}");
        $st->execute($p);
        $total = (int) $st->fetchColumn();
        $st = $this->pdo->prepare("SELECT c.*, k.razon_social FROM cobra c LEFT JOIN clientes k ON k.codigo = c.cliente WHERE {$w} ORDER BY c.fecha DESC, c.id DESC LIMIT " . (int) $porPagina . ' OFFSET ' . (($pagina - 1) * $porPagina));
        $st->execute($p);
        return ['filas' => $st->fetchAll(), 'total' => $total];
    }

    // ------------------------------------------------------------------ cheques

    /**
     * Cheques cobrados (con recibo o con factura), con su estado. $f: estado ('' cartera por omisión, 'todos'), desde/hasta (fecha de cobro), q (número o cliente), diferidos=1.
     * @return array{filas:array<int,array<string,mixed>>,total:int,suma:float}
     */
    public function cheques(array $f, int $pagina, int $porPagina, bool $todo = false): array
    {
        $where = ['t.tipo = 5'];
        $p = [];
        $estado = (string) ($f['estado'] ?? '');
        if ($estado === '' || $estado === 'cartera') {
            $where[] = "c.cheque_estado = ''";
        } elseif ($estado !== 'todos' && isset(self::ESTADOS_CHEQUE[$estado])) {
            $where[] = 'c.cheque_estado = ?';
            $p[] = $estado;
        }
        if (!empty($f['diferidos'])) {
            $where[] = 'c.p_fecvto > CURDATE()';
        }
        if (($d = Valida::fecha((string) ($f['desde'] ?? ''))) !== null) {
            $where[] = 'c.p_fecvto >= ?';
            $p[] = $d;
        }
        if (($h = Valida::fecha((string) ($f['hasta'] ?? ''))) !== null) {
            $where[] = 'c.p_fecvto <= ?';
            $p[] = $h;
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(c.p_nro LIKE ? OR k.razon_social LIKE ?)';
            array_push($p, '%' . $q . '%', '%' . $q . '%');
        }
        $w = implode(' AND ', $where);
        $from = "FROM caja c JOIN forma_de_pagos t ON t.codigo = c.p_fpago LEFT JOIN clientes k ON k.codigo = c.cliente LEFT JOIN bancos b ON b.codigo = c.p_banco WHERE {$w}";
        $st = $this->pdo->prepare("SELECT COUNT(*), COALESCE(SUM(c.p_importe), 0) {$from}");
        $st->execute($p);
        [$total, $suma] = $st->fetch(PDO::FETCH_NUM);
        $lim = $todo ? '' : ' LIMIT ' . (int) $porPagina . ' OFFSET ' . (($pagina - 1) * $porPagina);
        $st = $this->pdo->prepare("SELECT c.fe_clave, c.fecha, c.tipo, c.letra, c.prefijo, c.comprobante, c.cliente, c.p_nro, c.p_fecvto, c.p_banco, c.p_importe, c.cheque_estado, c.cheque_fecha, c.cheque_obs,
            c.cobra_id, k.razon_social, b.descripcion AS banco {$from} ORDER BY c.p_fecvto, c.fe_clave{$lim}");
        $st->execute($p);
        return ['filas' => $st->fetchAll(), 'total' => (int) $total, 'suma' => self::redondear((float) $suma)];
    }

    /** Cambia el estado de un cheque (cartera, depositado, entregado, rechazado). @return array{ok:bool,error?:string} */
    public function estadoCheque(int $cajaId, string $estado, string $fechaTxt, string $obs): array
    {
        if (!isset(self::ESTADOS_CHEQUE[$estado])) {
            return ['ok' => false, 'error' => 'Elegí el estado.'];
        }
        $fecha = Valida::fecha($fechaTxt);
        if ($fecha === null) {
            return ['ok' => false, 'error' => 'La fecha no es válida.'];
        }
        $st = $this->pdo->prepare('SELECT c.fe_clave FROM caja c JOIN forma_de_pagos t ON t.codigo = c.p_fpago WHERE c.fe_clave = ? AND t.tipo = 5');
        $st->execute([$cajaId]);
        if ($st->fetchColumn() === false) {
            return ['ok' => false, 'error' => 'No es un cheque.'];
        }
        $this->pdo->prepare('UPDATE caja SET cheque_estado = ?, cheque_fecha = ?, cheque_obs = ?, actualizacion = ? WHERE fe_clave = ?')
            ->execute([$estado, $estado === '' ? null : $fecha, trim($obs) !== '' ? mb_substr(trim($obs), 0, 255) : null, date('Y-m-d H:i:s'), $cajaId]);
        return ['ok' => true];
    }

    // ------------------------------------------------------------------ deuda

    /**
     * Resumen de deuda: una fila por cliente con saldo (débitos pendientes, saldos a favor, saldo neto y la parte vencida).
     * @return array{filas:array<int,array<string,mixed>>,total:int,totales:array<string,float>}
     */
    public function resumenDeuda(array $f, int $pagina, int $porPagina, bool $todo = false): array
    {
        $where = ['c.saldo > 0.004'];
        $p = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $where[] = ctype_digit($q) ? '(k.codigo = ? OR k.razon_social LIKE ?)' : 'k.razon_social LIKE ?';
            if (ctype_digit($q)) {
                $p[] = (int) $q;
            }
            $p[] = '%' . $q . '%';
        }
        $hasta = Valida::fecha((string) ($f['al'] ?? '')) ?? date('Y-m-d');
        $w = implode(' AND ', $where);
        $base = "SELECT c.cliente, k.razon_social, k.cuit, SUM(IF(c.tico < 7, c.saldo, 0)) AS deuda, SUM(IF(c.tico >= 7, c.saldo, 0)) AS a_favor,
                SUM(IF(c.tico < 7, c.saldo, -c.saldo)) AS saldo, SUM(IF(c.tico < 7 AND c.vencimiento IS NOT NULL AND c.vencimiento < ?, c.saldo, 0)) AS vencido, MIN(IF(c.tico < 7, c.vencimiento, NULL)) AS primer_vencimiento
            FROM ctact c LEFT JOIN clientes k ON k.codigo = c.cliente WHERE {$w} GROUP BY c.cliente, k.razon_social, k.cuit";
        $having = empty($f['solo_deudores']) ? '' : ' HAVING saldo > 0.004';
        $pp = array_merge([$hasta], $p);
        $st = $this->pdo->prepare("SELECT COUNT(*), COALESCE(SUM(deuda), 0), COALESCE(SUM(a_favor), 0), COALESCE(SUM(saldo), 0), COALESCE(SUM(vencido), 0) FROM ({$base}{$having}) x");
        $st->execute($pp);
        $t = $st->fetch(PDO::FETCH_NUM);
        $lim = $todo ? '' : ' LIMIT ' . (int) $porPagina . ' OFFSET ' . (($pagina - 1) * $porPagina);
        $st = $this->pdo->prepare("{$base}{$having} ORDER BY saldo DESC, k.razon_social{$lim}");
        $st->execute($pp);
        return ['filas' => $st->fetchAll(), 'total' => (int) $t[0], 'totales' => ['deuda' => (float) $t[1], 'a_favor' => (float) $t[2], 'saldo' => (float) $t[3], 'vencido' => (float) $t[4]]];
    }
}
