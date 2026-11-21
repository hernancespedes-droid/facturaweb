<?php
declare(strict_types=1);

namespace App\Modules\Compras;

use App\Core\Db;
use App\Core\ExcelListado;
use App\Core\Modulos;
use App\Core\Orden;
use App\Core\Valida;
use App\Modules\Caja\CajaService;
use App\Modules\Comprobantes\Calculadora;
use PDO;

/**
 * Órdenes de pago a proveedores (entrega B de compras): cancelan facturas, notas de débito y gastos a cuenta corriente, descuentan notas de crédito y
 * anticipos, y se pagan con efectivo de la caja, transferencia, cheques de terceros de la cartera, cheques propios, retenciones u otro medio.
 * Como el recibo de cuenta corriente, del otro lado: armar() valida y calcula; emitir() numera con el numerador bloqueado, bloquea las compras y los
 * cheques y revalida adentro de la transacción.
 *
 * DECISIÓN D-115: lo pagado de más queda como anticipo a favor del proveedor (se descuenta en la próxima orden); una nota de crédito o un anticipo no
 * se «cobra»: solo se descuentan. Una orden puede no pagar nada si las notas de crédito cubren las facturas.
 * DECISIÓN D-116: las retenciones (Ganancias, IVA, Ingresos Brutos, SUSS) se cargan a mano con su importe y número de certificado: el sistema no
 * calcula el importe ni conoce las escalas o padrones de cada régimen. Cuentan como parte del pago.
 * DECISIÓN D-117: un cheque de terceros sale de la cartera de cuenta corriente por su importe completo y queda «Entregado a un tercero» con el
 * proveedor y la orden en la observación; al anular la orden vuelve a la cartera. El cheque propio solo se registra (banco, número, fecha).
 */
final class OrdenesPagoService
{
    public const MEDIOS = ['E' => 'Efectivo de la caja', 'T' => 'Transferencia', 'Q' => 'Cheque de terceros (de la cartera)', 'P' => 'Cheque propio',
        'R' => 'Retención', 'O' => 'Otro medio'];
    public const RETENCIONES = ['GAN' => 'Ganancias', 'IVA' => 'IVA', 'IIBB' => 'Ingresos Brutos', 'SUSS' => 'SUSS'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::pdo();
    }

    /** «Ganancias · certificado 123», «N° 456 · paga 30/11/2026 · Banco Nación»… */
    public static function detalleMedio(array $x): string
    {
        $p = [];
        if ((string) $x['retencion'] !== '') {
            $p[] = (self::RETENCIONES[$x['retencion']] ?? $x['retencion']) . ' · certificado ' . $x['numero'];
        } elseif ((string) $x['numero'] !== '') {
            $p[] = 'N° ' . $x['numero'];
        }
        if (!empty($x['vence'])) {
            $p[] = 'paga ' . fecha((string) $x['vence']);
        }
        if ((string) $x['detalle'] !== '') {
            $p[] = (string) $x['detalle'];
        }
        return implode(' · ', $p);
    }

    public static function numeroVisible(int $n): string
    {
        return 'OP ' . sprintf('%08d', $n);
    }

    // ---------------------------------------------------------------- lo pendiente

