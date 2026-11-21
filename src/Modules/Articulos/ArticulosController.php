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
        return self::form(null, ['estado' => 'AC', 'moneda' => '$', 'grabado' => '1', 'incluirlista' => '1', 'controla_stock' => '1', 'alicuota' => '21', 'unidad_venta' => '01',
            'precio_automatico' => '1'], []); // D-133: los artículos nuevos nacen con el precio automático
    }

    public static function crear(Request $req, array $params): Response
    {
        $r = (new ArticulosService())->guardar(null, $req->post, puede('articulos.precios'), \App\Modules\Auth\Auth::id());
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
        $r = $svc->guardar((string) $a['codigo'], $req->post, puede('articulos.precios'), \App\Modules\Auth\Auth::id());
        if (!$r['ok']) {
            return self::form($a, $req->post + $a, $r['errores'], 422);
        }
        Session::flash('ok', 'Cambios guardados.');
        return Response::redirect(self::urlFicha((string) $a['codigo']));
    }

    /** Clave de sesión con los artículos cuyo costo acaba de cambiar (compra, importación) y falta preguntar por los precios. */
    public const PENDIENTES = 'precios.pendientes';

    /** Deja para «Actualizar precios» los artículos cuyo costo cambió. @param array<int,string> $codigos */
    public static function preguntarPrecios(array $codigos, string $motivo, string $volver): ?string
    {
        $codigos = array_values(array_unique($codigos));
        if ($codigos === [] || !puede('articulos.precios')) {
            return null;
        }
        // solo se pregunta por los que cambiarían de precio (si ninguna lista tiene margen, no hay nada que preguntar)
        $svc = new ArticulosService();
        $precios = new Precios();
        $codigos = array_values(array_filter($codigos, static function (string $c) use ($svc, $precios): bool {
            $a = $svc->obtener($c);
            return $a !== null && $precios->calcular($a)['cambia'];
        }));
        if ($codigos === []) {
            return null;
        }
        Session::set(self::PENDIENTES, ['codigos' => array_slice($codigos, 0, 1000), 'motivo' => $motivo, 'volver' => $volver]);
        return url('/articulos/precios') . '?pendientes=1';
    }

    /** Actualizar precios: vista previa de lo que cambia con los márgenes y elegir cuáles grabar. */
    public static function precios(Request $req, array $params): Response
    {
        $f = array_map(static fn ($x) => is_string($x) ? trim($x) : '', array_intersect_key($req->query, array_flip(['q', 'rubro', 'proveedor', 'ver', 'pendientes'])));
        $pend = null;
        if (($f['pendientes'] ?? '') === '1') {
            $pend = Session::get(self::PENDIENTES);
            if (!is_array($pend)) {
                return Response::redirect(url('/articulos/precios'));
            }
            $f['codigos'] = $pend['codigos'];
        }
        $f['ver'] = in_array($f['ver'] ?? '', ['automaticos', 'todos', 'cambian'], true) ? $f['ver'] : 'automaticos';
        $svc = new Precios();
        return Response::html(View::render('articulos/precios', ['title' => 'Precios por margen', 'nav' => 'articulos_precios', 'f' => $f, 'r' => $svc->propuesta($f),
            'cfg' => $svc->config(), 'pend' => $pend, 'rubros' => Validador::opciones('rubros')]));
    }

    public static function actualizarPrecios(Request $req, array $params): Response
    {
        $pend = Session::get(self::PENDIENTES);
        $volver = ($req->post['pendientes'] ?? '') === '1' && is_array($pend) ? (string) $pend['volver'] : url('/articulos/precios');
        if (($req->post['accion'] ?? '') === 'no') {
            Session::forget(self::PENDIENTES);
            Session::flash('ok', 'Los precios quedaron como estaban (solo cambió el costo).');
            return Response::redirect($volver);
        }
        $codigos = array_values(array_filter((array) ($req->post['codigos'] ?? []), 'is_string'));
        if ($codigos === []) {
            Session::flash('error', 'Tildá los artículos a los que querés actualizarles el precio.');
            return Response::redirect(url('/articulos/precios') . (($req->post['pendientes'] ?? '') === '1' ? '?pendientes=1' : ''));
        }
        $n = (new Precios())->actualizar($codigos, ($req->post['automatico'] ?? '') === '1', \App\Modules\Auth\Auth::id());
        Session::forget(self::PENDIENTES);
        Session::flash('ok', $n === 1 ? 'Se actualizó el precio de 1 artículo.' : 'Se actualizaron los precios de ' . $n . ' artículos.');
        return Response::redirect($volver);
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
            'puedePrecios' => puede('articulos.precios'),
            'precios' => (new Precios())->datosFicha(),
            'historial' => $a === null ? [] : (new Precios())->historial((string) $a['codigo']),
            'foto' => $a === null ? null : (Foto::urls([(string) $a['codigo']])[(string) $a['codigo']] ?? null),
        ]), $status);
    }
}
