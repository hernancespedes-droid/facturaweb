<?php
declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Config;
use App\Core\Db;
use App\Core\Session;

/**
 * "Recordarme en este dispositivo": una cookie HttpOnly con selector + validador (al azar). En la base queda el
 * selector y el sha256 del validador, nunca el validador. Cada uso lo cambia (si alguien copió la cookie vieja, deja
 * de servir). Salir, cambiar la contraseña o desactivar el usuario la invalidan.
 */
final class Recordarme
{
    public const COOKIE = 'fw_recordar';
    public const DIAS = 30;

    /** Crea la ficha para el usuario y deja la cookie. Devuelve el valor de la cookie (para las pruebas). */
    public static function crear(int $usuarioId): string
    {
        $selector = bin2hex(random_bytes(12));
        $validador = bin2hex(random_bytes(32));
        Db::pdo()->prepare('INSERT INTO recordarme (usuario_id, selector, validador, agente, vence_en) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . self::DIAS . ' DAY))')
            ->execute([$usuarioId, $selector, hash('sha256', $validador), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)]);
        $valor = $selector . '.' . $validador;
        self::cookie($valor, time() + self::DIAS * 86400);
        return $valor;
    }

    /** Si hay una cookie válida, inicia la sesión de su usuario y devuelve su id (null si no). */
    public static function restaurar(): ?int
    {
        $valor = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (!preg_match('/^([0-9a-f]{24})\.([0-9a-f]{64})$/', $valor, $m)) {
            return null;
        }
        try {
            $st = Db::pdo()->prepare('SELECT r.id, r.usuario_id, r.validador FROM recordarme r JOIN usuarios u ON u.id = r.usuario_id
                                       WHERE r.selector = ? AND r.vence_en > NOW() AND u.activo = 1');
            $st->execute([$m[1]]);
            $r = $st->fetch();
        } catch (\PDOException) {
            return null; // sin la tabla (antes de migrar)
        }
        if ($r === false || !hash_equals((string) $r['validador'], hash('sha256', $m[2]))) {
            self::olvidar();
            return null;
        }
        // Validador nuevo en cada uso.
        $nuevo = bin2hex(random_bytes(32));
        Db::pdo()->prepare('UPDATE recordarme SET validador = ?, usado_en = NOW(), vence_en = DATE_ADD(NOW(), INTERVAL ' . self::DIAS . ' DAY) WHERE id = ?')
            ->execute([hash('sha256', $nuevo), $r['id']]);
        self::cookie($m[1] . '.' . $nuevo, time() + self::DIAS * 86400);
        $_COOKIE[self::COOKIE] = $m[1] . '.' . $nuevo;
        return (int) $r['usuario_id'];
    }

    /** Borra la ficha de este dispositivo (al salir). */
    public static function olvidar(): void
    {
        $valor = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (preg_match('/^([0-9a-f]{24})\./', $valor, $m)) {
            try {
                Db::pdo()->prepare('DELETE FROM recordarme WHERE selector = ?')->execute([$m[1]]);
            } catch (\PDOException) {
                // sin la tabla: nada que borrar
            }
        }
        unset($_COOKIE[self::COOKIE]);
        self::cookie('', time() - 3600);
    }

    /** Borra todas las fichas del usuario (cambió la contraseña o lo desactivaron). */
    public static function olvidarUsuario(int $usuarioId): void
    {
        try {
            Db::pdo()->prepare('DELETE FROM recordarme WHERE usuario_id = ?')->execute([$usuarioId]);
        } catch (\PDOException) {
            // sin la tabla
        }
    }

    private static function cookie(string $valor, int $vence): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return; // pruebas
        }
        setcookie(self::COOKIE, $valor, [
            'expires'  => $vence,
            'path'     => ((string) Config::get('app.base_path', '')) . '/',
            'secure'   => ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
