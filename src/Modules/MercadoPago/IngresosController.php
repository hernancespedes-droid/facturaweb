<?php
declare(strict_types=1);

namespace App\Modules\MercadoPago;

use App\Core\Session;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Auth;
use App\Modules\Configuracion\Configuracion;

/** Ingresos de dinero a la cuenta de Mercado Pago: la grilla, la campanita (novedades) y el ingreso simulado. Permiso mercadopago.ingresos. */
final class IngresosController
{
    public static function index(Request $req, array $params): Response
    {
        if (!MercadoPago::activo()) {
            return new Response(404, View::render('mercadopago/apagado', ['title' => 'Mercado Pago', 'nav' => 'mp_ingresos']));
        }
        $svc = new IngresosService();
        $svc->consultarSiToca();
        $f = array_map(static fn ($x) => is_string($x) ? $x : '', array_intersect_key($req->query, array_flip(['desde', 'hasta', 'medio', 'texto', 'orden'])));
        if (!isset($req->query['desde']) && !isset($req->query['hasta'])) {
            $f['desde'] = $f['hasta'] = date('Y-m-d'); // por omisión, lo de hoy
        }
        $pagina = max(1, (int) ($req->query['pagina'] ?? 1));
        $por = Configuracion::porPagina();
        $r = $svc->listar($f, $pagina, $por);
        $uid = (int) Auth::id();
        return Response::html(View::render('mercadopago/ingresos', [
            'title' => 'Ingresos de Mercado Pago', 'nav' => 'mp_ingresos', 'f' => $f, 'r' => $r, 'pagina' => $pagina, 'porPagina' => $por,
            'visto' => $svc->ultimoVisto($uid), 'novedades' => $svc->novedades($uid), 'simulado' => MercadoPago::modo() === 'simulado', 'etiqueta' => MercadoPago::etiqueta(),
            'mails' => IngresosService::mails(), 'admin' => Auth::puede('configuracion.administrar'),
        ]));
    }

    /** Campanita: consulta a Mercado Pago si toca y devuelve cuántos no vio y los ingresos nuevos desde el último que conoce la pantalla (JSON). */
    public static function novedades(Request $req, array $params): Response
    {
        if (!MercadoPago::activo()) {
            return Response::json(['sin_ver' => 0, 'ultimo_id' => 0, 'nuevos' => []]);
        }
        $svc = new IngresosService();
        $svc->consultarSiToca();
        $desde = isset($req->query['desde']) && preg_match('/^\d{1,10}$/', (string) $req->query['desde']) ? (int) $req->query['desde'] : null;
        return Response::json($svc->novedades((int) Auth::id(), $desde));
    }

    public static function vistos(Request $req, array $params): Response
    {
        (new IngresosService())->marcarVistos((int) Auth::id(), (int) ($req->post['hasta'] ?? 0));
        if (($req->post['ajax'] ?? '') === '1') {
            return Response::json(['ok' => true]);
        }
        return Response::redirect(url('/mercadopago/ingresos'));
    }

    public static function simular(Request $req, array $params): Response
    {
        if (MercadoPago::modo() !== 'simulado') {
            return new Response(404);
        }
        $monto = \App\Core\Valida::decimal((string) ($req->post['monto'] ?? ''));
        if ($monto === null || $monto <= 0 || $monto > 99999999) {
            Session::flash('error', 'Escribí un importe mayor que cero.');
        } else {
            (new IngresosService())->simular((float) $monto, trim((string) ($req->post['pagador'] ?? '')), 'Ingreso simulado');
            Session::flash('ok', 'Ingreso simulado registrado.');
        }
        return Response::redirect(url('/mercadopago/ingresos'));
    }
}
