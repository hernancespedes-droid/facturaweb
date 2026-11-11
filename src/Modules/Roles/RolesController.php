<?php
declare(strict_types=1);

namespace App\Modules\Roles;

use App\Core\Session;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;

/** Pantalla de roles. Rutas con Guard::permiso('usuarios.administrar'). */
final class RolesController
{
    public static function index(Request $req, array $params): Response
    {
        return Response::html(View::render('roles/index', [
            'title' => 'Roles',
            'nav' => 'roles',
            'lista' => RolesService::desdeSesion()->listar(),
        ]));
    }

    public static function nuevo(Request $req, array $params): Response
    {
        return self::form(RolesService::desdeSesion(), null, ['nombre' => '', 'es_admin' => 0, 'es_master' => 0, 'permisos' => []], []);
    }

    public static function crear(Request $req, array $params): Response
    {
        $svc = RolesService::desdeSesion();
        $r = $svc->guardar(null, $req->post);
        if (!$r['ok']) {
            return self::form($svc, null, self::datosPost($req), $r['errores'], 422);
        }
        Session::flash('ok', 'Rol creado.');
        return Response::redirect(url('/roles'));
    }

    public static function editar(Request $req, array $params): Response
    {
        $svc = RolesService::desdeSesion();
        $r = $svc->obtener((int) $params['id']);
        return $r === null ? new Response(404) : self::form($svc, $r, $r, []);
    }

    public static function actualizar(Request $req, array $params): Response
    {
        $svc = RolesService::desdeSesion();
        $rol = $svc->obtener((int) $params['id']);
        if ($rol === null) {
            return new Response(404);
        }
        $r = $svc->guardar((int) $rol['id'], $req->post);
        if (!$r['ok']) {
            return self::form($svc, $rol, self::datosPost($req), $r['errores'], 422);
        }
        Session::flash('ok', 'Cambios guardados. Valen desde el próximo clic de cada usuario con este rol.');
        return Response::redirect(url('/roles'));
    }

    public static function borrar(Request $req, array $params): Response
    {
        $error = RolesService::desdeSesion()->borrar((int) $params['id']);
        Session::flash($error === null ? 'ok' : 'error', $error ?? 'Rol borrado.');
        return Response::redirect(url('/roles'));
    }

    /** @return array<string,mixed> */
    private static function datosPost(Request $req): array
    {
        $p = $req->post;
        return [
            'nombre' => (string) ($p['nombre'] ?? ''),
            'es_admin' => ($p['es_admin'] ?? '') === '1' ? 1 : 0,
            'es_master' => ($p['es_master'] ?? '') === '1' ? 1 : 0,
            'permisos' => array_fill_keys(array_map('strval', (array) ($p['permisos'] ?? [])), true),
        ];
    }

    /** @param array<string,mixed>|null $rol */
    private static function form(RolesService $svc, ?array $rol, array $datos, array $errores, int $status = 200): Response
    {
        return Response::html(View::render('roles/form', [
            'title' => $rol === null ? 'Nuevo rol' : 'Rol ' . $rol['nombre'],
            'nav' => 'roles',
            'rol' => $rol,
            'datos' => $datos,
            'errores' => $errores,
            'permisos' => $svc->permisos(),
            'otros' => $svc->otros(),
            'svc' => $svc,
        ]), $status);
    }
}
