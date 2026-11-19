<?php
declare(strict_types=1);

namespace App\Modules\MercadoPago;

/** Mercado Pago no contestó o rechazó el pedido. El mensaje se puede mostrar al usuario (nunca lleva claves). */
final class MercadoPagoError extends \RuntimeException
{
}
