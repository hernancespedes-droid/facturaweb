<?php
declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Config;
use App\Core\Db;
use App\Core\Session;

/**
 * Usuario logueado, su rol y los permisos de ese rol. Una instalación por cliente: no hay empresas, cada
 * usuario tiene un solo rol (usuarios.rol_id). Se vuelve a leer de la base en cada pedido: si al usuario
 * lo desactivan o le cambian el rol, rige desde el próximo clic.
 */
final class Auth
{
    /** @var array{usuario:?array,rol:?array,permisos:array<string,bool>}|null */
    private static ?array $ctx = null;

    /** Olvida lo leído (la App lo llama al empezar cada pedido). */
    public static function reset(): void
    {
        self::$ctx = null;
    }

    public static function login(int $usuarioId): void
    {
        Session::regenerate();
        Session::set('uid', $usuarioId);
        Session::set('ultimo_uso', time());
        self::reset();
    }

    public static function logout(): void
    {
        Recordarme::olvidar();
        Session::clear();
        self::reset();
    }

    /** @return array<string,mixed>|null */
    public static function usuario(): ?array
    {
        return self::ctx()['usuario'];
    }

    /** @return array<string,mixed>|null */
    public static function rol(): ?array
    {
        return self::ctx()['rol'];
    }

    public static function id(): ?int
    {
        $u = self::usuario();
        return $u === null ? null : (int) $u['id'];
    }

    public static function puede(string $codigo): bool
    {
        return isset(self::ctx()['permisos'][$codigo]);
    }

    /** Rol con la marca "Es administrador". */
    public static function esAdmin(): bool
    {
        return (int) (self::rol()['es_admin'] ?? 0) === 1;
    }

    /** Rol Master (O&S; no lo puede asignar el cliente). */
    public static function esMaster(): bool
    {
        return (int) (self::rol()['es_master'] ?? 0) === 1;
    }

    /** @return array{usuario:?array,rol:?array,permisos:array<string,bool>} */
    private static function ctx(): array
    {
        if (self::$ctx !== null) {
            return self::$ctx;
        }
        $vacio = ['usuario' => null, 'rol' => null, 'permisos' => []];
        $uid = Session::get('uid');
        if (!is_int($uid)) {
            // "Recordarme en este dispositivo": la cookie vuelve a abrir la sesión.
            $uid = Recordarme::restaurar();
            if ($uid === null) {
                return self::$ctx = $vacio;
            }
            self::login($uid);
        }

        // Vence por inactividad.
        $limite = (int) Config::get('auth.inactividad_minutos', 240) * 60;
        $ultimo = (int) Session::get('ultimo_uso', 0);
        if ($ultimo > 0 && time() - $ultimo > $limite) {
            self::logout();
            Session::flash('info', 'La sesión se cerró por inactividad. Volvé a ingresar.');
            return self::$ctx = $vacio;
        }
        Session::set('ultimo_uso', time());

        $pdo = Db::pdo();
        $st = $pdo->prepare(
            'SELECT u.id, u.usuario, u.nombre, u.email, u.activo, u.debe_cambiar_clave,
                    r.id AS rol_id, r.codigo AS rol_codigo, r.nombre AS rol_nombre, r.es_admin, r.es_master
               FROM usuarios u JOIN roles r ON r.id = u.rol_id
              WHERE u.id = ?'
        );
        $st->execute([$uid]);
        $u = $st->fetch();
        if ($u === false || (int) $u['activo'] !== 1) {
            self::logout();
            return self::$ctx = $vacio;
        }
        $rol = ['id' => (int) $u['rol_id'], 'codigo' => $u['rol_codigo'], 'nombre' => $u['rol_nombre'],
            'es_admin' => (int) $u['es_admin'], 'es_master' => (int) $u['es_master']];
        $st = $pdo->prepare('SELECT p.codigo FROM rol_permisos rp JOIN permisos p ON p.id = rp.permiso_id WHERE rp.rol_id = ?');
        $st->execute([$rol['id']]);
        $permisos = [];
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $c) {
            $permisos[(string) $c] = true;
        }
        $usuario = array_intersect_key($u, array_flip(['id', 'usuario', 'nombre', 'email', 'activo', 'debe_cambiar_clave']));
        return self::$ctx = ['usuario' => $usuario, 'rol' => $rol, 'permisos' => $permisos];
    }
}
