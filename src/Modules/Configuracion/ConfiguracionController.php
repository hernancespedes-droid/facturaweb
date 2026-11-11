<?php
declare(strict_types=1);

namespace App\Modules\Configuracion;

use App\Core\Session;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Auth;

/** Pantalla de configuración. Rutas con Guard::permiso('configuracion.administrar'). */
final class ConfiguracionController
{
    public static function index(Request $req, array $params): Response
    {
        return self::form(Configuracion::listar(), []);
    }

    public static function guardar(Request $req, array $params): Response
    {
        $r = Configuracion::guardar((array) ($req->post['valor'] ?? []), Auth::id());
        if ($r['errores'] !== []) {
            $lista = Configuracion::listar();
            foreach ($lista as &$p) {
                $p['valor'] = (string) ($req->post['valor'][$p['clave']] ?? $p['valor']);
            }
            return self::form($lista, $r['errores'], 422);
        }
        Session::flash('ok', $r['cambiados'] === 0 ? 'No había cambios para guardar.' : 'Configuración guardada.');
        return Response::redirect(url('/configuracion'));
    }

    /** @param array<int,array<string,mixed>> $lista */
    private static function form(array $lista, array $errores, int $status = 200): Response
    {
        return Response::html(View::render('configuracion/index', [
            'title' => 'Configuración',
            'nav' => 'configuracion',
            'lista' => $lista,
            'errores' => $errores,
        ]), $status);
    }
}
