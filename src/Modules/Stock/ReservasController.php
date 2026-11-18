<?php
declare(strict_types=1);

namespace App\Modules\Stock;

use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Auth;

/**
 * Reservas de stock de las ventas en curso (JSON). Las usan las pantallas que venden: comprobantes (comprobantes.emitir) y Kiosco (kiosco.usar); ver routes.php.
 * Cada pantalla manda el token de su venta y lo que lleva; el servidor lo reserva a nombre del usuario y devuelve cómo está cada producto.
 */
final class ReservasController
{
    /** Reserva (o actualiza) lo que lleva la venta y devuelve el estado de cada producto. */
    public static function reservar(Request $req, array $params): Response
    {
        $token = ReservasService::tokenValido($req->post['token'] ?? null);
        if ($token === null) {
            return Response::json(['error' => 'Falta la clave de la venta.'], 422);
        }
        $lineas = json_decode((string) ($req->post['lineas'] ?? '[]'), true);
        if (!is_array($lineas) || count($lineas) > 200) {
            return Response::json(['error' => 'Lo que lleva la venta no se puede leer.'], 422);
        }
        $lineas = array_values(array_filter($lineas, static fn ($l) => is_array($l)));
        $origen = ($req->post['origen'] ?? '') === 'kiosco' ? 'kiosco' : 'comprobante';
        $estados = (new ReservasService())->sincronizar($token, $lineas, (int) Auth::id(), $origen);
        return Response::json(['estados' => $estados, 'minutos' => ReservasService::minutos()]);
    }

    /** Libera todo lo que reservó la venta (se vació el carrito o se abandonó). */
    public static function liberar(Request $req, array $params): Response
    {
        $token = ReservasService::tokenValido($req->post['token'] ?? null);
        if ($token !== null) {
            (new ReservasService())->liberar($token);
        }
        return Response::json(['ok' => true]);
    }
}
