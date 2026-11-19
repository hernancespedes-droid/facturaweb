<?php
declare(strict_types=1);

namespace App\Modules\MercadoPago;

use App\Core\Db;
use App\Core\Orden;
use App\Modules\Caja\CajaService;
use App\Modules\Comprobantes\ComprobantesService;
use App\Modules\Comprobantes\Tipos;
use PDO;

/**
 * Cobros con Mercado Pago.
 *
 * DECISIÓN D-57 (Dev Senior, a confirmar por Hernán): la venta NO se emite hasta que Mercado Pago confirma la acreditación. «Cobrar con Mercado Pago» arma y valida la
 * venta, la deja guardada en un cobro «pendiente» (sin número, sin CAE de ARCA, sin tocar stock ni caja) y muestra el QR y el link de pago. Cuando llega la acreditación
 * (notificación firmada o consulta del sistema) se emite sola, con Mercado Pago como forma de pago, a nombre de quien inició el cobro. Así nunca queda una factura real
 * por una venta que no se cobró. Si se acredita un pago pero la venta ya no se puede emitir (stock, licencia, ARCA), el cobro queda en «error_emision» para reintentar.
 * El cobro vence a los 30 minutos (config mp.expira_minutos); un pago que llega después de vencido o cancelado también deja el cobro en «error_emision» para que lo resuelva
 * un administrador (emitir igual o devolver desde Mercado Pago).
 */
final class MercadoPagoService
{
    public const FORMA = 'MP';
    public const ESTADOS = ['pendiente' => 'Pendiente de pago', 'emitido' => 'Cobrado y emitido', 'error_emision' => 'Cobrado, falta emitir', 'vencido' => 'Vencido', 'cancelado' => 'Cancelado'];
    /** Lo que no se guarda nunca en la base. */
    private const SECRETOS = ['autoriza_clave', 'autoriza_usuario', 'autoriza_yo', 'forma_pago', 'fpago', 'pagos', 'pago_nro', 'pago_banco', 'pago_vto', 'recibido', 'excede_ok', 'operacion'];

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    // ---------------------------------------------------------------- iniciar

