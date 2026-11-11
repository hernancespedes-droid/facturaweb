<?php
declare(strict_types=1);

namespace App\Modules\Usuarios;

use App\Core\Db;
use App\Modules\Auth\Auth;
use App\Modules\Auth\Recordarme;

/**
 * ABM de usuarios con las reglas de quién puede ver y tocar a quién:
 *  - Solo un Master (rol con es_master) ve, edita o asigna usuarios con rol Master.
 *  - Nadie se cambia su propio rol ni se desactiva a sí mismo (para no quedar afuera).
 */
final class UsuariosService
{
    public function __construct(
        private int $actorId,
        private bool $actorEsMaster
    ) {
    }

    public static function desdeSesion(): self
    {
        return new self((int) Auth::id(), Auth::esMaster());
    }

    public function actorId(): int
    {
        return $this->actorId;
    }

    /** @return array<int,array<string,mixed>> rol_id => rol que el actor puede asignar */
    public function rolesAsignables(): array
    {
        $sql = 'SELECT id, codigo, nombre, es_master FROM roles'
            . ($this->actorEsMaster ? '' : ' WHERE es_master = 0')
            . ' ORDER BY es_master DESC, es_admin DESC, id';
        $out = [];
        foreach (Db::pdo()->query($sql)->fetchAll() as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    /** Condición SQL de los usuarios visibles para el actor (u = usuarios, r = su rol). */
    private function filtroVisibles(): string
    {
        return $this->actorEsMaster ? '1=1' : 'r.es_master = 0';
    }

    /** @return array<int,array<string,mixed>> */
    public function listar(string $buscar = ''): array
    {
        $sql = 'SELECT u.id, u.usuario, u.nombre, u.email, u.activo, u.ultimo_login, u.debe_cambiar_clave, u.usuario_vfp,
                       r.nombre AS rol_nombre, (u.bloqueado_hasta IS NOT NULL AND u.bloqueado_hasta > NOW()) AS bloqueado
                  FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE ' . $this->filtroVisibles();
        $params = [];
        $buscar = trim($buscar);
        if ($buscar !== '') {
            $sql .= ' AND (u.usuario LIKE ? OR u.nombre LIKE ? OR u.email LIKE ?)';
            $like = '%' . addcslashes($buscar, '%_\\') . '%';
            $params = [$like, $like, $like];
        }
        $st = Db::pdo()->prepare($sql . ' ORDER BY u.activo DESC, u.nombre');
        $st->execute($params);
        return $st->fetchAll();
    }

    public function visible(int $usuarioId): bool
    {
        $st = Db::pdo()->prepare('SELECT 1 FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ? AND ' . $this->filtroVisibles());
        $st->execute([$usuarioId]);
        return $st->fetchColumn() !== false;
    }

    /** @return array<string,mixed>|null null si no existe o el actor no lo puede ver */
    public function obtener(int $usuarioId): ?array
    {
        if (!$this->visible($usuarioId)) {
            return null;
        }
        $st = Db::pdo()->prepare(
            'SELECT u.id, u.usuario, u.nombre, u.email, u.activo, u.ultimo_login, u.creado_en, u.debe_cambiar_clave,
                    u.intentos_fallidos, u.usuario_vfp, u.rol_id, r.nombre AS rol_nombre,
                    (u.bloqueado_hasta IS NOT NULL AND u.bloqueado_hasta > NOW()) AS bloqueado
               FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?'
        );
        $st->execute([$usuarioId]);
        $u = $st->fetch();
        return $u === false ? null : $u;
    }

    /**
     * @param array<string,mixed> $data usuario, nombre, email, rol_id
     * @return array{ok:bool,errores:array<string,string>,id:?int,clave:?string}
     */
    public function crear(array $data): array
    {
        $usuario = mb_strtolower(trim((string) ($data['usuario'] ?? '')));
        $errores = $this->validarDatos($data);
        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $usuario)) {
            $errores['usuario'] = 'Usá entre 3 y 40 letras sin acentos, números, punto, guion o guion bajo.';
        }
        $rolId = (int) ($data['rol_id'] ?? 0);
        if (!isset($this->rolesAsignables()[$rolId])) {
            $errores['rol_id'] = 'Elegí un rol.';
        }
        if ($errores !== []) {
            return ['ok' => false, 'errores' => $errores, 'id' => null, 'clave' => null];
        }

        $clave = self::claveTemporal();
        try {
            Db::pdo()->prepare(
                'INSERT INTO usuarios (usuario, nombre, email, password_hash, rol_id, debe_cambiar_clave) VALUES (?, ?, ?, ?, ?, 1)'
            )->execute([$usuario, trim((string) $data['nombre']), self::emailONull($data), password_hash($clave, PASSWORD_DEFAULT), $rolId]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                $campo = str_contains($e->getMessage(), 'uq_usuarios_email') ? 'email' : 'usuario';
                return ['ok' => false, 'errores' => [$campo => $campo === 'email'
                    ? 'Ese email ya lo usa otro usuario.' : 'Ese nombre de usuario ya existe.'], 'id' => null, 'clave' => null];
            }
            throw $e;
        }
        return ['ok' => true, 'errores' => [], 'id' => (int) Db::pdo()->lastInsertId(), 'clave' => $clave];
    }

