<?php
declare(strict_types=1);

namespace App\Modules\Caja;

use App\Core\Db;
use App\Core\Orden;
use App\Core\Valida;
use App\Modules\Auth\Auth;
use App\Modules\Comprobantes\Calculadora;
use App\Modules\Comprobantes\Tipos;
use PDO;

/**
 * Caja: turnos de cajero con arqueo, ingresos y egresos manuales de efectivo y auditoría de todo lo que pasa por la caja.
 *
 * DECISIÓN D-54 (Dev Senior, a confirmar por Hernán): un turno por cajero (se abre con un fondo inicial y se cierra contando el efectivo: el sistema compara lo contado
 * con lo esperado = fondo + efectivo cobrado en el turno + ingresos − egresos manuales; si hay diferencia hay que dejar una observación). Los ingresos/egresos manuales
 * exigen turno abierto, motivo, el permiso `caja.mover` y no pueden superar el tope de Parámetros (`caja_tope`, por omisión $ 10.000.000). Nada se edita ni se borra:
 * un movimiento se anula con un contra-asiento. Cada acción queda en `caja_auditoria` con un hash encadenado (si alguien toca la tabla, la verificación lo marca).
 * Cambio de cajero: se cierra el turno contando y el que sigue abre el suyo (el fondo se sugiere con lo contado en el cierre anterior).
 */
final class CajaService
{
    public const TIPOS = ['I' => 'Ingreso', 'E' => 'Egreso'];

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    // ---------------------------------------------------------------- contexto de cada cobro

    /**
     * Quién cobra y cuándo, para anotarlo en cada fila de `caja`: [usuario, turno abierto de ese usuario, fecha y hora]. Sin sesión (tareas, pruebas) el usuario es null.
     *
     * @return array{usuario_id:?int,turno_id:?int,creado_en:string}
     */
    /** Quien hace el cobro cuando no hay sesión (la venta se emite desde la notificación de Mercado Pago): lo fija quien emite y lo suelta al terminar. */
    public static ?int $actor = null;

    public static function contexto(): array
    {
        $uid = self::$actor ?? Auth::id();
        $turno = null;
        if ($uid !== null) {
            $st = Db::pdo()->prepare("SELECT id FROM caja_turnos WHERE usuario_id = ? AND estado = 'A' ORDER BY id DESC LIMIT 1");
            $st->execute([$uid]);
            $v = $st->fetchColumn();
            $turno = $v === false ? null : (int) $v;
        }
        return ['usuario_id' => $uid, 'turno_id' => $turno, 'creado_en' => date('Y-m-d H:i:s')];
    }

    public static function tope(): float
    {
        return (float) (Db::pdo()->query('SELECT caja_tope FROM parametros WHERE id = 1')->fetchColumn() ?: 0);
    }

    // ---------------------------------------------------------------- turnos

