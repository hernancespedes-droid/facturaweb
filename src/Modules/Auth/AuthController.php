<?php
declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Session;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;

final class AuthController
{
    public static function loginForm(Request $req, array $params): Response
    {
        if (Auth::usuario() !== null) {
            return Response::redirect(url('/'));
        }
        return Response::html(View::render('auth/login', ['title' => 'Ingresar', 'usuario' => '', 'error' => null]));
    }

    public static function login(Request $req, array $params): Response
    {
        $usuario = trim((string) ($req->post['usuario'] ?? ''));
        $clave = (string) ($req->post['clave'] ?? '');
        $r = ($usuario === '' || $clave === '')
            ? ['ok' => false, 'error' => 'Completá usuario y contraseña.', 'usuario_id' => null]
            : (new LoginService())->intentar($usuario, $clave);

        if (!$r['ok']) {
            return Response::html(
                View::render('auth/login', ['title' => 'Ingresar', 'usuario' => $usuario, 'error' => $r['error']]),
                422
            );
        }

        $volver = Session::get('volver'); // login() regenera la sesión: se conserva a dónde volver
        Auth::login((int) $r['usuario_id']);
        if (is_string($volver)) {
            Session::set('volver', $volver);
        }
        if (($req->post['recordar'] ?? '') === '1') {
            Recordarme::crear((int) $r['usuario_id']);
        }
        return Response::redirect(url(self::destino()));
    }

    public static function logout(Request $req, array $params): Response
    {
        Auth::logout();
        Session::flash('info', 'Saliste del sistema.');
        return Response::redirect(url('/login'));
    }

    public static function claveForm(Request $req, array $params): Response
    {
        return Response::html(View::render('auth/clave', ['title' => 'Cambiar contraseña', 'error' => null]));
    }

    public static function cambiarClave(Request $req, array $params): Response
    {
        $error = (new LoginService())->cambiarClave(
            (int) Auth::id(),
            (string) ($req->post['actual'] ?? ''),
            (string) ($req->post['nueva'] ?? ''),
            (string) ($req->post['confirmacion'] ?? '')
        );
        if ($error !== null) {
            return Response::html(View::render('auth/clave', ['title' => 'Cambiar contraseña', 'error' => $error]), 422);
        }
        Recordarme::olvidarUsuario((int) Auth::id()); // los dispositivos recordados vuelven a pedir la contraseña
        Auth::reset();
        Session::regenerate();
        Session::flash('ok', 'Listo, tu contraseña quedó cambiada.');
        return Response::redirect(url(self::destino()));
    }

    /** A dónde ir después de ingresar: cambiar la clave temporal, la pantalla que se quería abrir o el inicio. */
    private static function destino(): string
    {
        $u = Auth::usuario();
        if ($u !== null && (int) $u['debe_cambiar_clave'] === 1) {
            return '/mi-clave';
        }
        $volver = Session::get('volver');
        $volver = is_string($volver) && preg_match('#^/(?!/)[^\s\\\\]*$#', $volver) === 1 ? $volver : null;
        Session::set('volver', null);
        return $volver ?? '/';
    }
}