    /**
     * @param array<string,mixed> $data nombre, email, rol_id
     * @return array<string,string> errores
     */
    public function actualizar(int $usuarioId, array $data): array
    {
        if (!$this->visible($usuarioId)) {
            return ['general' => 'No podés editar ese usuario.'];
        }
        $errores = $this->validarDatos($data);
        $propio = $usuarioId === $this->actorId;
        $rolId = (int) ($data['rol_id'] ?? 0);
        if (!$propio && !isset($this->rolesAsignables()[$rolId])) {
            $errores['rol_id'] = 'No podés asignar ese rol.';
        }
        if ($errores !== []) {
            return $errores;
        }
        try {
            Db::pdo()->prepare('UPDATE usuarios SET nombre = ?, email = ? WHERE id = ?')
                ->execute([trim((string) $data['nombre']), self::emailONull($data), $usuarioId]);
            if (!$propio) {
                // Su propio rol lo cambia otro administrador.
                Db::pdo()->prepare('UPDATE usuarios SET rol_id = ? WHERE id = ?')->execute([$rolId, $usuarioId]);
            }
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['email' => 'Ese email ya lo usa otro usuario.'];
            }
            throw $e;
        }
        return [];
    }

    public function cambiarActivo(int $usuarioId, bool $activo): ?string
    {
        if (!$this->visible($usuarioId)) {
            return 'No podés modificar ese usuario.';
        }
        if ($usuarioId === $this->actorId && !$activo) {
            return 'No podés desactivar tu propio usuario.';
        }
        Db::pdo()->prepare('UPDATE usuarios SET activo = ? WHERE id = ?')->execute([$activo ? 1 : 0, $usuarioId]);
        if (!$activo) {
            Recordarme::olvidarUsuario($usuarioId);
        }
        return null;
    }

    /** Genera una contraseña temporal (se muestra una sola vez) y desbloquea. Null si no puede. */
    public function resetearClave(int $usuarioId): ?string
    {
        if (!$this->visible($usuarioId) || $usuarioId === $this->actorId) {
            return null;
        }
        $clave = self::claveTemporal();
        Db::pdo()->prepare(
            'UPDATE usuarios SET password_hash = ?, debe_cambiar_clave = 1, intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?'
        )->execute([password_hash($clave, PASSWORD_DEFAULT), $usuarioId]);
        Recordarme::olvidarUsuario($usuarioId);
        return $clave;
    }

    public function desbloquear(int $usuarioId): bool
    {
        if (!$this->visible($usuarioId)) {
            return false;
        }
        Db::pdo()->prepare('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?')->execute([$usuarioId]);
        return true;
    }

    /** @return array<string,string> */
    private function validarDatos(array $data): array
    {
        $errores = [];
        $nombre = trim((string) ($data['nombre'] ?? ''));
        if ($nombre === '' || mb_strlen($nombre) > 80) {
            $errores['nombre'] = 'Completá el nombre (hasta 80 caracteres).';
        }
        $email = trim((string) ($data['email'] ?? ''));
        if ($email !== '' && (mb_strlen($email) > 120 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errores['email'] = 'El email no es válido.';
        }
        return $errores;
    }

    private static function emailONull(array $data): ?string
    {
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        return $email === '' ? null : $email;
    }

    /** Contraseña temporal legible (sin 0/O ni 1/l/I). */
    public static function claveTemporal(): string
    {
        $abc = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 12; $i++) {
            $out .= $abc[random_int(0, strlen($abc) - 1)];
        }
        return $out;
    }
}
