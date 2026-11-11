<?php
declare(strict_types=1);

namespace App\Modules\Clientes;

use App\Core\Session;
use App\Core\Validador;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Configuracion\Configuracion;

/** Rutas con Guard::permiso('clientes.ver'); las que graban, con 'clientes.editar' (ver routes.php). */
final class ClientesController
{
    public static function index(Request $req, array $params): Response
    {
        $f = array_intersect_key($req->query, array_flip(['q', 'iva', 'provincia', 'estado', 'orden']));
        $f = array_map(static fn ($x) => is_string($x) ? $x : '', $f);
        $pagina = max(1, (int) ($req->query['pagina'] ?? 1));
        $por = Configuracion::porPagina();
        $r = (new ClientesService())->listar($f, $pagina, $por);
        return Response::html(View::render('clientes/index', [
            'title' => 'Clientes', 'nav' => 'clientes',
            'f' => $f, 'filas' => $r['filas'], 'total' => $r['total'], 'pagina' => $pagina, 'porPagina' => $por,
            'provincias' => Validador::opciones('provincias'),
        ]));
    }

    public static function nuevo(Request $req, array $params): Response
    {
        return self::form(null, ['iva' => '1', 'forma_pago' => '1'], []);
    }

    public static function crear(Request $req, array $params): Response
    {
        $r = (new ClientesService())->guardar(null, $req->post);
        if (!$r['ok']) {
            return self::form(null, $req->post, $r['errores'], 422);
        }
        Session::flash('ok', 'Cliente ' . $r['codigo'] . ' creado.');
        return Response::redirect(url('/clientes/' . $r['codigo']));
    }

    public static function ver(Request $req, array $params): Response
    {
        $c = (new ClientesService())->obtener((int) $params['codigo']);
        return $c === null ? new Response(404) : self::form($c, $c, []);
    }

    public static function actualizar(Request $req, array $params): Response
    {
        $svc = new ClientesService();
        $c = $svc->obtener((int) $params['codigo']);
        if ($c === null) {
            return new Response(404);
        }
        $r = $svc->guardar((int) $c['codigo'], $req->post);
        if (!$r['ok']) {
            return self::form($c, $req->post + $c, $r['errores'], 422);
        }
        Session::flash('ok', 'Cambios guardados.');
        return Response::redirect(url('/clientes/' . $c['codigo']));
    }

    public static function activo(Request $req, array $params): Response
    {
        $activo = ($req->post['activo'] ?? '') === '1';
        if (!(new ClientesService())->cambiarActivo((int) $params['codigo'], $activo)) {
            return new Response(404);
        }
        Session::flash('ok', $activo ? 'Cliente activado.' : 'Cliente desactivado: no se le va a poder facturar.');
        return Response::redirect(url('/clientes/' . (int) $params['codigo']));
    }

    /** @param array<string,mixed>|null $c */
    private static function form(?array $c, array $datos, array $errores, int $status = 200): Response
    {
        $refs = [];
        foreach (ClientesService::campos() as $campo => $def) {
            if ($def['tipo'] === 'ref') {
                $refs[$campo] = Validador::opciones($def['ref']);
            }
        }
        return Response::html(View::render('clientes/form', [
            'title' => $c === null ? 'Nuevo cliente' : $c['razon_social'],
            'nav' => 'clientes',
            'c' => $c,
            'datos' => $datos,
            'errores' => $errores,
            'refs' => $refs,
            'proximo' => $c === null ? (new ClientesService())->proximoCodigo() : null,
            'puedeEditar' => puede('clientes.editar'),
        ]), $status);
    }
}
