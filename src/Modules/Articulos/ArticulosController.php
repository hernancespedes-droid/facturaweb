<?php
declare(strict_types=1);

namespace App\Modules\Articulos;

use App\Core\Session;
use App\Core\Validador;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Configuracion\Configuracion;

/**
 * Rutas con Guard::permiso('articulos.ver'); las que graban, con 'articulos.editar' (ver routes.php).
 * El código del artículo va en ?codigo= (los códigos del VFP pueden tener espacios o puntos).
 */
final class ArticulosController
{
    public static function index(Request $req, array $params): Response
    {
        $f = array_intersect_key($req->query, array_flip(['q', 'rubro', 'estado', 'orden']));
        $f = array_map(static fn ($x) => is_string($x) ? $x : '', $f);
        $pagina = max(1, (int) ($req->query['pagina'] ?? 1));
        $por = Configuracion::porPagina();
        $r = (new ArticulosService())->listar($f, $pagina, $por);
        return Response::html(View::render('articulos/index', [
            'title' => 'Artículos', 'nav' => 'articulos',
            'f' => $f, 'filas' => $r['filas'], 'total' => $r['total'], 'pagina' => $pagina, 'porPagina' => $por,
            'rubros' => Validador::opciones('rubros'),
        ]));
    }

    public static function nuevo(Request $req, array $params): Response
    {
        return self::form(null, ['estado' => 'AC', 'moneda' => '$', 'grabado' => '1', 'incluirlista' => '1', 'controla_stock' => '1', 'alicuota' => '21', 'unidad_venta' => '01'], []);
    }

    public static function crear(Request $req, array $params): Response
    {
        $r = (new ArticulosService())->guardar(null, $req->post);
        if (!$r['ok']) {
            return self::form(null, $req->post, $r['errores'], 422);
        }
        Session::flash('ok', 'Artículo ' . $r['codigo'] . ' creado.');
        return Response::redirect(self::urlFicha((string) $r['codigo']));
    }

    public static function ver(Request $req, array $params): Response
    {
        $a = (new ArticulosService())->obtener((string) ($req->query['codigo'] ?? ''));
        return $a === null ? new Response(404) : self::form($a, $a, []);
    }

    public static function actualizar(Request $req, array $params): Response
    {
        $svc = new ArticulosService();
        $a = $svc->obtener((string) ($req->query['codigo'] ?? ''));
        if ($a === null) {
            return new Response(404);
        }
        $r = $svc->guardar((string) $a['codigo'], $req->post);
        if (!$r['ok']) {
            return self::form($a, $req->post + $a, $r['errores'], 422);
        }
        Session::flash('ok', 'Cambios guardados.');
        return Response::redirect(self::urlFicha((string) $a['codigo']));
    }

    /** La foto del artículo (JPEG): ?t=g grande (zoom) o ?t=c chica. La ven todos los que trabajan con artículos (rutas con permisos en routes.php). */
    public static function foto(Request $req, array $params): Response
    {
        $f = Foto::obtener(mb_strtoupper(trim((string) ($req->query['codigo'] ?? ''))), (string) ($req->query['t'] ?? 'c'));
        if ($f === null) {
            return new Response(404);
        }
        return new Response(200, $f['datos'], ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
    }

    public static function subirFoto(Request $req, array $params): Response
    {
        $a = (new ArticulosService())->obtener((string) ($req->query['codigo'] ?? ''));
        if ($a === null) {
            return new Response(404);
        }
        $f = $req->files['foto'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = is_array($f) && in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'La foto pesa más de lo que acepta el hosting (' . ini_get('upload_max_filesize') . ').' : 'Elegí la foto del producto.';
            Session::flash('error', $err);
        } else {
            $err = Foto::guardar((string) $a['codigo'], (string) $f['tmp_name']);
            Session::flash($err === null ? 'ok' : 'error', $err ?? 'Foto guardada.');
        }
        return Response::redirect(self::urlFicha((string) $a['codigo']) . '#foto');
    }

    public static function quitarFoto(Request $req, array $params): Response
    {
        $a = (new ArticulosService())->obtener((string) ($req->query['codigo'] ?? ''));
        if ($a === null) {
            return new Response(404);
        }
        Foto::quitar((string) $a['codigo']);
        Session::flash('ok', 'Se quitó la foto.');
        return Response::redirect(self::urlFicha((string) $a['codigo']) . '#foto');
    }

    public static function urlFicha(string $codigo): string
    {
        return url('/articulos/ficha') . '?' . http_build_query(['codigo' => $codigo]);
    }

    /** @param array<string,mixed>|null $a */
    private static function form(?array $a, array $datos, array $errores, int $status = 200): Response
    {
        $refs = [];
        foreach (ArticulosService::campos() as $campo => $def) {
            if ($def['tipo'] === 'ref') {
                $refs[$campo] = Validador::opciones($def['ref']);
            }
        }
        return Response::html(View::render('articulos/form', [
            'title' => $a === null ? 'Nuevo artículo' : $a['descripcion'],
            'nav' => 'articulos',
            'a' => $a,
            'datos' => $datos,
            'errores' => $errores,
            'refs' => $refs,
            'puedeEditar' => puede('articulos.editar'),
            'foto' => $a === null ? null : (Foto::urls([(string) $a['codigo']])[(string) $a['codigo']] ?? null),
        ]), $status);
    }
}
