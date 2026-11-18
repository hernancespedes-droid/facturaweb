<?php
declare(strict_types=1);

namespace App\Modules\Stock;

use App\Core\Db;
use App\Modules\Auth\LoginService;
use App\Modules\Configuracion\Configuracion;
use PDO;

/**
 * Reservas de stock entre puestos (pedido de Hernán, 18/11): para no vender dos veces lo mismo cuando varios puestos —incluso en la nube— venden a la vez.
 *
 *  - Quien arma una venta (carrito del kiosco o factura/presupuesto en carga) RESERVA lo que lleva, a su nombre, hasta que se factura, se vacía o vence
 *    (stock.reserva_minutos; mientras la pantalla está abierta se renueva). Los demás ven «reservado por Ana».
 *  - Disponible = stock del depósito desde el que se vende (el principal) menos lo reservado por OTROS. Si lo que se pide no entra en lo disponible, vender igual
 *    exige la autorización de un supervisor (permiso stock.autorizar): él mismo tildando «autorizo» o escribiendo su usuario y su clave. Queda registrada.
 *  - La verdad la define el stock, no la reserva: al emitir se bloquean los saldos (FOR UPDATE) y se vuelve a controlar adentro de la transacción, así dos puestos
 *    que emiten a la vez lo último que queda no lo venden los dos sin autorización.
 *
 * DECISIÓN D-51 (Claude, a confirmar por Hernán): ver docs/decisiones-pendientes.md.
 */
