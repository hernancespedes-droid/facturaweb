<?php
declare(strict_types=1);

namespace App\Core;

use App\App;

/**
 * Sesión del usuario. En la web usa la sesión de PHP (cookie HttpOnly, SameSite=Lax);
 * en la línea de comandos y en las pruebas guarda los datos en memoria.
 */
final class Session
{
    /** @var array<string,mixed> */
    private static array $memoria = [];
    private static bool $web = false;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (str_contains((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/api/v1/')) {
            return; // la API del agente no usa sesión
        }
        $minutos = (int) Config::get('auth.inactividad_minutos', 240);
        // Por defecto en la base (tabla sesiones); 'archivos' usa storage/sessions.
        // /instalar corre con la base vacía (todavía no existe la tabla sesiones): ahí las sesiones van en archivos.
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (Config::get('auth.sesiones', 'base') === 'archivos' || str_ends_with(rtrim($path, '/'), '/instalar')) {
            $dir = App::root() . '/storage/sessions';
            if (is_dir($dir) && is_writable($dir)) {
                session_save_path($dir);
            }
        } else {
            session_set_save_handler(new DbSessionHandler(Db::pdo()), true);
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) ($minutos * 60));
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        session_name('fw_sesion');
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => ((string) Config::get('app.base_path', '')) . '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        self::$web = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$web ? ($_SESSION[$key] ?? $default) : (self::$memoria[$key] ?? $default);
    }

    public static function set(string $key, mixed $value): void
    {
        if (self::$web) {
            $_SESSION[$key] = $value;
        } else {
            self::$memoria[$key] = $value;
        }
    }

    public static function forget(string $key): void
    {
        if (self::$web) {
            unset($_SESSION[$key]);
        } else {
            unset(self::$memoria[$key]);
        }
    }

    /** Nuevo id de sesión (al iniciar sesión, contra fijación de sesión). */
    public static function regenerate(): void
    {
        if (self::$web) {
            session_regenerate_id(true);
        }
    }

    /** Vacía la sesión (al salir). */
    public static function clear(): void
    {
        if (self::$web) {
            $_SESSION = [];
            session_regenerate_id(true);
        } else {
            self::$memoria = [];
        }
    }

    /** Mensaje para mostrar una sola vez en la próxima pantalla. */
    public static function flash(string $tipo, string $texto): void
    {
        $f = (array) self::get('_flash', []);
        $f[] = ['tipo' => $tipo, 'texto' => $texto];
        self::set('_flash', $f);
    }

    /** @return array<int,array{tipo:string,texto:string}> */
    public static function takeFlash(): array
    {
        $f = (array) self::get('_flash', []);
        self::forget('_flash');
        return $f;
    }
}
