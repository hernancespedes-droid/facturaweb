<?php
declare(strict_types=1);

namespace App\Modules\Articulos;

use App\Core\Session;
use App\Core\Validador;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Auth;

/**
 * Actualización de precios por filtro (0.60.0, D-144…D-146). Todas las rutas con el permiso articulos.precios (ver routes.php).
 * GET muestra el formulario y, con ?vista=1, la vista previa; POST confirma (con la firma de la vista previa); POST /deshacer vuelve atrás la última.
 */
final class ActualizacionController
{
    public static function form(Request $req, array $params): Response
    {
        $vista = ($req->query['vista'] ?? '') === '1';
        $l = ActualizacionMasiva::leer($vista ? $req->query : []);
        $svc = new ActualizacionMasiva();
        $prev = $vista && $l['errores'] === [] ? $svc->simular($l['d']) : null;
        return self::pantalla($l['d'], $vista ? $l['errores'] : [], $prev, $svc);
    }

    public static function confirmar(Request $req, array $params): Response
    {
        $l = ActualizacionMasiva::leer($req->post);
        $svc = new ActualizacionMasiva();
        if ($l['errores'] !== []) {
            return self::pantalla($l['d'], $l['errores'], null, $svc, 422);
        }
        $r = $svc->confirmar($l['d'], (string) ($req->post['firma'] ?? ''), Auth::id());
        if (!$r['ok']) {
            Session::flash('error', (string) $r['error']);
            return Response::redirect(self::url($l['d']));
        }
        $txt = $r['cambiados'] === 1 ? 'Se actualizó 1 artículo' : 'Se actualizaron ' . number_format($r['cambiados'], 0, ',', '.') . ' artículos';
        if ($r['a_manual'] > 0) {
            $txt .= ' (' . $r['a_manual'] . ' pasaron a precio manual)';
        }
        Session::flash('ok', $txt . '. Si te equivocaste, la podés deshacer desde «Últimas actualizaciones».');
        return Response::redirect(url('/articulos/actualizacion') . '#registro');
    }

    public static function deshacer(Request $req, array $params): Response
    {
        $r = (new ActualizacionMasiva())->deshacer((int) ($req->post['id'] ?? 0), Auth::id());
        if (!$r['ok']) {
            Session::flash('error', (string) $r['error']);
        } else {
            $txt = 'Se deshizo la actualización: ' . ($r['deshechos'] === 1 ? '1 artículo volvió' : $r['deshechos'] . ' artículos volvieron') . ' a los precios de antes.';
            if ($r['no_deshechos'] !== []) {
                $txt .= ' Quedaron como estaban ' . count($r['no_deshechos']) . ' que alguien cambió después: ' . implode(', ', array_slice($r['no_deshechos'], 0, 20))
                    . (count($r['no_deshechos']) > 20 ? '…' : '') . '.';
            }
            Session::flash('ok', $txt);
        }
        return Response::redirect(url('/articulos/actualizacion') . '#registro');
    }

    /** Buscador de artículos para «Desde / Hasta código» (con la lupa: ?listar=1). */
    public static function buscarArticulo(Request $req, array $params): Response
    {
        if (isset($req->query['listar'])) {
            return Response::json(BuscadorArticulos::listar(trim((string) ($req->query['q'] ?? '')), (int) ($req->query['pagina'] ?? 1)));
        }
        return Response::json(BuscadorArticulos::buscar(trim((string) ($req->query['q'] ?? '')), isset($req->query['exacto'])));
    }

    /** @param array<string,mixed> $d */
    private static function url(array $d): string
    {
        $q = ['vista' => '1'];
        foreach (array_keys(ActualizacionMasiva::FILTROS) as $k) {
            $q[$k . '_desde'] = $d[$k . '_desde'];
            $q[$k . '_hasta'] = $d[$k . '_hasta'];
        }
        $q += ['que' => $d['que'], 'lista' => $d['lista'], 'forma' => $d['forma'], 'valor' => $d['valor_texto'], 'automaticos' => $d['automaticos'],
            'redondear' => $d['redondear'] ? '1' : '0', 'enviado' => '1'];
        return url('/articulos/actualizacion') . '?' . http_build_query($q);
    }

    /** @param array<string,mixed> $d @param array<string,string> $errores @param array<string,mixed>|null $prev */
    private static function pantalla(array $d, array $errores, ?array $prev, ActualizacionMasiva $svc, int $status = 200): Response
    {
        $precios = new Precios();
        return Response::html(View::render('articulos/actualizacion', [
            'title' => 'Actualización de precios', 'nav' => 'articulos_actualizacion',
            'd' => $d, 'errores' => $errores, 'prev' => $prev, 'cfg' => $precios->config(),
            'rubros' => Validador::opciones('rubros'),
            'registro' => $svc->registro(), 'deshacible' => $svc->deshacible(),
        ]), $status);
    }
}
