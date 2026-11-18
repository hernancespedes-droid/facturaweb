<?php
declare(strict_types=1);

namespace App\Modules\Cambios;

/** El cambio ya no se puede hacer (por ejemplo, otro cambio devolvió lo mismo mientras tanto). */
final class CambioInvalido extends \RuntimeException
{
}
