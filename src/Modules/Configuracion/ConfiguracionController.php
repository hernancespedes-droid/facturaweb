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

    /** Apariencia: nombre, color y opciones del logo (Apariencia::guardar valida). */
    public static function guardarApariencia(Request $req, array $params): Response
    {
        $err = Apariencia::guardar($req->post, Auth::id());
        if ($err !== []) {
            return self::form(Configuracion::listar(), [], 422, $err, $req->post);
        }
        Session::flash('ok', 'Apariencia guardada.');
        return Response::redirect(url('/configuracion#apariencia'));
    }

    public static function subirLogoPantalla(Request $req, array $params): Response
    {
        $f = $req->files['logo'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Session::flash('error', is_array($f) && in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'La imagen pesa más de lo que acepta el hosting (' . ini_get('upload_max_filesize') . ').' : 'Elegí la imagen del logo.');
            return Response::redirect(url('/configuracion#apariencia'));
        }
        $err = Apariencia::guardarLogoPantalla((string) $f['tmp_name'], Auth::id());
        Session::flash($err === null ? 'ok' : 'error', $err ?? 'Logo de la pantalla guardado.');
        return Response::redirect(url('/configuracion#apariencia'));
    }

    public static function quitarLogoPantalla(Request $req, array $params): Response
    {
        Apariencia::quitarLogoPantalla();
        Session::flash('ok', 'Se quitó el logo de la pantalla: vuelve a verse el de los comprobantes.');
        return Response::redirect(url('/configuracion#apariencia'));
    }

    /** El logo que va en pantalla. Con ?v= (la versión) el navegador lo guarda un año. */
    public static function marcaLogo(Request $req, array $params): Response
    {
        $c = Apariencia::claveLogo();
        return self::imagen($c === null ? null : Apariencia::datos($c), $req);
    }

    public static function marcaIcono(Request $req, array $params): Response
    {
        if (Apariencia::claveLogo() === null) {
            return self::imagen(null, $req);
        }
        $i = Apariencia::datos(Apariencia::IMG_ICONO);
        if ($i === null) { // logo cargado antes de la 0.31.0: el ícono se hace la primera vez
            Apariencia::regenerarIcono(null);
            $i = Apariencia::datos(Apariencia::IMG_ICONO);
        }
        return self::imagen($i, $req);
    }

    /** @param array{tipo:string,datos:string}|null $img */
    private static function imagen(?array $img, Request $req): Response
    {
        if ($img === null || !in_array($img['tipo'], ['image/png', 'image/jpeg'], true)) {
            return new Response(404, '', ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
        }
        $cache = isset($req->query['v']) ? 'public, max-age=31536000, immutable' : 'public, max-age=300';
        return new Response(200, $img['datos'], ['Content-Type' => $img['tipo'], 'Cache-Control' => $cache, 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * @param array<int,array<string,mixed>> $lista
     * @param array<string,string> $erroresApariencia
     * @param array<string,mixed>|null $apariencia lo que se mandó (para no perderlo si hay error)
     */
    private static function form(array $lista, array $errores, int $status = 200, array $erroresApariencia = [], ?array $apariencia = null): Response
    {
        return Response::html(View::render('configuracion/index', [
            'title' => 'Configuración',
            'nav' => 'configuracion',
            'lista' => $lista,
            'errores' => $errores,
            'erroresApariencia' => $erroresApariencia,
            'apariencia' => $apariencia,
        ]), $status);
    }
}