final class ReservasService
{
    public const PERMISO = 'stock.autorizar';
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::pdo();
    }

    public static function minutos(): int
    {
        return max(1, min(1440, Configuracion::entero('stock.reserva_minutos', 15)));
    }

    /** El token de una venta en curso (32 hexadecimales) o null. */
    public static function tokenValido(mixed $t): ?string
    {
        return is_string($t) && preg_match('/^[0-9a-f]{32}$/', $t) === 1 ? $t : null;
    }

    /** ¿Hay control de stock para esta venta? (el interruptor de Configuración y el artículo). */
    public static function activo(): bool
    {
        return StockService::activo();
    }

    // ------------------------------------------------------------------ reservar

    /**
     * Reserva lo que lleva una venta en curso (reemplaza lo anterior de ese token) y devuelve el estado de cada artículo.
     *
     * @param array<int,array{articulo:string,cantidad:float|int|string}> $lineas lo que se ve en pantalla (el artículo como se escribió: código, barras o SKU)
     * @return array<int,array<string,mixed>> uno por línea que controla stock: clave (lo escrito), articulo, descripcion, cantidad, saldo, otros[{nombre,cantidad}], disponible, falta, mensaje
     */
    public function sincronizar(string $token, array $lineas, int $usuarioId, string $origen = 'comprobante'): array
    {
        if (!self::activo()) {
            return [];
        }
        $this->limpiar();
        $stock = new StockService($this->pdo);
        $porArticulo = []; // codigo => cantidad
        $claves = [];       // codigo => lo escrito
        foreach ($lineas as $l) {
            $a = $stock->resolverArticulo((string) ($l['articulo'] ?? ''));
            $c = (float) str_replace(',', '.', (string) ($l['cantidad'] ?? 0));
            if ($a === null || (int) $a['controla_stock'] !== 1 || $c <= 0) {
                continue;
            }
            $porArticulo[(string) $a['codigo']] = ($porArticulo[(string) $a['codigo']] ?? 0.0) + $c;
            $claves[(string) $a['codigo']][] = (string) $l['articulo'];
        }
        $this->pdo->beginTransaction();
        try {
            if ($porArticulo === []) {
                $this->pdo->prepare('DELETE FROM stock_reservas WHERE token = ?')->execute([$token]);
            } else {
                $in = implode(', ', array_fill(0, count($porArticulo), '?'));
                $this->pdo->prepare("DELETE FROM stock_reservas WHERE token = ? AND articulo NOT IN ({$in})")->execute([$token, ...array_keys($porArticulo)]);
                $up = $this->pdo->prepare('INSERT INTO stock_reservas (articulo, cantidad, token, usuario_id, origen, creado, vence) VALUES (?, ?, ?, ?, ?, NOW(), NOW() + INTERVAL ? MINUTE)
                    ON DUPLICATE KEY UPDATE cantidad = VALUES(cantidad), vence = VALUES(vence), usuario_id = VALUES(usuario_id)');
                foreach ($porArticulo as $codigo => $cant) {
                    $up->execute([$codigo, round($cant, 3), $token, $usuarioId > 0 ? $usuarioId : null, mb_substr($origen, 0, 12), self::minutos()]);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $estados = $this->estados($porArticulo, $token);
        $out = [];
        foreach ($estados as $codigo => $e) {
            foreach ($claves[$codigo] ?? [$codigo] as $clave) {
                $out[] = ['clave' => $clave] + $e;
            }
        }
        return $out;
    }

    /** Libera todo lo reservado por una venta (se facturó, se vació o se abandonó). */
    public function liberar(string $token): void
    {
        $this->pdo->prepare('DELETE FROM stock_reservas WHERE token = ?')->execute([$token]);
    }

    public function limpiar(): void
    {
        $this->pdo->exec('DELETE FROM stock_reservas WHERE vence < NOW()');
    }

    // ------------------------------------------------------------------ disponibilidad

    /**
     * Estado de cada artículo respecto de lo que pide esta venta: stock del depósito principal, lo reservado por otros (con quién) y lo que falta.
     * @param array<string,float> $porArticulo codigo => cantidad pedida @return array<string,array<string,mixed>> por código
     */
    public function estados(array $porArticulo, ?string $token, bool $bloquear = false): array
    {
        if ($porArticulo === []) {
            return [];
        }
        ksort($porArticulo); // mismo orden de bloqueo en todos los puestos (sin deadlocks)
        $dep = (new StockService($this->pdo))->depositoPrincipal();
        $codigos = array_keys($porArticulo);
        $marcas = implode(', ', array_fill(0, count($codigos), '?'));
        $d = $this->pdo->prepare("SELECT codigo, descripcion FROM articulos WHERE codigo IN ({$marcas})");
        $d->execute($codigos);
        $desc = $d->fetchAll(PDO::FETCH_KEY_PAIR);
        $otros = [];
        $r = $this->pdo->prepare("SELECT r.articulo, r.cantidad, COALESCE(u.nombre, 'otro puesto') AS nombre FROM stock_reservas r LEFT JOIN usuarios u ON u.id = r.usuario_id
            WHERE r.articulo IN ({$marcas}) AND r.vence >= NOW() AND r.token <> ? ORDER BY r.creado");
        $r->execute([...$codigos, $token ?? '']);
        foreach ($r->fetchAll() as $f) {
            $otros[(string) $f['articulo']][] = ['nombre' => (string) $f['nombre'], 'cantidad' => (float) $f['cantidad']];
        }
        $q = $this->pdo->prepare('SELECT saldo FROM stock_saldos WHERE articulo = ? AND deposito_id = ?' . ($bloquear ? ' FOR UPDATE' : ''));
        $out = [];
        foreach ($porArticulo as $codigo => $cant) {
            $q->execute([$codigo, $dep]);
            $saldo = (float) ($q->fetchColumn() ?: 0.0);
            $reservado = array_sum(array_column($otros[$codigo] ?? [], 'cantidad'));
            $disp = round($saldo - $reservado, 3);
            $falta = max(0.0, round($cant - $disp, 3));
            $out[$codigo] = ['articulo' => $codigo, 'descripcion' => (string) ($desc[$codigo] ?? $codigo), 'cantidad' => round($cant, 3), 'saldo' => round($saldo, 3),
                'otros' => $otros[$codigo] ?? [], 'disponible' => $disp, 'falta' => $falta];
            $out[$codigo]['mensaje'] = self::mensaje($out[$codigo]);
        }
        return $out;
    }

    /** «ARTICULO (T-ART): se piden 3 y hay 2 disponibles (stock 3, reservado por Ana 1).» */
    public static function mensaje(array $e): string
    {
        $n = static fn (float $v): string => rtrim(rtrim(number_format($v, 3, ',', '.'), '0'), ',');
        $txt = $e['descripcion'] . ' (' . $e['articulo'] . '): se piden ' . $n($e['cantidad']) . ' y ' . ($e['disponible'] > 0 ? 'hay ' . $n($e['disponible']) . ' disponibles' : 'no hay disponibles');
        $partes = ['stock ' . $n($e['saldo'])];
        foreach ($e['otros'] as $o) {
            $partes[] = 'reservado por ' . $o['nombre'] . ' ' . $n($o['cantidad']);
        }
        return $txt . ' (' . implode(', ', $partes) . ').';
    }

    /**
     * Lo que NO alcanza para una venta (los artículos que controlan stock). Con $bloquear (adentro de la transacción de emitir) deja bloqueados los saldos.
     * @param array<int,array<string,mixed>> $items renglones con articulo y cantidad @return array<int,array<string,mixed>> estados con falta > 0
     */
    public function faltantes(array $items, ?string $token, bool $bloquear = false): array
    {
        if (!self::activo()) {
            return [];
        }
        $acum = [];
        foreach ($items as $it) {
            $a = (string) ($it['articulo'] ?? '');
            if ($a !== '') {
                $acum[$a] = ($acum[$a] ?? 0.0) + (float) $it['cantidad'];
            }
        }
        if ($acum === []) {
            return [];
        }
        $ctrl = $this->pdo->prepare('SELECT controla_stock FROM articulos WHERE codigo = ?');
        foreach (array_keys($acum) as $a) {
            $ctrl->execute([$a]);
            if ((int) $ctrl->fetchColumn() !== 1) {
                unset($acum[$a]); // los servicios y lo que no se cuenta no reservan ni faltan
            }
        }
        return array_values(array_filter($this->estados($acum, $token, $bloquear), static fn (array $e) => $e['falta'] > 0.0004));
    }

    // ------------------------------------------------------------------ autorización

    /** ¿Ese usuario activo tiene permiso para autorizar? (sus permisos son los de su rol; Administrador y Master los tienen todos guardados) */
    public function usuarioPuede(int $usuarioId): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM usuarios u JOIN rol_permisos rp ON rp.rol_id = u.rol_id JOIN permisos p ON p.id = rp.permiso_id WHERE u.id = ? AND u.activo = 1 AND p.codigo = ?');
        $st->execute([$usuarioId, self::PERMISO]);
        return $st->fetchColumn() !== false;
    }

    /**
     * Quién autoriza vender lo que no alcanza: el mismo usuario si tiene el permiso y tilda «autorizo» (autoriza_yo), o un supervisor con su usuario y su clave
     * (autoriza_usuario / autoriza_clave: nunca se guardan ni se muestran). @return array{ok:bool,error:?string,usuario_id?:int,nombre?:string}
     */
    public function autorizar(array $in, int $usuarioId): array
    {
        $nombre = function (int $id): string {
            $st = $this->pdo->prepare('SELECT nombre FROM usuarios WHERE id = ?');
            $st->execute([$id]);
            return (string) $st->fetchColumn();
        };
        if (!empty($in['autoriza_yo']) && $usuarioId > 0) {
            return $this->usuarioPuede($usuarioId)
                ? ['ok' => true, 'error' => null, 'usuario_id' => $usuarioId, 'nombre' => $nombre($usuarioId)]
                : ['ok' => false, 'error' => 'Tu rol no puede autorizar ventas sin stock disponible: pedile a un supervisor que ponga su usuario y su clave.'];
        }
        $u = trim((string) ($in['autoriza_usuario'] ?? ''));
        $c = (string) ($in['autoriza_clave'] ?? '');
        if ($u === '' && $c === '') {
            return ['ok' => false, 'error' => null];
        }
        $r = (new LoginService())->intentar($u, $c, false);
        if (!$r['ok']) {
            return ['ok' => false, 'error' => 'La autorización no es válida: ' . ($r['error'] ?? 'usuario o clave incorrectos') . ' Volvé a probar.'];
        }
        if (!$this->usuarioPuede((int) $r['usuario_id'])) {
            return ['ok' => false, 'error' => 'Ese usuario no tiene permiso para autorizar ventas sin stock disponible.'];
        }
        return ['ok' => true, 'error' => null, 'usuario_id' => (int) $r['usuario_id'], 'nombre' => $nombre((int) $r['usuario_id'])];
    }

    /**
     * Deja asentado lo que se vendió de más y quién lo autorizó (adentro de la transacción de emitir).
     * @param array<int,array<string,mixed>> $faltantes @param array{usuario_id?:int}|null $autorizo null si el comprobante ya lo había autorizado ARCA
     */
    public function registrar(array $faltantes, int $pideUsuarioId, ?array $autorizo, int $subdiarioId): void
    {
        $st = $this->pdo->prepare('INSERT INTO stock_autorizaciones (fecha, articulo, cantidad, disponible, pide_usuario_id, autoriza_usuario_id, subdiario_id, detalle) VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?)');
        foreach ($faltantes as $f) {
            $st->execute([$f['articulo'], $f['falta'], max(0.0, $f['disponible']), $pideUsuarioId > 0 ? $pideUsuarioId : null, $autorizo['usuario_id'] ?? null, $subdiarioId,
                $autorizo === null ? 'Sin autorización: el comprobante ya estaba autorizado por ARCA cuando cambió el stock' : mb_substr($f['mensaje'], 0, 255)]);
        }
    }
}
