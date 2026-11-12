<?php
declare(strict_types=1);

namespace App\Modules\Licencia;

use App\Core\Session;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Auth;

/** Sistema → Licencia: estado de la suscripción (Master y Administrador) y carga de la licencia renovada (solo Master). */
final class LicenciaController
{
    public static function index(Request $req, array $params): Response
    {
        if (!Auth::esMaster() && !Auth::esAdmin()) {
            return new Response(403);
        }
        $l = Licencia::leer();
        return Response::html(View::render('licencia/index', ['title' => 'Licencia', 'nav' => 'sistema', 'estado' => Licencia::estado(),
            'licencia' => $l, 'puedeCargar' => Auth::esMaster(), 'error' => null]));
    }

    public static function cargar(Request $req, array $params): Response
    {
        if (!Auth::esMaster()) {
            return new Response(403);
        }
        $r = Licencia::guardar(trim((string) ($req->post['licencia'] ?? '')));
        if (!$r['ok']) {
            return Response::html(View::render('licencia/index', ['title' => 'Licencia', 'nav' => 'sistema', 'estado' => Licencia::estado(),
                'licencia' => Licencia::leer(), 'puedeCargar' => true, 'error' => $r['error']]), 422);
        }
        Session::flash('ok', 'Licencia cargada. ' . Licencia::estado()['mensaje']);
        return Response::redirect(url('/sistema/licencia'));
    }
}
