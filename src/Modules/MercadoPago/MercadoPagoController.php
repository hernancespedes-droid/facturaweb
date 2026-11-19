<?php
declare(strict_types=1);

namespace App\Modules\MercadoPago;

use App\Core\Session;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Auth;
use App\Modules\Comprobantes\ComprobantesController;
use App\Modules\Comprobantes\ComprobantesService;
use App\Modules\Configuracion\Configuracion;
use App\Modules\Kiosco\KioscoService;

/** Cobrar con Mercado Pago (QR y link), el seguimiento del cobro y la notificación firmada que avisa la acreditación. Rutas en routes.php. */
final class MercadoPagoController
{
    private const CAMPOS_AUTORIZACION = ['autoriza_yo', 'autoriza_usuario', 'autoriza_clave', 'excede_ok'];

    private static function apagado(): ?Response
    {
        return MercadoPago::activo() ? null : new Response(404, View::render('mercadopago/apagado', ['title' => 'Mercado Pago', 'nav' => 'mp']));
    }

    /** El cobro, solo si es de este usuario o si puede administrar. @return array<string,mixed>|null */
    private static function suyo(string $token): ?array
    {
        $c = (new MercadoPagoService())->obtener($token);
        if ($c === null || ((int) $c['usuario_id'] !== (int) Auth::id() && !Auth::puede('mercadopago.administrar'))) {
            return null;
        }
        return $c;
    }

    /** Desde la pantalla de revisión: arma la venta con Mercado Pago como forma de pago y muestra el QR y el link. NO emite hasta que se acredite el pago. */
    public static function iniciar(Request $req, array $params): Response
    {
        if (($r = self::apagado()) !== null) {
            return $r;
        }
        $datos = json_decode((string) ($req->post['datos'] ?? ''), true);
        if (!is_array($datos)) {
            Session::flash('error', 'No se pudo leer la venta. Volvé a cargarla.');
            return Response::redirect(url('/comprobantes/nuevo'));
        }
        $kiosco = ($datos['origen'] ?? '') === 'kiosco';
        if ($kiosco) { // una venta del kiosco: solo presupuestos, con los precios de lista (salvo permiso)
            if (!KioscoService::habilitado() || !Auth::puede('kiosco.usar') || ($datos['tipo'] ?? '') !== 'PR') {
                return new Response(403);
            }
            $datos['items'] = KioscoService::fijarPrecios(array_values(array_filter((array) ($datos['items'] ?? []), 'is_array')), Auth::puede('kiosco.precio'));
        } elseif (!Auth::puede('comprobantes.emitir')) {
            return new Response(403);
        }
        $autorizacion = array_intersect_key($req->post, array_flip(self::CAMPOS_AUTORIZACION));
        $datos = array_merge($datos, $autorizacion);
        $svc = new MercadoPagoService();
        try {
            $r = $svc->iniciar($datos, (int) Auth::id(), Auth::puede('comprobantes.credito'));
        } catch (MercadoPagoError $ex) {
            return self::volverALaRevision($datos, $autorizacion, ['mp' => $ex->getMessage()], $kiosco);
        }
        if (!$r['ok']) {
            return self::volverALaRevision($datos, $autorizacion, $r['errores'], $kiosco);
        }
        return Response::redirect(url('/mercadopago/cobro/' . $r['cobro']['token']));
    }

    /** @param array<string,mixed> $datos @param array<string,mixed> $autorizacion @param array<string,string> $errores */
    private static function volverALaRevision(array $datos, array $autorizacion, array $errores, bool $kiosco): Response
    {
        $sinPago = array_diff_key($datos, array_flip(['forma_pago', 'fpago', 'pagos', 'pago_nro', 'pago_banco', 'pago_vto', 'recibido', 'excede_ok', 'autoriza_yo', 'autoriza_usuario', 'autoriza_clave']));
        $base = (new ComprobantesService())->armar($sinPago, Auth::puede('comprobantes.credito'), (int) Auth::id());
        if ($base['ok']) {
            return ComprobantesController::pantallaRevisar($base['cbte'], $sinPago, $errores, $autorizacion, 422);
        }
        Session::flash('error', 'No se pudo cobrar con Mercado Pago: ' . implode(' ', array_values($errores + $base['errores'])));
        return Response::redirect(url($kiosco ? '/kiosco' : '/comprobantes/nuevo'));
    }

    public static function cobro(Request $req, array $params): Response
    {
        if (($r = self::apagado()) !== null) {
            return $r;
        }
        $c = self::suyo((string) $params['token']);
        if ($c === null) {
            return new Response(404);
        }
        $c = (new MercadoPagoService())->refrescar($c);
        return Response::html(View::render('mercadopago/cobro', [
            'title' => 'Cobro con Mercado Pago', 'nav' => 'mp', 'c' => $c, 'etiqueta' => MercadoPago::etiqueta(), 'simulado' => MercadoPago::modo() === 'simulado',
            'urlFinal' => MercadoPagoService::urlFinal($c), 'puedeAdministrar' => Auth::puede('mercadopago.administrar'),
        ]));
    }

    /** Estado del cobro para la pantalla que espera el pago (JSON). En producción también le pregunta a Mercado Pago, por si la notificación no llegó. */
    public static function estado(Request $req, array $params): Response
    {
        $c = self::suyo((string) $params['token']);
        if ($c === null) {
            return Response::json(['estado' => 'desconocido'], 404);
        }
        $c = (new MercadoPagoService())->refrescar($c);
        return Response::json(['estado' => $c['estado'], 'texto' => MercadoPagoService::ESTADOS[$c['estado']] ?? $c['estado'], 'url_final' => MercadoPagoService::urlFinal($c),
            'error' => $c['error'], 'segundos' => max(0, (int) strtotime((string) $c['vence_en']) - time())]);
    }