    /**
     * Lo que se le debe al proveedor y lo que tiene a favor.
     * @return array{debitos:array<int,array<string,mixed>>,creditos:array<int,array<string,mixed>>,anticipos:array<int,array<string,mixed>>,saldo:float}
     */
    public function pendientes(int $proveedorId): array
    {
        $st = $this->pdo->prepare("SELECT id, tipo, letra, punto_venta, numero, fecha, vence, total, saldo FROM compras
            WHERE proveedor_id = ? AND estado = 'V' AND forma = 'C' AND saldo > 0.005 ORDER BY COALESCE(vence, fecha), id");
        $st->execute([$proveedorId]);
        $deb = $cre = [];
        foreach ($st->fetchAll() as $c) {
            $c['titulo'] = ComprasService::titulo($c);
            $c['saldo'] = (float) $c['saldo'];
            if ($c['tipo'] === 'NC') {
                $cre[] = $c;
            } else {
                $deb[] = $c;
            }
        }
        $st = $this->pdo->prepare("SELECT id, numero, fecha, anticipo, anticipo_saldo FROM ordenes_pago WHERE proveedor_id = ? AND estado = 'V' AND anticipo_saldo > 0.005 ORDER BY fecha, id");
        $st->execute([$proveedorId]);
        $ant = array_map(static fn ($o) => $o + ['titulo' => 'Anticipo de la ' . self::numeroVisible((int) $o['numero'])], $st->fetchAll());
        $saldo = array_sum(array_column($deb, 'saldo')) - array_sum(array_column($cre, 'saldo')) - array_sum(array_map(static fn ($a) => (float) $a['anticipo_saldo'], $ant));
        return ['debitos' => $deb, 'creditos' => $cre, 'anticipos' => $ant, 'saldo' => Calculadora::r2($saldo)];
    }

    /** Cheques de terceros en cartera, para elegir. @return array<int,array<string,mixed>> */
    public function chequesEnCartera(): array
    {
        return $this->pdo->query("SELECT c.fe_clave AS id, c.p_nro AS numero, c.p_fecvto AS vence, c.p_importe AS importe, b.descripcion AS banco, k.razon_social AS cliente
            FROM caja c JOIN forma_de_pagos t ON t.codigo = c.p_fpago LEFT JOIN bancos b ON b.codigo = c.p_banco LEFT JOIN clientes k ON k.codigo = c.cliente
            WHERE t.tipo = 5 AND c.cheque_estado = '' AND c.p_importe > 0 ORDER BY c.p_fecvto, c.fe_clave")->fetchAll();
    }

    // ---------------------------------------------------------------- armar

    /**
     * Valida y calcula una orden. $in: proveedor, fecha, deb[compraId] => importe, cre[compraId] => importe, ant[opId] => importe,
     * medios[] => {medio, importe, detalle, numero, vence, retencion, cheque}, observaciones.
     *
     * @return array{ok:bool,errores:array<string,string>,orden?:array<string,mixed>}
     */
    public function armar(array $in): array
    {
        $e = [];
        $o = [];
        $prov = (new ProveedoresService())->obtener((int) ($in['proveedor'] ?? 0));
        if ($prov === null) {
            return ['ok' => false, 'errores' => ['proveedor' => 'Elegí el proveedor de la lista.']];
        }
        $o['proveedor_id'] = (int) $prov['id'];
        $o['proveedor'] = $prov;
        $o['fecha'] = trim((string) ($in['fecha'] ?? '')) === '' ? date('Y-m-d') : (Valida::fecha((string) $in['fecha']) ?? '');
        if ($o['fecha'] === '' || $o['fecha'] > date('Y-m-d')) {
            $e['fecha'] = 'La fecha no es válida (no puede ser posterior a hoy).';
        }
        $o['observaciones'] = mb_substr(trim((string) ($in['observaciones'] ?? '')), 0, 255);
        $pend = $this->pendientes($o['proveedor_id']);
        $porId = static function (array $filas): array {
            $out = [];
            foreach ($filas as $f) {
                $out[(int) $f['id']] = $f;
            }
            return $out;
        };
        $num = static function (mixed $v): ?float {
            $t = trim((string) $v);
            if ($t === '') {
                return 0.0;
            }
            $x = Valida::decimal($t);
            return $x === null || $x < 0 ? null : Calculadora::r2($x);
        };
        $o['imputaciones'] = [];
        $sumas = ['debitos' => 0.0, 'creditos' => 0.0];
        foreach ([['deb', $porId($pend['debitos']), 'debitos', 'saldo'], ['cre', $porId($pend['creditos']), 'creditos', 'saldo'], ['ant', $porId($pend['anticipos']), 'creditos', 'anticipo_saldo']] as [$k, $disp, $suma, $col]) {
            foreach ((is_array($in[$k] ?? null) ? $in[$k] : []) as $id => $txt) {
                $imp = $num($txt);
                if ($imp === 0.0) {
                    continue;
                }
                $f = $disp[(int) $id] ?? null;
                if ($f === null) {
                    $e['imputaciones'] = 'Uno de los comprobantes ya no está pendiente. Volvé a elegir.';
                    continue;
                }
                if ($imp === null || $imp - (float) $f[$col] > 0.005) {
                    $e['imputaciones'] = 'El importe de ' . $f['titulo'] . ' tiene que ser mayor que cero y no más que su saldo (' . plata_dec((float) $f[$col]) . ').';
                    continue;
                }
                $o['imputaciones'][] = ['clase' => $k, 'id' => (int) $id, 'titulo' => $f['titulo'], 'importe' => $imp];
                $sumas[$suma] += $imp;
            }
        }
        $o['debitos'] = Calculadora::r2($sumas['debitos']);
        $o['creditos'] = Calculadora::r2($sumas['creditos']);
        $o['a_pagar'] = Calculadora::r2($o['debitos'] - $o['creditos']);
        if ($o['a_pagar'] < -0.005) {
            $e['imputaciones'] = 'Las notas de crédito y anticipos elegidos (' . plata_dec($o['creditos']) . ') superan a lo que se cancela (' . plata_dec($o['debitos']) . ').';
        }

        // medios
        $o['medios'] = [];
        $cheques = [];
        foreach ($this->chequesEnCartera() as $ch) {
            $cheques[(int) $ch['id']] = $ch;
        }
        $usados = [];
        foreach ((is_array($in['medios'] ?? null) ? $in['medios'] : []) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $medio = (string) ($m['medio'] ?? '');
            $imp = $num($m['importe'] ?? '');
            if ($medio === '' && $imp === 0.0) {
                continue;
            }
            if (!isset(self::MEDIOS[$medio])) {
                $e['medios'] = 'Elegí el medio de pago de cada renglón.';
                continue;
            }
            $r = ['medio' => $medio, 'importe' => $imp ?? 0.0, 'detalle' => mb_substr(trim((string) ($m['detalle'] ?? '')), 0, 120),
                'numero' => mb_substr(trim((string) ($m['numero'] ?? '')), 0, 30), 'vence' => null, 'retencion' => '', 'cheque' => null];
            if ($medio === 'Q') {
                $ch = $cheques[(int) ($m['cheque'] ?? 0)] ?? null;
                if ($ch === null || isset($usados[(int) $ch['id']])) {
                    $e['medios'] = $ch === null ? 'Elegí un cheque de la cartera.' : 'Ese cheque ya está en otro renglón.';
                    continue;
                }
                $usados[(int) $ch['id']] = true;
                $r['cheque'] = (int) $ch['id'];
                $r['importe'] = (float) $ch['importe'];
                $r['numero'] = (string) $ch['numero'];
                $r['vence'] = $ch['vence'];
                $r['detalle'] = mb_substr(trim(($ch['banco'] ?? '') . ' · de ' . ($ch['cliente'] ?? '')), 0, 120);
            } elseif ($imp === null || $imp <= 0) {
                $e['medios'] = 'Cada medio de pago necesita un importe mayor que cero.';
                continue;
            }
            if ($medio === 'P') {
                $r['vence'] = Valida::fecha((string) ($m['vence'] ?? ''));
                if ($r['numero'] === '' || $r['vence'] === null) {
                    $e['medios'] = 'El cheque propio necesita número y fecha de pago.';
                }
            }
            if ($medio === 'R') {
                $r['retencion'] = (string) ($m['retencion'] ?? '');
                if (!isset(self::RETENCIONES[$r['retencion']])) {
                    $e['medios'] = 'Elegí qué retención es (Ganancias, IVA, Ingresos Brutos o SUSS).';
                } elseif ($r['numero'] === '') {
                    $e['medios'] = 'La retención necesita el número de certificado.';
                }
            }
            $o['medios'][] = $r;
        }
        $o['retenciones'] = Calculadora::r2(array_sum(array_map(static fn ($r) => $r['medio'] === 'R' ? $r['importe'] : 0.0, $o['medios'])));
        $o['pagado'] = Calculadora::r2(array_sum(array_column($o['medios'], 'importe')));
        $o['anticipo'] = Calculadora::r2($o['pagado'] - max(0.0, $o['a_pagar']));
        if ($o['imputaciones'] === [] && $o['pagado'] < 0.005 && !isset($e['imputaciones'])) {
            $e['imputaciones'] = 'Elegí qué se paga (o cargá un medio de pago para dejar un anticipo).';
        } elseif (!isset($e['imputaciones']) && !isset($e['medios']) && $o['anticipo'] < -0.005) {
            $e['medios'] = 'Falta pagar ' . plata_dec(-$o['anticipo']) . ': los medios suman ' . plata_dec($o['pagado']) . ' y hay que pagar ' . plata_dec($o['a_pagar']) . '.';
        }
        $o['anticipo'] = max(0.0, $o['anticipo']);
        if ($o['retenciones'] - max(0.0, $o['a_pagar']) > 0.005) {
            $e['medios'] = 'Las retenciones no pueden superar lo que se paga.';
        }
        return ['ok' => $e === [], 'errores' => $e, 'orden' => $o];
    }

    // ---------------------------------------------------------------- emitir

    /**
     * Numera y graba la orden. Todo en una transacción: numerador, compras, anticipos y cheques bloqueados y revalidados.
     * @return array{ok:bool,error?:string,id?:int,numero?:int,repetida?:bool}
     */
    public function emitir(array $o, int $usuarioId, ?string $operacion): array
    {
        $operacion = $operacion !== null && preg_match('/^[a-f0-9]{32}$/', $operacion) === 1 ? $operacion : null;
        if ($operacion !== null) {
            $st = $this->pdo->prepare('SELECT id, numero FROM ordenes_pago WHERE operacion = ?');
            $st->execute([$operacion]);
            if ($ya = $st->fetch()) {
                return ['ok' => true, 'id' => (int) $ya['id'], 'numero' => (int) $ya['numero'], 'repetida' => true];
            }
        }
        $turno = null;
        $efectivo = array_filter($o['medios'], static fn ($m) => $m['medio'] === 'E');
        if ($efectivo !== [] && Modulos::activo('caja')) {
            $turno = (new CajaService())->turnoAbierto($usuarioId);
            if ($turno === null) {
                return ['ok' => false, 'error' => 'Para pagar en efectivo abrí tu turno de caja (Caja › Mi caja), o elegí otro medio.'];
            }
        }
        $this->pdo->beginTransaction();
        try {
            $numero = (int) $this->pdo->query("SELECT ultimo FROM compras_numeradores WHERE clave = 'OP' FOR UPDATE")->fetchColumn() + 1;
            $this->pdo->prepare('SELECT id FROM proveedores WHERE id = ? FOR UPDATE')->execute([$o['proveedor_id']]);
            // compras y anticipos bloqueados en orden de id (dos órdenes a la vez no se cruzan)
            $imps = $o['imputaciones'];
            usort($imps, static fn ($a, $b) => [$a['clase'] === 'ant', $a['id']] <=> [$b['clase'] === 'ant', $b['id']]);
            $lockC = $this->pdo->prepare("SELECT saldo, tipo, estado, forma, proveedor_id FROM compras WHERE id = ? FOR UPDATE");
            $lockA = $this->pdo->prepare('SELECT anticipo_saldo, estado, proveedor_id FROM ordenes_pago WHERE id = ? FOR UPDATE');
            foreach ($imps as $i) {
                if ($i['clase'] === 'ant') {
                    $lockA->execute([$i['id']]);
                    $f = $lockA->fetch();
                    $ok = $f && $f['estado'] === 'V' && (int) $f['proveedor_id'] === $o['proveedor_id'] && $i['importe'] - (float) $f['anticipo_saldo'] <= 0.005;
                } else {
                    $lockC->execute([$i['id']]);
                    $f = $lockC->fetch();
                    $ok = $f && $f['estado'] === 'V' && $f['forma'] === 'C' && (int) $f['proveedor_id'] === $o['proveedor_id']
                        && ($f['tipo'] === 'NC') === ($i['clase'] === 'cre') && $i['importe'] - (float) $f['saldo'] <= 0.005;
                }
                if (!$ok) {
                    $this->pdo->rollBack();
                    return ['ok' => false, 'error' => 'El saldo de ' . $i['titulo'] . ' cambió mientras armabas la orden (otro usuario lo pagó). Volvé a elegir.'];
                }
            }
            $lockCh = $this->pdo->prepare('SELECT cheque_estado, p_importe FROM caja WHERE fe_clave = ? FOR UPDATE');
            foreach ($o['medios'] as $m) {
                if ($m['medio'] === 'Q') {
                    $lockCh->execute([$m['cheque']]);
                    $ch = $lockCh->fetch();
                    if (!$ch || $ch['cheque_estado'] !== '') {
                        $this->pdo->rollBack();
                        return ['ok' => false, 'error' => 'El cheque ' . $m['numero'] . ' ya no está en la cartera.'];
                    }
                }
            }
            $this->pdo->prepare("UPDATE compras_numeradores SET ultimo = ? WHERE clave = 'OP'")->execute([$numero]);
            $this->pdo->prepare('INSERT INTO ordenes_pago (numero, proveedor_id, fecha, debitos, creditos, retenciones, pagado, anticipo, anticipo_saldo, observaciones, usuario_id, creado_en, operacion)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)')
                ->execute([$numero, $o['proveedor_id'], $o['fecha'], $o['debitos'], $o['creditos'], $o['retenciones'], $o['pagado'], $o['anticipo'], $o['anticipo'],
                    $o['observaciones'], $usuarioId ?: null, $operacion]);
            $id = (int) $this->pdo->lastInsertId();
            $insI = $this->pdo->prepare('INSERT INTO ordenes_pago_imputaciones (orden_id, compra_id, anticipo_de, importe) VALUES (?, ?, ?, ?)');
            foreach ($o['imputaciones'] as $i) {
                if ($i['clase'] === 'ant') {
                    $insI->execute([$id, null, $i['id'], $i['importe']]);
                    $this->pdo->prepare('UPDATE ordenes_pago SET anticipo_saldo = anticipo_saldo - ? WHERE id = ?')->execute([$i['importe'], $i['id']]);
                } else {
                    $insI->execute([$id, $i['id'], null, $i['importe']]);
                    $this->pdo->prepare('UPDATE compras SET saldo = saldo - ? WHERE id = ?')->execute([$i['importe'], $i['id']]);
                }
            }
            $titulo = self::numeroVisible($numero) . ' · ' . $o['proveedor']['razon_social'];
            $caja = new CajaService();
            $insM = $this->pdo->prepare('INSERT INTO ordenes_pago_medios (orden_id, medio, importe, detalle, numero, vence, retencion, cheque_caja_id, caja_movimiento_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($o['medios'] as $m) {
                $mov = null;
                if ($m['medio'] === 'E' && $turno !== null) {
                    $motivo = mb_substr('Pago ' . $titulo, 0, 200);
                    $this->pdo->prepare("INSERT INTO caja_movimientos (creado_en, usuario_id, turno_id, tipo, importe, motivo) VALUES (NOW(), ?, ?, 'E', ?, ?)")
                        ->execute([$usuarioId, $turno['id'], $m['importe'], $motivo]);
                    $mov = (int) $this->pdo->lastInsertId();
                    $caja->auditar('egreso', 'movimiento', $mov, plata_dec($m['importe']) . ' · ' . $motivo, $usuarioId);
                }
                if ($m['medio'] === 'Q') {
                    $this->pdo->prepare("UPDATE caja SET cheque_estado = 'E', cheque_fecha = ?, cheque_obs = ?, actualizacion = ? WHERE fe_clave = ?")
                        ->execute([$o['fecha'], mb_substr('Entregado en la ' . $titulo, 0, 255), date('Y-m-d H:i:s'), $m['cheque']]);
                }
                $insM->execute([$id, $m['medio'], $m['importe'], $m['detalle'], $m['numero'], $m['vence'], $m['retencion'], $m['cheque'], $mov]);
            }
            $this->pdo->commit();
            return ['ok' => true, 'id' => $id, 'numero' => $numero];
        } catch (\Throwable $ex) {
            $this->pdo->rollBack();
            throw $ex;
        }
    }

    // ---------------------------------------------------------------- anular

    /** Anula una orden: devuelve los saldos, los cheques vuelven a la cartera y el efectivo a la caja (contra-asiento). @return array{ok:bool,error?:string} */
    public function anular(int $id, int $usuarioId, string $motivo): array
    {
        $motivo = trim($motivo);
        if ($motivo === '' || mb_strlen($motivo) > 160) {
            return ['ok' => false, 'error' => 'Escribí el motivo de la anulación (hasta 160 letras).'];
        }
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare('SELECT * FROM ordenes_pago WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $o = $st->fetch();
            if (!$o || $o['estado'] !== 'V') {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => $o ? 'La orden ya está anulada.' : 'No existe esa orden de pago.'];
            }
            if ((float) $o['anticipo'] - (float) $o['anticipo_saldo'] > 0.005) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'El anticipo de esta orden ya se usó en otra: anulá primero esa orden.'];
            }
            $med = $this->pdo->prepare('SELECT * FROM ordenes_pago_medios WHERE orden_id = ?');
            $med->execute([$id]);
            $medios = $med->fetchAll();
            $enCaja = array_filter($medios, static fn ($m) => $m['caja_movimiento_id'] !== null);
            $turno = null;
            if ($enCaja !== []) {
                $turno = (new CajaService())->turnoAbierto($usuarioId);
                if ($turno === null) {
                    $this->pdo->rollBack();
                    return ['ok' => false, 'error' => 'La orden pagó con efectivo de la caja: abrí tu turno para que la anulación devuelva el dinero.'];
                }
            }
            $imp = $this->pdo->prepare('SELECT * FROM ordenes_pago_imputaciones WHERE orden_id = ? ORDER BY compra_id IS NULL, compra_id, anticipo_de');
            $imp->execute([$id]);
            foreach ($imp->fetchAll() as $i) {
                if ($i['compra_id'] !== null) {
                    $this->pdo->prepare("UPDATE compras SET saldo = saldo + ? WHERE id = ? AND estado = 'V'")->execute([$i['importe'], $i['compra_id']]);
                } else {
                    $this->pdo->prepare('UPDATE ordenes_pago SET anticipo_saldo = anticipo_saldo + ? WHERE id = ?')->execute([$i['importe'], $i['anticipo_de']]);
                }
            }
            $caja = new CajaService();
            $titulo = self::numeroVisible((int) $o['numero']);
            foreach ($medios as $m) {
                if ($m['cheque_caja_id'] !== null) {
                    $this->pdo->prepare("UPDATE caja SET cheque_estado = '', cheque_fecha = NULL, cheque_obs = ?, actualizacion = ? WHERE fe_clave = ? AND cheque_estado = 'E'")
                        ->execute([mb_substr('Volvió a la cartera: se anuló la ' . $titulo, 0, 255), date('Y-m-d H:i:s'), $m['cheque_caja_id']]);
                }
                if ($m['caja_movimiento_id'] !== null) {
                    $this->pdo->prepare("INSERT INTO caja_movimientos (creado_en, usuario_id, turno_id, tipo, importe, motivo, anula_id) VALUES (NOW(), ?, ?, 'I', ?, ?, ?)")
                        ->execute([$usuarioId, $turno['id'], $m['importe'], mb_substr('Anulación de la ' . $titulo . ': ' . $motivo, 0, 200), $m['caja_movimiento_id']]);
                    $caja->auditar('anulacion', 'movimiento', (int) $m['caja_movimiento_id'], 'Anulación de la ' . $titulo . ' · ' . $motivo, $usuarioId);
                }
            }
            $this->pdo->prepare("UPDATE ordenes_pago SET estado = 'A', anticipo_saldo = 0, anulada_en = NOW(), anulada_por = ?, motivo_anulacion = ? WHERE id = ?")
                ->execute([$usuarioId ?: null, $motivo, $id]);
            $this->pdo->commit();
            return ['ok' => true];
        } catch (\Throwable $ex) {
            $this->pdo->rollBack();
            throw $ex;
        }
    }

    // ---------------------------------------------------------------- consultas

    /** @return ?array<string,mixed> la orden con proveedor, imputaciones y medios */
    public function obtener(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT o.*, p.razon_social, p.cuit, p.codigo AS proveedor_codigo, p.email, p.cbu_alias, u.nombre AS usuario
            FROM ordenes_pago o JOIN proveedores p ON p.id = o.proveedor_id LEFT JOIN usuarios u ON u.id = o.usuario_id WHERE o.id = ?');
        $st->execute([$id]);
        $o = $st->fetch();
        if (!$o) {
            return null;
        }
        $st = $this->pdo->prepare('SELECT i.*, c.tipo, c.letra, c.punto_venta, c.numero, c.fecha, c.vence, c.total, a.numero AS anticipo_numero
            FROM ordenes_pago_imputaciones i LEFT JOIN compras c ON c.id = i.compra_id LEFT JOIN ordenes_pago a ON a.id = i.anticipo_de WHERE i.orden_id = ? ORDER BY i.id');
        $st->execute([$id]);
        $o['imputaciones'] = array_map(static function ($i) {
            $i['titulo'] = $i['compra_id'] !== null ? ComprasService::titulo($i) : 'Anticipo de la ' . self::numeroVisible((int) $i['anticipo_numero']);
            $i['credito'] = $i['compra_id'] === null || $i['tipo'] === 'NC';
            return $i;
        }, $st->fetchAll());
        $st = $this->pdo->prepare('SELECT * FROM ordenes_pago_medios WHERE orden_id = ? ORDER BY id');
        $st->execute([$id]);
        $o['medios'] = $st->fetchAll();
        return $o;
    }

    /** Órdenes de una compra (para la ficha de la compra). @return array<int,array<string,mixed>> */
    public function deCompra(int $compraId): array
    {
        $st = $this->pdo->prepare('SELECT o.id, o.numero, o.fecha, o.estado, i.importe FROM ordenes_pago_imputaciones i JOIN ordenes_pago o ON o.id = i.orden_id WHERE i.compra_id = ? ORDER BY o.fecha, o.id');
        $st->execute([$compraId]);
        return $st->fetchAll();
    }

    public const ORDEN = ['numero' => 'o.numero', 'fecha' => 'o.fecha', 'proveedor' => 'p.razon_social', 'pagado' => 'o.pagado', 'retenciones' => 'o.retenciones', 'anticipo' => 'o.anticipo'];

    /**
     * Órdenes emitidas. $f: desde, hasta, proveedor (id), estado ('' vigentes, 'A' anuladas, 'todas'), medio, orden.
     * @return array{filas:array<int,array<string,mixed>>,total:int,totales:array<string,float>}
     */
    public function listar(array $f, int $pagina = 1, int $porPagina = 50): array
    {
        $w = ['1 = 1'];
        $p = [];
        if (($d = Valida::fecha((string) ($f['desde'] ?? ''))) !== null) {
            $w[] = 'o.fecha >= ?';
            $p[] = $d;
        }
        if (($h = Valida::fecha((string) ($f['hasta'] ?? ''))) !== null) {
            $w[] = 'o.fecha <= ?';
            $p[] = $h;
        }
        if ((int) ($f['proveedor'] ?? 0) > 0) {
            $w[] = 'o.proveedor_id = ?';
            $p[] = (int) $f['proveedor'];
        }
        $estado = (string) ($f['estado'] ?? '');
        if ($estado === '') {
            $w[] = "o.estado = 'V'";
        } elseif ($estado === 'A') {
            $w[] = "o.estado = 'A'";
        }
        if (isset(self::MEDIOS[(string) ($f['medio'] ?? '')])) {
            $w[] = 'EXISTS (SELECT 1 FROM ordenes_pago_medios m WHERE m.orden_id = o.id AND m.medio = ?)';
            $p[] = (string) $f['medio'];
        }
        $base = 'FROM ordenes_pago o JOIN proveedores p ON p.id = o.proveedor_id WHERE ' . implode(' AND ', $w);
        $st = $this->pdo->prepare("SELECT COUNT(*), COALESCE(SUM(IF(o.estado = 'V', o.pagado, 0)), 0), COALESCE(SUM(IF(o.estado = 'V', o.retenciones, 0)), 0), COALESCE(SUM(IF(o.estado = 'V', o.anticipo, 0)), 0) {$base}");
        $st->execute($p);
        [$n, $tp, $tr, $ta] = $st->fetch(PDO::FETCH_NUM);
        $orden = Orden::sql(self::ORDEN, (string) ($f['orden'] ?? ''), 'o.fecha DESC, o.numero DESC');
        $st = $this->pdo->prepare("SELECT o.*, p.razon_social, p.cuit,
                (SELECT GROUP_CONCAT(DISTINCT m.medio ORDER BY m.medio SEPARATOR '') FROM ordenes_pago_medios m WHERE m.orden_id = o.id) AS medios
            {$base} ORDER BY {$orden}" . ExcelListado::limite($f + ['pagina' => $pagina], $porPagina));
        $st->execute($p);
        return ['filas' => $st->fetchAll(), 'total' => (int) $n, 'totales' => ['pagado' => (float) $tp, 'retenciones' => (float) $tr, 'anticipo' => (float) $ta]];
    }

    /**
     * Cuenta corriente del proveedor con saldo corrido: debe = compras a cuenta corriente; haber = notas de crédito, órdenes de pago y pagos sueltos.
     * El saldo es lo que se le debe (negativo: a favor nuestro).
     * @return array{filas:array<int,array<string,mixed>>,anterior:float,saldo:float}
     */
    public function cuentaCorriente(int $proveedorId, ?string $desde, ?string $hasta): array
    {
        $mov = [];
        $st = $this->pdo->prepare("SELECT id, tipo, letra, punto_venta, numero, fecha, vence, total FROM compras WHERE proveedor_id = ? AND estado = 'V' AND forma = 'C'");
        $st->execute([$proveedorId]);
        foreach ($st->fetchAll() as $c) {
            $nc = $c['tipo'] === 'NC';
            $mov[] = ['fecha' => $c['fecha'], 'orden' => 1, 'id' => (int) $c['id'], 'clase' => 'compra', 'titulo' => ComprasService::titulo($c), 'vence' => $c['vence'],
                'debe' => $nc ? 0.0 : (float) $c['total'], 'haber' => $nc ? (float) $c['total'] : 0.0];
        }
        $st = $this->pdo->prepare("SELECT id, numero, fecha, pagado FROM ordenes_pago WHERE proveedor_id = ? AND estado = 'V'");
        $st->execute([$proveedorId]);
        foreach ($st->fetchAll() as $o) {
            $mov[] = ['fecha' => $o['fecha'], 'orden' => 2, 'id' => (int) $o['id'], 'clase' => 'orden', 'titulo' => self::numeroVisible((int) $o['numero']), 'vence' => null,
                'debe' => 0.0, 'haber' => (float) $o['pagado']];
        }
        // pagos sueltos desde la ficha de la compra (antes de las órdenes de pago, 0.43)
        $st = $this->pdo->prepare("SELECT g.fecha, g.importe, g.medio, c.id, c.tipo, c.letra, c.punto_venta, c.numero FROM compras_pagos g JOIN compras c ON c.id = g.compra_id
            WHERE c.proveedor_id = ? AND c.forma = 'C' AND c.estado = 'V' AND g.anulado = 0");
        $st->execute([$proveedorId]);
        foreach ($st->fetchAll() as $g) {
            $mov[] = ['fecha' => $g['fecha'], 'orden' => 2, 'id' => (int) $g['id'], 'clase' => 'compra', 'titulo' => 'Pago de ' . ComprasService::titulo($g), 'vence' => null,
                'debe' => 0.0, 'haber' => (float) $g['importe']];
        }
        usort($mov, static fn ($a, $b) => [$a['fecha'], $a['orden'], $a['id']] <=> [$b['fecha'], $b['orden'], $b['id']]);
        $anterior = 0.0;
        $saldo = 0.0;
        $filas = [];
        foreach ($mov as $m) {
            $saldo += $m['debe'] - $m['haber'];
            if ($desde !== null && $m['fecha'] < $desde) {
                $anterior = $saldo;
                continue;
            }
            if ($hasta !== null && $m['fecha'] > $hasta) {
                continue;
            }
            $m['saldo'] = Calculadora::r2($saldo);
            $filas[] = $m;
        }
        $final = $filas === [] ? $anterior : (float) end($filas)['saldo'];
        return ['filas' => $filas, 'anterior' => Calculadora::r2($anterior), 'saldo' => Calculadora::r2($final)];
    }
}
