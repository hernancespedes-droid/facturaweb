<?php
declare(strict_types=1);

namespace App\Modules\Arca;

/** ARCA contestó y NO aprobó el comprobante (no se grabó nada). */
final class AutorizacionRechazada extends \RuntimeException
{
    /** @param string[] $detalles motivos con su código, como los da ARCA */
    public function __construct(string $mensaje, public readonly array $detalles = [])
    {
        parent::__construct($mensaje);
    }
}