    /**
     * Arma y valida la venta con Mercado Pago como única forma de pago y deja el cobro pendiente con su QR y su link.
     *
     * @param array<string,mixed> $datos lo cargado en la venta (con las autorizaciones de stock, si hicieron falta)
     * @return array{ok:bool,errores:array<string,string>,cbte:?array,cobro:?array}
     */
    public function iniciar(array $datos, int $usuarioId, bool $puedeCredito = false): array
    {
        $conPago = array_diff_key($datos, array_flip(['forma_pago', 'fpago', 'pagos', 'pago_nro', 'pago_banco', 'pago_vto', 'recibido', 'excede_ok'])) + ['forma_pago' => '1', 'pagos' => [['fpago' => self::FORMA, 'importe' => '']]];
        $svc = new ComprobantesService();
        $r = $svc->armar($conPago, $puedeCredito, $usuarioId);
        if (!$r['ok']) {
            return ['ok' => false, 'errores' => $r['errores'], 'cbte' => null, 'cobro' => null];
        }
        $cbte = $r['cbte'];
        // la autorización de stock (la de un supervisor con su clave) se resuelve ahora y queda asentada: la clave nunca se guarda
        $autorizo = !empty($cbte['stock_autorizado']) ? ['usuario_id' => (int) $cbte['stock_autorizado']['usuario_id'], 'nombre' => (string) $cbte['stock_autorizado']['nombre']] : null;
        $guardar = array_diff_key($conPago, array_flip(self::SECRETOS)) + ['forma_pago' => '1', 'pagos' => [['fpago' => self::FORMA, 'importe' => '']]];
        $token = bin2hex(random_bytes(16));
        $importe = (float) $cbte['calculo']['total'];
        $cliente = (string) ($cbte['cliente']['razon_social'] ?? '');
        $desc = mb_substr(Tipos::titulo($cbte['tipo'], $cbte['letra']) . ($cliente !== '' ? ' a ' . $cliente : ''), 0, 150);
        $vence = date('Y-m-d H:i:s', time() + MercadoPago::expiraMinutos() * 60);
        $mp = MercadoPago::crear(['token' => $token, 'importe' => $importe, 'descripcion' => $desc, 'vence_en' => $vence]); // si falla, no queda nada guardado
        $this->pdo->prepare('INSERT INTO mp_cobros (token, estado, modo, importe, descripcion, origen, datos, autorizo, usuario_id, creado_en, vence_en, link, qr_data, mp_ref)
            VALUES (?, \'pendiente\', ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)')
            ->execute([$token, MercadoPago::letraModo(), $importe, $desc, (string) ($datos['origen'] ?? ''), json_encode($guardar, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $autorizo === null ? null : json_encode($autorizo), $usuarioId, $vence, $mp['link'], $mp['qr'], $mp['ref']]);
        return ['ok' => true, 'errores' => [], 'cbte' => $cbte, 'cobro' => $this->obtener($token)];
    }

    // ---------------------------------------------------------------- leer

    /** @return array<string,mixed>|null */
    public function obtener(string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
            return null;
        }
        $st = $this->pdo->prepare('SELECT c.*, u.nombre AS cajero FROM mp_cobros c LEFT JOIN usuarios u ON u.id = c.usuario_id WHERE c.token = ?');
        $st->execute([$token]);
        $c = $st->fetch();
        return $c ? $this->vencerSiCorresponde($c) : null;
    }

    /** Un cobro pendiente que pasó su hora de vencimiento se marca vencido (se evalúa al leerlo). @param array<string,mixed> $c @return array<string,mixed> */
    private function vencerSiCorresponde(array $c): array
    {
        if ($c['estado'] === 'pendiente' && strtotime((string) $c['vence_en']) < time()) {
            $this->pdo->prepare("UPDATE mp_cobros SET estado = 'vencido' WHERE id = ? AND estado = 'pendiente'")->execute([$c['id']]);
            $c['estado'] = 'vencido';
        }
        return $c;
    }

    /** Dónde va el usuario cuando el cobro se emitió: al kiosco con el aviso o al comprobante. @param array<string,mixed> $c */
    public static function urlFinal(array $c): ?string
    {
        if ($c['estado'] !== 'emitido' || $c['comprobante_id'] === null) {
            return null;
        }
        return $c['origen'] === 'kiosco' ? url('/kiosco') . '?hecho=' . (int) $c['comprobante_id'] : url('/comprobantes/' . (int) $c['comprobante_id']);
    }

    // ---------------------------------------------------------------- confirmar y emitir

    /**
     * Pregunta a Mercado Pago si el cobro ya se acreditó (producción) y, si es así, lo emite. En modo simulado no consulta nada: el pago se aprueba con el botón del simulador.
     *
     * @param array<string,mixed> $c @return array<string,mixed> el cobro actualizado
     */
    public function refrescar(array $c): array
    {
        if ($c['estado'] === 'pendiente' && $c['modo'] === 'P' && MercadoPago::modo() === 'produccion') {
            try {
                $p = MercadoPago::pagoDeReferencia((string) $c['token']);
                if ($p !== null) {
                    return $this->confirmar((string) $c['token'], $p)['cobro'] ?? $c;
                }
            } catch (MercadoPagoError) {
                // sin conexión con Mercado Pago: se muestra el estado que hay y se reintenta en la próxima consulta
            }
        }
        return $this->obtener((string) $c['token']) ?? $c;
    }

    /**
     * Mercado Pago dice que hay un pago de este cobro. Se confirma que sea aprobado, de este cobro y por el importe, y se emite la venta. Es idempotente: si llegan
     * dos notificaciones (o la notificación y la consulta) el comprobante se emite una sola vez (la clave de operación es el token del cobro).
     *
     * @param array{id:string,estado:string,referencia:string,monto:float} $pago visto en Mercado Pago, no en la notificación
     * @return array{ok:bool,mensaje:string,cobro:?array}
     */
    public function confirmar(string $token, array $pago): array
    {
        $c = $this->obtener($token);
        if ($c === null || !hash_equals((string) $token, (string) $pago['referencia'])) {
            return ['ok' => false, 'mensaje' => 'El pago no corresponde a ningún cobro.', 'cobro' => null];
        }
        if ($pago['estado'] !== 'approved') {
            return ['ok' => false, 'mensaje' => 'El pago todavía no está aprobado.', 'cobro' => $c];
        }
        if ($c['estado'] === 'emitido') {
            return ['ok' => true, 'mensaje' => 'Ya estaba emitido.', 'cobro' => $c];
        }
        if (abs((float) $pago['monto'] - (float) $c['importe']) > 0.004) {
            $this->marcar((int) $c['id'], 'error_emision', 'Mercado Pago acreditó ' . plata_dec($pago['monto']) . ' y el cobro era de ' . plata_dec((float) $c['importe']) . ': revisar y resolver a mano.', $pago['id']);
            return ['ok' => false, 'mensaje' => 'El importe acreditado no coincide.', 'cobro' => $this->obtener($token)];
        }
        $tardio = in_array($c['estado'], ['vencido', 'cancelado'], true);
        $this->pdo->prepare('UPDATE mp_cobros SET mp_pago_id = ?, acreditado_en = COALESCE(acreditado_en, NOW()) WHERE id = ?')->execute([$pago['id'], $c['id']]);
        if ($tardio) { // llegó plata de un cobro que ya no estaba vigente: lo resuelve un administrador
            $this->marcar((int) $c['id'], 'error_emision', 'Se acreditó el pago de un cobro ' . ($c['estado'] === 'vencido' ? 'vencido' : 'cancelado') . ': emitirlo igual o devolver el dinero desde Mercado Pago.', $pago['id']);
            return ['ok' => false, 'mensaje' => 'Pago de un cobro no vigente.', 'cobro' => $this->obtener($token)];
        }
        return $this->emitir($c);
    }

    /**
     * Emite la venta guardada (la vuelve a armar y a validar con los datos de hoy). Si no se puede, el cobro queda en «error_emision» con el motivo.
     *
     * @param array<string,mixed> $c @return array{ok:bool,mensaje:string,cobro:?array}
     */
    public function emitir(array $c): array
    {
        $datos = json_decode((string) $c['datos'], true);
        if (!is_array($datos)) {
            $this->marcar((int) $c['id'], 'error_emision', 'No se pudo leer la venta guardada.');
            return ['ok' => false, 'mensaje' => 'Venta ilegible.', 'cobro' => $this->obtener((string) $c['token'])];
        }
        $uid = (int) $c['usuario_id'];
        $autorizo = $c['autorizo'] !== null ? json_decode((string) $c['autorizo'], true) : null;
        $anterior = CajaService::$actor;
        CajaService::$actor = $uid; // la notificación llega sin sesión: el cobro queda a nombre de quien lo inició
        try {
            $svc = new ComprobantesService();
            $r = $svc->armar($datos, false, $uid, is_array($autorizo) ? $autorizo : null);
            if (!$r['ok']) {
                $msg = implode(' ', array_values($r['errores']));
                $this->marcar((int) $c['id'], 'error_emision', 'Cobrado, pero la venta ya no se puede emitir: ' . $msg);
                return ['ok' => false, 'mensaje' => $msg, 'cobro' => $this->obtener((string) $c['token'])];
            }
            $e = $svc->emitir($r['cbte'], (string) $c['token'], $uid);
            $this->pdo->prepare("UPDATE mp_cobros SET estado = 'emitido', comprobante_id = ?, emitido_en = NOW(), error = NULL WHERE id = ?")->execute([(int) $e['id'], $c['id']]);
            return ['ok' => true, 'mensaje' => 'Emitido.', 'cobro' => $this->obtener((string) $c['token'])];
        } catch (\Throwable $ex) {
            $msg = $ex instanceof \InvalidArgumentException ? 'la venta guardada no es válida' : $ex->getMessage();
            $this->marcar((int) $c['id'], 'error_emision', 'Cobrado, pero no se pudo emitir: ' . $msg);
            error_log('[mercadopago] cobro ' . $c['id'] . ': ' . get_class($ex) . ': ' . $ex->getMessage());
            return ['ok' => false, 'mensaje' => $msg, 'cobro' => $this->obtener((string) $c['token'])];
        } finally {
            CajaService::$actor = $anterior;
        }
    }

    /** Reintenta emitir un cobro ya acreditado (administrador): tras arreglar el stock, la licencia o ARCA. @return array{ok:bool,mensaje:string,cobro:?array} */
    public function reintentar(int $id): array
    {
        $st = $this->pdo->prepare('SELECT * FROM mp_cobros WHERE id = ?');
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c || $c['estado'] !== 'error_emision' || $c['acreditado_en'] === null) {
            return ['ok' => false, 'mensaje' => 'Solo se reintenta un cobro acreditado que no se pudo emitir.', 'cobro' => null];
        }
        return $this->emitir($c);
    }

