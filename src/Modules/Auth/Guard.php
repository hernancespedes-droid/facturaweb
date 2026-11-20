<?php
declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Session;
use App\Http\Request;
use App\Http\Response;

/**
 * Envoltorios de rutas que verifican en el servidor quién puede entrar.
 *   Guard::sesion($h)            logueado (aunque tenga que cambiar la contraseña temporal)
 *   Guard::auth($h)              logueado y con la contraseña al día
 *   Guard::permiso('codigo', $h) lo anterior más el permiso en su rol
 */
final class Guard
{
    public static function sesion(callable $handler): \Closure
    {
        return static function (Request $req, array $params) use ($handler): Response {
            if (Auth::usuario() === null) {
                return self::aLogin($req);
            }
            return $handler($req, $params);
        };
    }

    public static function auth(callable $handler): \Closure
    {
        return static function (Request $req, array $params) use ($handler): Response {
            $u = Auth::usuario();
            if ($u === null) {
                return self::aLogin($req);
            }
            if ((int) $u['debe_cambiar_clave'] === 1) {
                return Response::redirect(url('/mi-clave'));
            }
            // Suscripción bloqueada desde el sitio master: solo entra el Master de O&S.
            if (\App\Modules\Instancias\Suscripcion::bloqueada() && !Auth::esMaster()) {
                return new Response(403, \App\Modules\Instancias\Suscripcion::paginaBloqueada(), ['Content-Type' => 'text/html; charset=utf-8']);
            }
            return $handler($req, $params);
        };
    }

    public static function permiso(string $codigo, callable $handler): \Closure
    {
        return self::auth(static function (Request $req, array $params) use ($codigo, $handler): Response {
            if (!Auth::puede($codigo)) {
                return new Response(403);
            }
            return $handler($req, $params);
        });
    }

    /** Logueado y con AL MENOS UNO de esos permisos (pantallas que sirven a dos módulos). */
    public static function algun(array $codigos, callable $handler): \Closure
    {
        return self::auth(static function (Request $req, array $params) use ($codigos, $handler): Response {
            foreach ($codigos as $c) {
                if (Auth::puede($c)) {
                    return $handler($req, $params);
                }
            }
            return new Response(403);
        });
    }

    private static function aLogin(Request $req): Response
    {
        // Después de ingresar vuelve a donde quería ir. Solo pantallas (GET).
        if ($req->method === 'GET') {
            Session::set('volver', $req->path . ($req->query !== [] ? '?' . http_build_query($req->query) : ''));
        }
        return Response::redirect(url('/login'));
    }
}
