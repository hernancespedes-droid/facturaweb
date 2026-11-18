<?php
declare(strict_types=1);

namespace App\Modules\Stock;

/** Un producto no tiene stock disponible (o lo tiene reservado otro puesto) y la venta no tiene la autorización de un supervisor. */
final class StockNoDisponible extends \RuntimeException
{
}