    public function cancelar(string $token): bool
    {
        $st = $this->pdo->prepare("UPDATE mp_cobros SET estado = 'cancelado' WHERE token = ? AND estado = 'pendiente'");
        $st->execute([$token]);
        return $st->rowCount() > 0;
    }

    private function marcar(int $id, string $estado, ?string $error, ?string $pagoId = null): void
    {
        $this->pdo->prepare('UPDATE mp_cobros SET estado = ?, error = ?, mp_pago_id = COALESCE(?, mp_pago_id) WHERE id = ?')->execute([$estado, $error === null ? null : mb_substr($error, 0, 500), $pagoId, $id]);
    }

    // ---------------------------------------------------------------- listado

    private const ORDEN = ['fecha' => 'c.creado_en', 'estado' => 'c.estado', 'importe' => 'c.importe', 'descripcion' => 'c.descripcion', 'cajero' => 'u.nombre'];

    /**
     * @param array<string,string> $f estado, orden @return array{filas:array<int,array<string,mixed>>,total:int}
     */
    public function listar(array $f, int $pagina, int $porPagina, ?int $soloUsuario): array
    {
        $this->pdo->exec("UPDATE mp_cobros SET estado = 'vencido' WHERE estado = 'pendiente' AND vence_en < NOW()");
        $where = ['1 = 1'];
        $args = [];
        if (isset(self::ESTADOS[$f['estado'] ?? ''])) {
            $where[] = 'c.estado = ?';
            $args[] = $f['estado'];
        }
        if ($soloUsuario !== null) {
            $where[] = 'c.usuario_id = ?';
            $args[] = $soloUsuario;
        }
        $w = implode(' AND ', $where);
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM mp_cobros c WHERE {$w}");
        $st->execute($args);
        $total = (int) $st->fetchColumn();
        $desde = max(0, ($pagina - 1) * $porPagina);
        $st = $this->pdo->prepare('SELECT c.id, c.token, c.estado, c.modo, c.importe, c.descripcion, c.creado_en, c.acreditado_en, c.comprobante_id, c.mp_pago_id, c.error, u.nombre AS cajero
            FROM mp_cobros c LEFT JOIN usuarios u ON u.id = c.usuario_id WHERE ' . $w . ' ORDER BY ' . Orden::sql(self::ORDEN, (string) ($f['orden'] ?? ''), 'c.creado_en DESC, c.id DESC') . " LIMIT {$porPagina} OFFSET {$desde}");
        $st->execute($args);
        return ['filas' => $st->fetchAll(), 'total' => $total];
    }
}
