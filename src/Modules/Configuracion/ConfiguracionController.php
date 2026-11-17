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

    /** Logo de los comprobantes impresos (Logo::guardar valida y normaliza la imagen). */
    public static function subirLogo(Request $req, array $params): Response
    {
        $f = $req->files['logo'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = is_array($f) && in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'La imagen pesa más de lo que acepta el hosting (' . ini_get('upload_max_filesize') . ').' : 'Elegí la imagen del logo.';
            Session::flash('error', $err);
            return Response::redirect(url('/configuracion#logo'));
        }
        $err = Logo::guardar((string) $f['tmp_name'], Auth::id());
        Session::flash($err === null ? 'ok' : 'error', $err ?? 'Logo guardado: ya sale en las facturas, notas y recibos.');
        return Response::redirect(url('/configuracion#logo'));
    }

    public static function quitarLogo(Request $req, array $params): Response
    {
        Logo::quitar();
        Session::flash('ok', 'Se quitó el logo de los comprobantes.');
        return Response::redirect(url('/configuracion#logo'));
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
