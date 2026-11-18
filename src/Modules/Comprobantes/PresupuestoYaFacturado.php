<?php
declare(strict_types=1);

namespace App\Modules\Comprobantes;

/** Se quiso facturar un presupuesto que ya generó su factura (o que tiene una contrapartida): un presupuesto se factura una sola vez. */
final class PresupuestoYaFacturado extends \RuntimeException
{
}
