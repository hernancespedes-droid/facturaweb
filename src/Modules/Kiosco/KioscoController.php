<?php
declare(strict_types=1);

namespace App\Modules\Kiosco;

use App\Core\Session;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Articulos\BuscadorArticulos;
use App\Modules\Auth\Auth;
use App\Modules\Comprobantes\ComprobantesController;
use App\Modules\Comprobantes\ComprobantesService;

/**
 * Modo Kiosco: los productos en tarjetas y un carrito siempre visible. Al finalizar arma un PRESUPUESTO (letra X) y sigue el mismo camino que el presupuesto:
 * pantalla de revisión con «Cómo se paga» (varias formas, vuelto) y emisión. Hay que tener tildado «habilitar modo Kiosco» en Parámetros; todo pide el permiso
 * kiosco.usar (que se da o se quita por rol) y solo emite presupuestos: no da acceso a facturar.
 */
final class KioscoController
{
    private static function apagado(): ?Response
    {
        return KioscoService::habilitado() ? null : new Response(404, View::render('kiosco/apagado', ['title' => 'Kiosco', 'nav' => 'kiosco']));
    }

    public static function index(Request $req, array $params): Response
    {
        if (($r = self::apagado()) !== null) {
            return $r;
        }
        $svc = new ComprobantesService();
        // venta recién hecha (vuelve de emitir): se muestra el aviso y se vacía el carrito
        $hecho = null;
        $id = (int) ($req->query['hecho'] ?? 0);
        if ($id > 0 && ($h = $svc->obtener($id)) !== null && $h['tipo'] === 'PR' && Auth::puede('comprobantes.ver')) {
            $hecho = $h;
        }
        return Response::html(View::render('kiosco/index', [
            'title' => 'Kiosco', 'nav' => 'kiosco', 'rubros' => KioscoService::rubros(), 'cliente' => KioscoService::clientePorOmision(),
            'sucursales' => $svc->sucursales(), 'hecho' => $hecho, 'puedeVer' => Auth::puede('comprobantes.ver'),
            'conTicket' => \App\Modules\Cambios\CambiosService::habilitado() && Auth::puede('comprobantes.ver'),
        ]));
    }

    /** Tarjetas (JSON): artículos activos con su stock por depósito; con ?exacto=1 resuelve el código leído con la pistola. */
    public static function articulos(Request $req, array $params): Response
    {
        if (self::apagado() !== null) {
            return new Response(404);
        }
        BuscadorArticulos::$reserva = \App\Modules\Stock\ReservasService::tokenValido($req->query['reserva'] ?? null); // lo reservado por OTROS puestos (no lo propio)
        $q = trim((string) ($req->query['q'] ?? ''));
        if (isset($req->query['exacto'])) {
            return Response::json(['items' => BuscadorArticulos::buscar($q, true), 'hay_mas' => false]);
        }
        return Response::json(BuscadorArticulos::listar($q, max(1, (int) ($req->query['pagina'] ?? 1)), trim((string) ($req->query['rubro'] ?? '')), 24));
    }

    /** Buscador de clientes del carrito (JSON): el mismo de los comprobantes; el usuario elige. */
    public static function buscarCliente(Request $req, array $params): Response
    {
        if (self::apagado() !== null) {
            return new Response(404);
        }
        return ComprobantesController::buscarCliente($req, $params);
    }

    /** Finalizar: arma el presupuesto con lo que hay en el carrito y pasa a la revisión (con «Cómo se paga»). */
    public static function cobrar(Request $req, array $params): Response
    {
        if (($r = self::apagado()) !== null) {
            return $r;
        }
        $items = [];
        foreach ((array) ($req->post['items'] ?? []) as $x) {
            if (is_array($x)) {
                $items[] = ['articulo' => (string) ($x['articulo'] ?? ''), 'descripcion' => '', 'cantidad' => (string) ($x['cantidad'] ?? ''), 'precio' => (string) ($x['precio'] ?? ''),
                    'alicuota' => (string) ($x['alicuota'] ?? '')];
            }
        }
        $datos = ['tipo' => 'PR', 'origen' => 'kiosco', 'cliente' => (string) ($req->post['cliente'] ?? ''), 'items' => $items, 'reserva' => (string) ($req->post['reserva'] ?? '')];
        if (trim((string) ($req->post['sucursal'] ?? '')) !== '') { // si no viene, con un solo punto de venta se usa ese
            $datos['sucursal'] = (string) $req->post['sucursal'];
        }
        $r = (new ComprobantesService())->armar($datos, Auth::puede('comprobantes.credito'), (int) Auth::id());
        if (!$r['ok']) {
            Session::flash('error', 'No se pudo armar la venta: ' . implode(' ', array_values($r['errores'])));
            return Response::redirect(url('/kiosco'));
        }
        return ComprobantesController::pantallaRevisar($r['cbte'], $datos);
    }

    /** Emitir el presupuesto del carrito. Solo presupuestos del kiosco: este permiso no sirve para emitir otros comprobantes. */
    public static function emitir(Request $req, array $params): Response
    {
        if (($r = self::apagado()) !== null) {
            return $r;
        }
        $d = json_decode((string) ($req->post['datos'] ?? ''), true);
        if (!is_array($d) || ($d['tipo'] ?? '') !== 'PR' || ($d['origen'] ?? '') !== 'kiosco') {
            Session::flash('error', 'No se pudo leer la venta. Volvé a armarla en el kiosco.');
            return Response::redirect(url('/kiosco'));
        }
        return ComprobantesController::emitir($req, $params);
    }
}
