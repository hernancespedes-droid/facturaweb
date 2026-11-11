<?php
declare(strict_types=1);

namespace App\Modules\Arca;

/** No hubo respuesta de ARCA (o no se pudo hablar con ella): el comprobante puede o no haber quedado autorizado. */
final class ArcaSinRespuesta extends \RuntimeException
{
}