    /** @return array<string,mixed>|null */
    public function turnoAbierto(int $usuarioId): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM caja_turnos WHERE usuario_id = ? AND estado = 'A' ORDER BY id DESC LIMIT 1");
        $st->execute([$usuarioId]);
        return $st->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function turno(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT t.*, u.nombre AS cajero FROM caja_turnos t JOIN usuarios u ON u.id = t.usuario_id WHERE t.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Lo que quedó contado en el último cierre (sugerencia de fondo para el que abre). */
    public function fondoSugerido(): float
    {
        $v = $this->pdo->query("SELECT contado FROM caja_turnos WHERE estado = 'C' ORDER BY cerrado_en DESC, id DESC LIMIT 1")->fetchColumn();
        return $v === false || $v === null ? 0.0 : (float) $v;
    }

    /** @return array{ok:bool,error?:string,id?:int} */
    public function abrir(int $usuarioId, string $fondoTxt): array
    {
        $fondo = trim($fondoTxt) === '' ? 0.0 : Valida::decimal($fondoTxt);
        if ($fondo === null || $fondo < 0) {
            return ['ok' => false, 'error' => 'El fondo inicial no es un importe válido.'];
        }
        if ($fondo > self::tope() && self::tope() > 0) {
            return ['ok' => false, 'error' => 'El fondo inicial supera el tope de caja de Parámetros (' . plata_dec(self::tope()) . ').'];
        }
        $this->pdo->beginTransaction();
        try {
            // un solo turno abierto por cajero (se bloquea la fila del usuario para que dos clics no abran dos)
            $this->pdo->prepare('SELECT id FROM usuarios WHERE id = ? FOR UPDATE')->execute([$usuarioId]);
            if ($this->turnoAbierto($usuarioId) !== null) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'Ya tenés un turno abierto.'];
            }
            $this->pdo->prepare("INSERT INTO caja_turnos (usuario_id, abierto_en, fondo_inicial, estado) VALUES (?, NOW(), ?, 'A')")->execute([$usuarioId, $fondo]);
            $id = (int) $this->pdo->lastInsertId();
            $this->auditar('apertura', 'turno', $id, 'Fondo inicial ' . plata_dec($fondo), $usuarioId);
            $this->pdo->commit();
            return ['ok' => true, 'id' => $id];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Resumen en vivo de un turno: efectivo cobrado (ventas y recibos), ingresos y egresos manuales, lo esperado en el cajón y lo cobrado por otras formas.
     *
     * @return array{fondo:float,efectivo:float,ingresos:float,egresos:float,esperado:float,otras:array<int,array<string,mixed>>}
     */
    public function resumenTurno(int $turnoId): array
    {
        $t = $this->turno($turnoId);
        $fondo = (float) ($t['fondo_inicial'] ?? 0);
        $st = $this->pdo->prepare('SELECT t.codigo, t.descripcion, t.tipo, COALESCE(SUM(c.p_importe), 0) AS total, COUNT(*) AS cantidad
            FROM caja c JOIN forma_de_pagos t ON t.codigo = c.p_fpago WHERE c.turno_id = ? AND t.tipo <> 6 GROUP BY t.codigo, t.descripcion, t.tipo ORDER BY t.tipo, t.descripcion');
        $st->execute([$turnoId]);
        $efectivo = 0.0;
        $otras = [];
        foreach ($st->fetchAll() as $f) {
            if ((int) $f['tipo'] === 1) {
                $efectivo += (float) $f['total'];
            } else {
                $otras[] = $f;
            }
        }
        $st = $this->pdo->prepare("SELECT COALESCE(SUM(IF(tipo = 'I', importe, 0)), 0), COALESCE(SUM(IF(tipo = 'E', importe, 0)), 0) FROM caja_movimientos WHERE turno_id = ?");
        $st->execute([$turnoId]);
        [$ing, $egr] = $st->fetch(PDO::FETCH_NUM);
        return ['fondo' => $fondo, 'efectivo' => Calculadora::r2($efectivo), 'ingresos' => (float) $ing, 'egresos' => (float) $egr,
            'esperado' => Calculadora::r2($fondo + $efectivo + (float) $ing - (float) $egr), 'otras' => $otras];
    }

    /**
     * Cierra el turno con el arqueo. Lo cierra su cajero (o quien audita la caja). Si lo contado no coincide con lo esperado hace falta una observación.
     *
     * @return array{ok:bool,error?:string,diferencia?:float}
     */
    public function cerrar(int $turnoId, int $usuarioId, bool $puedeAuditar, string $contadoTxt, string $obs): array
    {
        $contado = Valida::decimal($contadoTxt);
        if ($contado === null || $contado < 0) {
            return ['ok' => false, 'error' => 'Escribí el efectivo que contaste (puede ser 0).'];
        }
        $obs = trim($obs);
        if (mb_strlen($obs) > 255) {
            return ['ok' => false, 'error' => 'La observación admite hasta 255 letras.'];
        }
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare('SELECT * FROM caja_turnos WHERE id = ? FOR UPDATE');
            $st->execute([$turnoId]);
            $t = $st->fetch();
            if (!$t || $t['estado'] !== 'A') {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'Ese turno no está abierto.'];
            }
            if ((int) $t['usuario_id'] !== $usuarioId && !$puedeAuditar) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'Solo el cajero (o quien audita la caja) puede cerrar ese turno.'];
            }
            $esperado = $this->resumenTurno($turnoId)['esperado'];
            $dif = Calculadora::r2($contado - $esperado);
            if (abs($dif) > 0.004 && $obs === '') {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'Hay una diferencia de ' . plata_dec($dif) . ' (esperado ' . plata_dec($esperado) . ', contado ' . plata_dec($contado) . '): dejá una observación.'];
            }
            $this->pdo->prepare("UPDATE caja_turnos SET estado = 'C', cerrado_en = NOW(), esperado = ?, contado = ?, diferencia = ?, observaciones = ? WHERE id = ?")
                ->execute([$esperado, $contado, $dif, $obs, $turnoId]);
            $this->auditar('cierre', 'turno', $turnoId, 'Esperado ' . plata_dec($esperado) . ', contado ' . plata_dec($contado) . ', diferencia ' . plata_dec($dif) . ($obs !== '' ? ' · ' . $obs : '')
                . ((int) $t['usuario_id'] !== $usuarioId ? ' · cerrado por otro usuario' : ''), $usuarioId);
            $this->pdo->commit();
            return ['ok' => true, 'diferencia' => $dif];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // ---------------------------------------------------------------- ingresos y egresos manuales

    /** @return array{ok:bool,error?:string,id?:int} */
    public function mover(int $usuarioId, string $tipo, string $importeTxt, string $motivo): array
    {
        $motivo = trim($motivo);
        $importe = Valida::decimal($importeTxt);
        if (!isset(self::TIPOS[$tipo])) {
            return ['ok' => false, 'error' => 'Elegí si es un ingreso o un egreso.'];
        }
        if ($importe === null || $importe <= 0) {
            return ['ok' => false, 'error' => 'El importe tiene que ser mayor que cero.'];
        }
        if ($motivo === '' || mb_strlen($motivo) > 200) {
            return ['ok' => false, 'error' => 'Escribí el motivo (hasta 200 letras).'];
        }
        $importe = Calculadora::r2($importe);
        $tope = self::tope();
        if ($tope > 0 && $importe > $tope) {
            $this->pdo->beginTransaction();
            $this->auditar('rechazo', 'movimiento', null, self::TIPOS[$tipo] . ' de ' . plata_dec($importe) . ' rechazado: supera el tope de ' . plata_dec($tope) . ' · ' . $motivo, $usuarioId);
            $this->pdo->commit();
            return ['ok' => false, 'error' => 'El importe supera el tope de caja de Parámetros (' . plata_dec($tope) . ').'];
        }
        $turno = $this->turnoAbierto($usuarioId);
        if ($turno === null) {
            return ['ok' => false, 'error' => 'Abrí tu turno de caja antes de mover dinero.'];
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO caja_movimientos (creado_en, usuario_id, turno_id, tipo, importe, motivo) VALUES (NOW(), ?, ?, ?, ?, ?)')
                ->execute([$usuarioId, $turno['id'], $tipo, $importe, $motivo]);
            $id = (int) $this->pdo->lastInsertId();
            $this->auditar($tipo === 'I' ? 'ingreso' : 'egreso', 'movimiento', $id, plata_dec($importe) . ' · ' . $motivo, $usuarioId);
            $this->pdo->commit();
            return ['ok' => true, 'id' => $id];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Anula un movimiento manual con un contra-asiento (del tipo contrario, en el turno abierto de quien anula). @return array{ok:bool,error?:string} */
    public function anular(int $usuarioId, int $movimientoId, string $motivo): array
    {
        $motivo = trim($motivo);
        if ($motivo === '' || mb_strlen($motivo) > 160) {
            return ['ok' => false, 'error' => 'Escribí el motivo de la anulación (hasta 160 letras).'];
        }
        $turno = $this->turnoAbierto($usuarioId);
        if ($turno === null) {
            return ['ok' => false, 'error' => 'Abrí tu turno de caja antes de anular un movimiento.'];
        }
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare('SELECT * FROM caja_movimientos WHERE id = ? FOR UPDATE');
            $st->execute([$movimientoId]);
            $m = $st->fetch();
            if (!$m) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'No existe ese movimiento.'];
            }
            if ($m['anula_id'] !== null) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'Ese movimiento ya es una anulación: no se anula otra vez.'];
            }
            $ya = $this->pdo->prepare('SELECT COUNT(*) FROM caja_movimientos WHERE anula_id = ?');
            $ya->execute([$movimientoId]);
            if ((int) $ya->fetchColumn() > 0) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'Ese movimiento ya fue anulado.'];
            }
            if (\App\Modules\Compras\ComprasService::esPagoDeCompra($movimientoId)) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'Ese movimiento es el pago de una compra: se anula anulando la compra (Compras).'];
            }
            $contra = $m['tipo'] === 'I' ? 'E' : 'I';
            $this->pdo->prepare('INSERT INTO caja_movimientos (creado_en, usuario_id, turno_id, tipo, importe, motivo, anula_id) VALUES (NOW(), ?, ?, ?, ?, ?, ?)')
                ->execute([$usuarioId, $turno['id'], $contra, $m['importe'], 'Anula movimiento ' . $movimientoId . ': ' . $motivo, $movimientoId]);
            $id = (int) $this->pdo->lastInsertId();
            $this->auditar('anulacion', 'movimiento', $movimientoId, 'Contra-asiento ' . $id . ' por ' . plata_dec((float) $m['importe']) . ' · ' . $motivo, $usuarioId);
            $this->pdo->commit();
            return ['ok' => true];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // ---------------------------------------------------------------- auditoría

    /** Anota una acción en el registro de auditoría, encadenada con la anterior. Hay que llamarlo dentro de una transacción. */
    public function auditar(string $accion, string $entidad, ?int $entidadId, string $detalle, ?int $usuarioId): void
    {
        $prev = (string) ($this->pdo->query('SELECT hash FROM caja_auditoria ORDER BY id DESC LIMIT 1 FOR UPDATE')->fetchColumn() ?: str_repeat('0', 64));
        $cuando = date('Y-m-d H:i:s');
        $detalle = mb_substr($detalle, 0, 500);
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $hash = self::hash($prev, $cuando, $usuarioId, $accion, $entidad, $entidadId, $detalle);
        $this->pdo->prepare('INSERT INTO caja_auditoria (creado_en, usuario_id, accion, entidad, entidad_id, detalle, ip, hash) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$cuando, $usuarioId, $accion, $entidad, $entidadId, $detalle, $ip, $hash]);
    }

    private static function hash(string $prev, string $cuando, ?int $uid, string $accion, string $entidad, ?int $entidadId, string $detalle): string
    {
        return hash('sha256', implode('|', [$prev, $cuando, (string) $uid, $accion, $entidad, (string) $entidadId, $detalle]));
    }

    /** Recorre la cadena: si una fila fue cambiada o borrada, dice cuál es la primera que no cierra. @return array{ok:bool,total:int,primera_mala:?int} */
    public function verificar(): array
    {
        $prev = str_repeat('0', 64);
        $n = 0;
        foreach ($this->pdo->query('SELECT * FROM caja_auditoria ORDER BY id') as $f) {
            $n++;
            $esperado = self::hash($prev, (string) $f['creado_en'], $f['usuario_id'] === null ? null : (int) $f['usuario_id'], (string) $f['accion'], (string) $f['entidad'],
                $f['entidad_id'] === null ? null : (int) $f['entidad_id'], (string) $f['detalle']);
            if (!hash_equals($esperado, (string) $f['hash'])) {
                return ['ok' => false, 'total' => $n, 'primera_mala' => (int) $f['id']];
            }
            $prev = (string) $f['hash'];
        }
        return ['ok' => true, 'total' => $n, 'primera_mala' => null];
    }

    /** @return array<int,array<string,mixed>> los últimos registros de auditoría */
    public function registro(int $limite = 60): array
    {
        return $this->pdo->query('SELECT a.*, u.nombre AS usuario FROM caja_auditoria a LEFT JOIN usuarios u ON u.id = a.usuario_id ORDER BY a.id DESC LIMIT ' . max(1, $limite))->fetchAll();
    }

    // ---------------------------------------------------------------- listados

    private const ORDEN = ['cuando' => 'z.cuando', 'origen' => 'z.origen', 'detalle' => 'z.detalle', 'forma' => 'z.forma', 'importe' => 'z.importe', 'usuario' => 'u.nombre', 'turno' => 'z.turno_id'];

    /**
     * Todos los movimientos de la caja (cobros de ventas y recibos, ingresos y egresos manuales) con hora y usuario, para la pantalla de caja y la auditoría.
     * Filtros: desde, hasta (fechas), usuario (id), origen (venta|recibo|manual), turno (id).
     *
     * @param array<string,string> $f
     * @return array{filas:array<int,array<string,mixed>>,total:int}
     */
    public function movimientos(array $f, int $pagina, int $porPagina): array
    {
        $d = Valida::fecha((string) ($f['desde'] ?? '')) ?? date('Y-m-d');
        $h = Valida::fecha((string) ($f['hasta'] ?? '')) ?? $d;
        if ($h < $d) {
            [$d, $h] = [$h, $d];
        }
        $nro = 'IF(s.fe_clave IS NULL, c.comprobante, ' . Tipos::sqlNumeroVisible('s') . ')';
        $base = "SELECT * FROM (
            SELECT IF(c.cobra_id IS NULL, 'venta', 'recibo') AS origen, c.fe_clave AS id, COALESCE(c.creado_en, CAST(c.fecha AS DATETIME)) AS cuando, c.fecha AS dia,
                   CONCAT(c.tipo COLLATE utf8mb4_unicode_ci, ' ', c.letra COLLATE utf8mb4_unicode_ci, ' ', LPAD(c.prefijo, 4, '0'), '-', LPAD({$nro}, 8, '0'), ' · ', COALESCE(s.cliente_razon_social, k.razon_social, '')) AS detalle,
                   t.descripcion AS forma, c.p_importe AS importe, c.p_vuelto AS vuelto, c.usuario_id, c.turno_id, NULL AS anula_id, NULL AS mtipo
              FROM caja c JOIN forma_de_pagos t ON t.codigo = c.p_fpago LEFT JOIN subdiario s ON s.fe_clave = c.subdiario_id LEFT JOIN clientes k ON k.codigo = c.cliente
             WHERE t.tipo <> 6
            UNION ALL
            SELECT 'manual', m.id, m.creado_en, DATE(m.creado_en), m.motivo, 'EFECTIVO',
                   IF(m.tipo = 'I', m.importe, -m.importe), 0, m.usuario_id, m.turno_id, m.anula_id, m.tipo
              FROM caja_movimientos m
        ) x";
        $where = ['x.dia BETWEEN ? AND ?'];
        $args = [$d, $h];
        if (ctype_digit((string) ($f['usuario'] ?? '')) && (int) $f['usuario'] > 0) {
            $where[] = 'x.usuario_id = ?';
            $args[] = (int) $f['usuario'];
        }
        if (in_array($f['origen'] ?? '', ['venta', 'recibo', 'manual'], true)) {
            $where[] = 'x.origen = ?';
            $args[] = $f['origen'];
        }
        if (ctype_digit((string) ($f['turno'] ?? '')) && (int) $f['turno'] > 0) {
            $where[] = 'x.turno_id = ?';
            $args[] = (int) $f['turno'];
        }
        $w = ' WHERE ' . implode(' AND ', $where);
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM ({$base}) y WHERE " . str_replace('x.', 'y.', implode(' AND ', $where)) . '');
        $st->execute($args);
        $total = (int) $st->fetchColumn();
        $desde = max(0, ($pagina - 1) * $porPagina);
        $orden = Orden::sql(self::ORDEN, (string) ($f['orden'] ?? ''), 'z.cuando DESC, z.id DESC');
        $st = $this->pdo->prepare("SELECT z.*, u.nombre AS usuario, (SELECT COUNT(*) FROM caja_movimientos a WHERE z.origen = 'manual' AND a.anula_id = z.id) AS anulado
            FROM ({$base}{$w}) z LEFT JOIN usuarios u ON u.id = z.usuario_id ORDER BY {$orden} LIMIT {$porPagina} OFFSET {$desde}");
        $st->execute($args);
        return ['filas' => $st->fetchAll(), 'total' => $total];
    }

    /** Turnos con su cajero. @param array<string,string> $f desde, hasta, usuario @return array<int,array<string,mixed>> */
    public function turnos(array $f, ?int $soloUsuario): array
    {
        $d = Valida::fecha((string) ($f['desde'] ?? '')) ?? date('Y-m-d', strtotime('-30 days'));
        $h = Valida::fecha((string) ($f['hasta'] ?? '')) ?? date('Y-m-d');
        $where = ['DATE(t.abierto_en) BETWEEN ? AND ?'];
        $args = [$d, $h];
        $uid = $soloUsuario ?? (ctype_digit((string) ($f['usuario'] ?? '')) && (int) $f['usuario'] > 0 ? (int) $f['usuario'] : null);
        if ($uid !== null) {
            $where[] = 't.usuario_id = ?';
            $args[] = $uid;
        }
        $orden = Orden::sql(['abierto' => 't.abierto_en', 'cajero' => 'u.nombre', 'cerrado' => 't.cerrado_en', 'fondo' => 't.fondo_inicial', 'esperado' => 't.esperado',
            'contado' => 't.contado', 'diferencia' => 't.diferencia'], (string) ($f['orden'] ?? ''), 't.abierto_en DESC');
        $st = $this->pdo->prepare('SELECT t.*, u.nombre AS cajero FROM caja_turnos t JOIN usuarios u ON u.id = t.usuario_id WHERE ' . implode(' AND ', $where) . " ORDER BY {$orden} LIMIT 500");
        $st->execute($args);
        return $st->fetchAll();
    }

    /** Usuarios que alguna vez tocaron la caja (para el filtro). @return array<int,string> */
    public function usuarios(): array
    {
        $r = $this->pdo->query('SELECT DISTINCT u.id, u.nombre FROM usuarios u WHERE u.id IN (SELECT usuario_id FROM caja_turnos UNION SELECT usuario_id FROM caja_movimientos UNION SELECT usuario_id FROM caja WHERE usuario_id IS NOT NULL) ORDER BY u.nombre');
        $o = [];
        foreach ($r->fetchAll() as $u) {
            $o[(int) $u['id']] = (string) $u['nombre'];
        }
        return $o;
    }
}
