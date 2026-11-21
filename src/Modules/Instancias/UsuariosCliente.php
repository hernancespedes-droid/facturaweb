<?php
declare(strict_types=1);

namespace App\Modules\Instancias;

use App\Core\Db;
use App\Modules\Usuarios\UsuariosService;
use PDO;

/**
 * Usuarios de la base de un cliente, vistos desde el master (Sistema → Instalaciones de clientes → Administrar → Usuarios).
 * Sirve para atender el ticket de «perdí la contraseña» sin entrar a la instalación del cliente:
 *   - listar(): todos los usuarios de su base (también los Master), con estado, bloqueo y último ingreso.
 *   - resetear(): contraseña temporal + debe_cambiar_clave = 1 (al entrar el cliente la tiene que cambiar, Guard lo obliga), desbloquea,
 *     borra sus «recordarme» y, si se pide, lo reactiva. La contraseña se devuelve una sola vez y no se guarda en ningún lado (D-142).
 *   - Cada reseteo queda en instancias_resets de la base del master (quién, a quién, cuándo, IP, ticket) y, si vino por un ticket,
 *     como nota interna del ticket, sin la contraseña (D-143).
 * Solo toca columnas que existen desde la migración 0001: la carpeta de clientes puede estar una versión atrás.
 */
final class UsuariosCliente
{
    /** Para pruebas: conexión a la base del cliente en lugar de la de su config. */
    public static ?PDO $conexion = null;

    private static function pdo(string $host): PDO
    {
        return self::$conexion ?? InstanciasService::conectar(InstanciasService::config($host)['db'] ?? []);
    }

    /** @return array<int,array<string,mixed>> */
    public static function listar(string $host): array
    {
        return self::pdo($host)->query(
            'SELECT u.id, u.usuario, u.nombre, u.email, u.activo, u.debe_cambiar_clave, u.ultimo_login, u.intentos_fallidos,
                    (u.bloqueado_hasta IS NOT NULL AND u.bloqueado_hasta > NOW()) AS bloqueado, r.nombre AS rol_nombre, r.es_master, r.es_admin
               FROM usuarios u JOIN roles r ON r.id = u.rol_id
              ORDER BY r.es_master DESC, r.es_admin DESC, u.activo DESC, u.usuario'
        )->fetchAll();
    }

    /**
     * @param array{id:int,usuario:string} $por el Master del master que lo hace
     * @return array{ok:bool,error?:string,clave?:string,usuario?:string,nombre?:string,reactivado?:bool,aviso?:?string}
     */
    public static function resetear(string $host, int $usuarioId, array $por, bool $reactivar, string $motivo, ?int $ticketId, string $ip): array
    {
        $motivo = mb_substr(trim($motivo), 0, 200);
        if ($ticketId !== null) {
            $st = Db::pdo()->prepare('SELECT instancia FROM soporte_tickets WHERE id = ?');
            $st->execute([$ticketId]);
            $de = $st->fetchColumn();
            if ($de === false) {
                return ['ok' => false, 'error' => 'No existe el ticket ' . $ticketId . '.'];
            }
            if ((string) $de !== $host) {
                return ['ok' => false, 'error' => 'El ticket ' . $ticketId . ' es de otra instalación (' . $de . ').'];
            }
        }
        $pdo = self::pdo($host);
        $st = $pdo->prepare('SELECT id, usuario, nombre, activo FROM usuarios WHERE id = ?');
        $st->execute([$usuarioId]);
        $u = $st->fetch();
        if ($u === false) {
            return ['ok' => false, 'error' => 'Ese usuario no existe en ' . $host . '.'];
        }
        $reactivado = $reactivar && (int) $u['activo'] !== 1;
        $clave = UsuariosService::claveTemporal();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE usuarios SET password_hash = ?, debe_cambiar_clave = 1, intentos_fallidos = 0, bloqueado_hasta = NULL'
                . ($reactivado ? ', activo = 1' : '') . ' WHERE id = ?')->execute([password_hash($clave, PASSWORD_DEFAULT), $usuarioId]);
            // Las sesiones abiertas quedan obligadas a cambiarla (Guard lee debe_cambiar_clave en cada pedido); «recordarme» se corta.
            $pdo->prepare('DELETE FROM recordarme WHERE usuario_id = ?')->execute([$usuarioId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        Db::pdo()->prepare('INSERT INTO instancias_resets (instancia, usuario_id, usuario, usuario_nombre, reactivado, ticket_id, motivo, por_id, por, ip, creado_en)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$host, $usuarioId, (string) $u['usuario'], mb_substr((string) $u['nombre'], 0, 80), $reactivado ? 1 : 0, $ticketId, $motivo,
                $por['id'] ?: null, mb_substr($por['usuario'], 0, 40), mb_substr($ip, 0, 45)]);

        $aviso = null;
        if ($ticketId !== null) {
            try {
                (new \App\Modules\Soporte\SoporteService())->responderOys($ticketId, $por['usuario'], ['interno' => '1',
                    'texto' => 'Se reseteó la contraseña del usuario «' . $u['usuario'] . '» (' . $u['nombre'] . ')' . ($reactivado ? ' y se lo reactivó' : '')
                        . '. La contraseña temporal se le pasa aparte; al entrar la tiene que cambiar.' . ($motivo !== '' ? ' Motivo: ' . $motivo : '')]);
            } catch (\Throwable $e) {
                \App\App::registrar($e);
                $aviso = 'No se pudo anotar en el ticket ' . $ticketId . ' (quedó igual en el registro).';
            }
        }
        return ['ok' => true, 'clave' => $clave, 'usuario' => (string) $u['usuario'], 'nombre' => (string) $u['nombre'], 'reactivado' => $reactivado, 'aviso' => $aviso];
    }

    /** Últimos reseteos de una instalación (los más nuevos primero). @return array<int,array<string,mixed>> */
    public static function historial(string $host, int $limite = 50): array
    {
        $st = Db::pdo()->prepare('SELECT * FROM instancias_resets WHERE instancia = ? ORDER BY id DESC LIMIT ' . max(1, $limite));
        $st->execute([$host]);
        return $st->fetchAll();
    }

    /** Tickets abiertos de la instalación (para elegir a cuál se anota el reseteo). @return array<int,array{id:int,asunto:string}> */
    public static function ticketsAbiertos(string $host): array
    {
        $st = Db::pdo()->prepare("SELECT id, asunto FROM soporte_tickets WHERE instancia = ? AND estado NOT IN ('resuelto', 'cerrado') ORDER BY id DESC LIMIT 30");
        $st->execute([$host]);
        return $st->fetchAll();
    }
}
