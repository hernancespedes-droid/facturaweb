<?php
declare(strict_types=1);

namespace App\Modules\Licencia;

/** La suscripción no está vigente: no se pueden emitir comprobantes (se puede seguir consultando e imprimiendo). */
final class LicenciaVencida extends \RuntimeException
{
}
