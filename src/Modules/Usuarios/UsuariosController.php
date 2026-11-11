<?php
declare(strict_types=1);

namespace App\Modules\Usuarios;

use App\Core\Session;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;

/** Rutas protegidas con Guard::permiso('usuarios.administrar') (ver routes.php). */
final class UsuariosController
{
    public static function index(Request $req, array $params): Response
    {
        $svc = UsuariosService::desdeSesion();
        $q = trim((string) ($req->query['q'] ?? ''));
        return Response::html(View::render('usuarios/index', [
            'title' => 'Usuarios',
            'nav' => 'usuarios',
            'usuarios' => $svc->listar($q),
            'q' => $q,
            'actorId' => $svc->actorId(),
        ]));
    }

    public static function nuevo(Request $req, array $params): Response
    {
        return self::form(UsuariosService::desdeSesion(), null, ['usuario' => '', 'nombre' => '', 'email' => '', 'rol_id' => ''], []);
    }

    public static function crear(Request $req, array $params): Response
    {
        $svc = UsuariosService::desdeSesion();
        $r = $svc->crear($req->post);
        if (!$r['ok']) {
            return self::form($svc, null, $req->post, $r['errores'], 422);
        }
        Session::flash('ok', 'Usuario creado.');
        self::flashClave((string) ($req->post['usuario'] ?? ''), (string) $r['clave']);
        return Response::redirect(url('/usuarios/' . $r['id']));
    }

    public static function editar(Request $req, array $params): Response
    {
        $svc = UsuariosService::desdeSesion();
        $u = $svc->obtener((int) $params['id']);
        if ($u === null) {
            return new Response(404);
        }
        return self::form($svc, $u, $u, []);
    }

    public static function actualizar(Request $req, array $params): Response
    {
        $svc = UsuariosService::desdeSesion();
        $id = (int) $params['id'];
        $u = $svc->obtener($id);
        if ($u === null) {
            return new Response(404);
        }
        $errores = $svc->actualizar($id, $req->post);
        if ($errores !== []) {
            return self::form($svc, $u, $req->post + ['usuario' => $u['usuario']], $errores, 422);
        }
        Session::flash('ok', 'Cambios guardados.');
        return Response::redirect(url('/usuarios/' . $id));
    }

    public static function activo(Request $req, array $params): Response
    {
        $svc = UsuariosService::desdeSesion();
        $id = (int) $params['id'];
        if (!$svc->visible($id)) {
            return new Response(404);
        }
        $activo = ($req->post['activo'] ?? '') === '1';
        $error = $svc->cambiarActivo($id, $activo);
        Session::flash($error === null ? 'ok' : 'error', $error ?? ($activo ? 'Usuario activado.' : 'Usuario desactivado: ya no puede ingresar.'));
        return Response::redirect(url('/usuarios/' . $id));
    }

    public static function resetClave(Request $req, array $params): Response
    {
        $svc = UsuariosService::desdeSesion();
        $id = (int) $params['id'];
        $u = $svc->obtener($id);
        if ($u === null) {
            return new Response(404);
        }
        $clave = $svc->resetearClave($id);
        if ($clave === null) {
            Session::flash('error', 'Tu propia contraseña la cambiás desde "Mi contraseña".');
        } else {
            self::flashClave((string) $u['usuario'], $clave);
        }
        return Response::redirect(url('/usuarios/' . $id));
    }

    public static function desbloquear(Request $req, array $params): Response
    {
        $svc = UsuariosService::desdeSesion();
        $id = (int) $params['id'];
        if (!$svc->desbloquear($id)) {
            return new Response(404);
        }
        Session::flash('ok', 'Usuario desbloqueado.');
        return Response::redirect(url('/usuarios/' . $id));
    }

    private static function flashClave(string $usuario, string $clave): void
    {
        Session::flash('clave', 'Contraseña temporal de ' . $usuario . ': ' . $clave
            . ' — pasásela por un medio seguro. Se le va a pedir que la cambie al entrar. No se vuelve a mostrar.');
    }

    /**
     * @param array<string,mixed>|null $u usuario existente (null = alta)
     * @param array<string,mixed> $datos
     * @param array<string,string> $errores
     */
    private static function form(UsuariosService $svc, ?array $u, array $datos, array $errores, int $status = 200): Response
    {
        return Response::html(View::render('usuarios/form', [
            'title' => $u === null ? 'Nuevo usuario' : 'Usuario ' . $u['usuario'],
            'nav' => 'usuarios',
            'u' => $u,
            'datos' => $datos,
            'errores' => $errores,
            'roles' => $svc->rolesAsignables(),
            'propio' => $u !== null && (int) $u['id'] === $svc->actorId(),
        ]), $status);
    }
}
