<?php
declare(strict_types=1);

namespace App\Modules\Tablas;

use App\Core\Session;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Auth;

/** Pantallas de las 12 tablas. Cada tabla pide su permiso (tablas.<tabla>), verificado acá en cada ruta. */
final class TablasController
{
    public static function inicio(Request $req, array $params): Response
    {
        $tablas = array_filter(Definiciones::todas(), static fn ($d, $t) => Auth::puede('tablas.' . $t), ARRAY_FILTER_USE_BOTH);
        if ($tablas === []) {
            return new Response(403);
        }
        return Response::html(View::render('tablas/inicio', ['title' => 'Tablas', 'nav' => 'tablas', 'tablas' => $tablas]));
    }

    public static function index(Request $req, array $params): Response
    {
        $def = self::def($params);
        if ($def instanceof Response) {
            return $def;
        }
        if ($def['unico']) {
            return Response::redirect(url('/tablas/' . $def['tabla'] . '/1'));
        }
        $q = trim((string) ($req->query['q'] ?? ''));
        return Response::html(View::render('tablas/index', [
            'title' => $def['titulo'],
            'nav' => $def['tabla'],
            'def' => $def,
            'filas' => (new TablasService($def))->listar($q),
            'q' => $q,
            'refs' => self::refs($def),
        ]));
    }

    public static function nuevo(Request $req, array $params): Response
    {
        $def = self::def($params);
        if ($def instanceof Response) {
            return $def;
        }
        if ($def['unico']) {
            return new Response(404);
        }
        return self::form($def, null, [], []);
    }

    public static function crear(Request $req, array $params): Response
    {
        $def = self::def($params);
        if ($def instanceof Response) {
            return $def;
        }
        if ($def['unico']) {
            return new Response(404);
        }
        $r = (new TablasService($def))->guardar(null, $req->post);
        if (!$r['ok']) {
            return self::form($def, null, $req->post, $r['errores'], 422);
        }
        Session::flash('ok', 'Listo, quedó guardado.');
        return Response::redirect(url('/tablas/' . $def['tabla']));
    }

    public static function editar(Request $req, array $params): Response
    {
        $def = self::def($params);
        if ($def instanceof Response) {
            return $def;
        }
        $fila = (new TablasService($def))->obtener((int) $params['id']);
        return $fila === null ? new Response(404) : self::form($def, $fila, $fila, []);
    }

    public static function actualizar(Request $req, array $params): Response
    {
        $def = self::def($params);
        if ($def instanceof Response) {
            return $def;
        }
        $svc = new TablasService($def);
        $fila = $svc->obtener((int) $params['id']);
        if ($fila === null) {
            return new Response(404);
        }
        $r = $svc->guardar((int) $fila['id'], $req->post);
        if (!$r['ok']) {
            return self::form($def, $fila, $req->post + $fila, $r['errores'], 422);
        }
        Session::flash('ok', 'Cambios guardados.');
        return Response::redirect(url($def['unico'] ? '/tablas/' . $def['tabla'] . '/1' : '/tablas/' . $def['tabla']));
    }

    public static function borrar(Request $req, array $params): Response
    {
        $def = self::def($params);
        if ($def instanceof Response) {
            return $def;
        }
        $error = (new TablasService($def))->borrar((int) $params['id']);
        Session::flash($error === null ? 'ok' : 'error', $error ?? 'Borrado.');
        return Response::redirect(url('/tablas/' . $def['tabla']));
    }

    /** Definición de la tabla de la URL, o 404 si no existe / 403 si el rol no la tiene. @return array<string,mixed>|Response */
    private static function def(array $params): array|Response
    {
        $def = Definiciones::de((string) ($params['tabla'] ?? ''));
        if ($def === null) {
            return new Response(404);
        }
        if (!Auth::puede($def['permiso'])) {
            return new Response(403);
        }
        return $def;
    }

    /** Opciones de los campos "ref" de la tabla. @return array<string,array<string,string>> */
    private static function refs(array $def): array
    {
        $out = [];
        foreach ($def['campos'] as $campo => $c) {
            if ($c['tipo'] === 'ref') {
                $out[$campo] = TablasService::opcionesRef($c['ref']);
            }
        }
        return $out;
    }

    /** @param array<string,mixed>|null $fila */
    private static function form(array $def, ?array $fila, array $datos, array $errores, int $status = 200): Response
    {
        return Response::html(View::render('tablas/form', [
            'title' => $def['unico'] ? $def['titulo'] : ($fila === null ? 'Alta de ' . $def['singular'] : ucfirst($def['singular'])),
            'nav' => $def['tabla'],
            'def' => $def,
            'fila' => $fila,
            'datos' => $datos,
            'errores' => $errores,
            'refs' => self::refs($def),
        ]), $status);
    }
}
