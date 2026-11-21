<?php
declare(strict_types=1);

namespace App\Modules\Arca;

/** No se pudo consultar el padrón de ARCA. El mensaje es para el usuario; $motivo: cuit, no_disponible, acceso, conexion, no_existe, arca. */
final class PadronError extends \RuntimeException
{
    public function __construct(string $mensaje, public readonly string $motivo)
    {
        parent::__construct($mensaje);
    }
}
