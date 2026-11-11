<?php
declare(strict_types=1);

namespace App\Modules\Arca;

/** ARCA aprobó el comprobante pero no se pudo grabar: el CAE y los datos quedaron registrados para recuperarlo. */
final class GrabacionFallida extends \RuntimeException
{
}