    public static function cancelar(Request $req, array $params): Response
    {
        $c = self::suyo((string) $params['token']);
        if ($c === null) {
            return new Response(404);
        }
        if ((new MercadoPagoService())->cancelar((string) $c['token'])) {
            Session::flash('ok', 'Cobro cancelado. No se emitió nada.');
        } else {
            Session::flash('error', 'Ese cobro ya no se puede cancelar (' . (MercadoPagoService::ESTADOS[$c['estado']] ?? $c['estado']) . ').');
        }
        return Response::redirect(url($c['origen'] === 'kiosco' ? '/kiosco' : '/comprobantes/nuevo'));
    }

    /** Cobros: cada uno ve los suyos; quien administra, los de todos. */
    public static function index(Request $req, array $params): Response
    {
        if (($r = self::apagado()) !== null) {
            return $r;
        }
        $f = array_map(static fn ($x) => is_string($x) ? $x : '', array_intersect_key($req->query, array_flip(['estado', 'orden'])));
        $pagina = max(1, (int) ($req->query['pagina'] ?? 1));
        $por = Configuracion::porPagina();
        $admin = Auth::puede('mercadopago.administrar');
        $r = (new MercadoPagoService())->listar($f, $pagina, $por, $admin ? null : (int) Auth::id());
        return Response::html(View::render('mercadopago/index', [
            'title' => 'Cobros con Mercado Pago', 'nav' => 'mp', 'f' => $f, 'filas' => $r['filas'], 'total' => $r['total'], 'pagina' => $pagina, 'porPagina' => $por,
            'admin' => $admin, 'modo' => MercadoPago::modo(), 'etiqueta' => MercadoPago::etiqueta(), 'faltantes' => MercadoPago::faltantes(), 'conQr' => MercadoPago::conQr(),
            'webhook' => MercadoPago::urlNotificaciones(),
        ]));
    }

    public static function reintentar(Request $req, array $params): Response
    {
        if (($r = self::apagado()) !== null) {
            return $r;
        }
        $r = (new MercadoPagoService())->reintentar((int) $params['id']);
        Session::flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Cobro emitido.' : 'No se pudo emitir: ' . $r['mensaje']);
        return Response::redirect(url('/mercadopago'));
    }

    // ---------------------------------------------------------------- simulador (solo en modo simulado)

    public static function simulador(Request $req, array $params): Response
    {
        if (MercadoPago::modo() !== 'simulado') {
            return new Response(404);
        }
        $c = self::suyo((string) $params['token']);
        return $c === null ? new Response(404) : Response::html(View::render('mercadopago/simulador', ['title' => 'Mercado Pago (simulado)', 'nav' => 'mp', 'c' => $c]));
    }

    public static function simuladorPagar(Request $req, array $params): Response
    {
        if (MercadoPago::modo() !== 'simulado') {
            return new Response(404);
        }
        $c = self::suyo((string) $params['token']);
        if ($c === null) {
            return new Response(404);
        }
        $svc = new MercadoPagoService();
        $svc->confirmar((string) $c['token'], ['id' => (string) random_int(100000000, 999999999), 'estado' => 'approved', 'referencia' => (string) $c['token'], 'monto' => (float) $c['importe']]);
        Session::flash('ok', 'Pago simulado aprobado.');
        return Response::redirect(url('/mercadopago/cobro/' . $c['token']));
    }

    // ---------------------------------------------------------------- notificación de Mercado Pago (sin sesión ni CSRF: va por /api/ y se autentica con la firma)

    public static function notificacion(Request $req, array $params): Response
    {
        if (MercadoPago::modo() !== 'produccion') {
            return Response::json(['ok' => false, 'motivo' => 'apagado'], 404);
        }
        $cuerpo = json_decode((string) (self::$cuerpoCrudo ?? file_get_contents('php://input')), true);
        $cuerpo = is_array($cuerpo) ? $cuerpo : [];
        $q = $req->query;
        $id = (string) ($q['data.id'] ?? $q['data_id'] ?? $cuerpo['data']['id'] ?? $q['id'] ?? '');
        $tipo = (string) ($q['type'] ?? $q['topic'] ?? $cuerpo['type'] ?? $cuerpo['topic'] ?? '');
        $firma = (string) ($req->server['HTTP_X_SIGNATURE'] ?? '');
        $reqId = (string) ($req->server['HTTP_X_REQUEST_ID'] ?? '');
        // la firma se arma con el id que viene en la dirección (data.id); si no viene ahí, no se puede verificar
        $idFirmado = (string) ($q['data.id'] ?? $q['data_id'] ?? $q['id'] ?? '');
        if (!MercadoPago::firmaValida($idFirmado, $firma, $reqId)) {
            return Response::json(['ok' => false, 'motivo' => 'firma'], 401);
        }
        try {
            $pago = match ($tipo) {
                'payment' => MercadoPago::pago($id),
                'merchant_order' => MercadoPago::pagoDeOrden($id),
                default => null,
            };
            if ($pago !== null && $pago['referencia'] !== '') {
                (new MercadoPagoService())->confirmar($pago['referencia'], $pago);
            }
        } catch (\Throwable $ex) {
            error_log('[mercadopago] notificación ' . $tipo . ' ' . $id . ': ' . $ex->getMessage());
            return Response::json(['ok' => false], 500); // Mercado Pago la vuelve a mandar
        }
        return Response::json(['ok' => true]);
    }

    /** Solo para las pruebas: el cuerpo de la notificación (en producción se lee de php://input). */
    public static ?string $cuerpoCrudo = null;
}
