<?php
declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Db;

/** Verificación de usuario y contraseña con bloqueo temporal por intentos fallidos. */
final class LoginService
{
    public const MAX_INTENTOS = 5;
    public const BLOQUEO_MINUTOS = 15;
    public const MIN_LARGO_CLAVE = 8;

    private const HASH_FICTICIO = '$2y$10$R.800a.Vm.W8rvXunl0vy.ZkSEc7FYDgVu03ntUpUVK2nVxMxRTDS';
    private const ERROR_GENERICO = 'Usuario o contraseña incorrectos.';

    /**
     * Con $ingreso = false solo verifica la contraseña (con el mismo bloqueo por intentos) sin marcar un ingreso:
     * para autorizar una operación sin iniciar sesión.
     *
     * @return array{ok:bool,error:?string,usuario_id:?int}
     */
    public function intentar(string $usuario, string $clave, bool $ingreso = true): array
    {
        $pdo = Db::pdo();
        $usuario = mb_strtolower(trim($usuario));
        $st = $pdo->prepare(
            'SELECT id, password_hash, activo, intentos_fallidos,
                    bloqueado_hasta, (bloqueado_hasta IS NOT NULL AND bloqueado_hasta > NOW()) AS bloqueado,
                    GREATEST(1, CEIL(TIMESTAMPDIFF(SECOND, NOW(), bloqueado_hasta) / 60)) AS minutos
               FROM usuarios WHERE usuario = ?'
        );
        $st->execute([$usuario]);
        $u = $st->fetch();

        if ($u === false) {
            // Mismo costo que con un usuario real, para no delatar cuáles existen.
            password_verify($clave, self::HASH_FICTICIO);
            return ['ok' => false, 'error' => self::ERROR_GENERICO, 'usuario_id' => null];
        }
        $id = (int) $u['id'];

        if ((int) $u['bloqueado'] === 1) {
            return [
                'ok' => false,
                'error' => 'El usuario quedó bloqueado por varios intentos fallidos. Probá de nuevo en '
                    . (int) $u['minutos'] . ' minuto' . ((int) $u['minutos'] === 1 ? '' : 's')
                    . ' o pedile al administrador que lo desbloquee.',
                'usuario_id' => null,
            ];
        }
        if ($u['bloqueado_hasta'] !== null) {
            // El bloqueo ya venció: arranca de cero.
            $pdo->prepare('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?')->execute([$id]);
        }

        if (!password_verify($clave, (string) $u['password_hash'])) {
            // Incremento atómico: dos intentos simultáneos no se pisan. MySQL evalúa las
            // asignaciones de izquierda a derecha, así que el IF ya ve el valor nuevo.
            $pdo->prepare(
                'UPDATE usuarios
                    SET intentos_fallidos = LEAST(intentos_fallidos + 1, 255),
                        bloqueado_hasta = IF(intentos_fallidos >= ?, NOW() + INTERVAL ? MINUTE, bloqueado_hasta)
                  WHERE id = ?'
            )->execute([self::MAX_INTENTOS, self::BLOQUEO_MINUTOS, $id]);
            return ['ok' => false, 'error' => self::ERROR_GENERICO, 'usuario_id' => null];
        }

        if ((int) $u['activo'] !== 1) {
            return ['ok' => false, 'error' => 'Tu usuario está desactivado. Consultá con el administrador.', 'usuario_id' => null];
        }

        $set = 'intentos_fallidos = 0, bloqueado_hasta = NULL' . ($ingreso ? ', ultimo_login = NOW()' : '');
        $params = [];
        if (password_needs_rehash((string) $u['password_hash'], PASSWORD_DEFAULT)) {
            $set .= ', password_hash = ?';
            $params[] = password_hash($clave, PASSWORD_DEFAULT);
        }
        $params[] = $id;
        $pdo->prepare("UPDATE usuarios SET {$set} WHERE id = ?")->execute($params);

        return ['ok' => true, 'error' => null, 'usuario_id' => $id];
    }

    /** Devuelve el problema de la contraseña nueva, o null si sirve. */
    public static function validarClaveNueva(string $clave, string $confirmacion, string $usuario = ''): ?string
    {
        if (mb_strlen($clave) < self::MIN_LARGO_CLAVE) {
            return 'La contraseña tiene que tener al menos ' . self::MIN_LARGO_CLAVE . ' caracteres.';
        }
        if (mb_strlen($clave) > 200) {
            return 'La contraseña es demasiado larga.';
        }
        if ($usuario !== '' && mb_strtolower($clave) === mb_strtolower($usuario)) {
            return 'La contraseña no puede ser igual al usuario.';
        }
        if (!hash_equals($clave, $confirmacion)) {
            return 'Las dos contraseñas no coinciden.';
        }
        return null;
    }

    public function cambiarClave(int $usuarioId, string $claveActual, string $nueva, string $confirmacion): ?string
    {
        $st = Db::pdo()->prepare('SELECT usuario, password_hash FROM usuarios WHERE id = ?');
        $st->execute([$usuarioId]);
        $u = $st->fetch();
        if ($u === false || !password_verify($claveActual, (string) $u['password_hash'])) {
            return 'La contraseña actual no es correcta.';
        }
        if (hash_equals($claveActual, $nueva)) {
            return 'La contraseña nueva tiene que ser distinta de la actual.';
        }
        $error = self::validarClaveNueva($nueva, $confirmacion, (string) $u['usuario']);
        if ($error !== null) {
            return $error;
        }
        Db::pdo()->prepare('UPDATE usuarios SET password_hash = ?, debe_cambiar_clave = 0 WHERE id = ?')
            ->execute([password_hash($nueva, PASSWORD_DEFAULT), $usuarioId]);
        return null;
    }
}
