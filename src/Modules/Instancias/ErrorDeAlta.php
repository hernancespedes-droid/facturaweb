<?php
declare(strict_types=1);

namespace App\Modules\Instancias;

/** Error del alta que se muestra junto a un campo del formulario. */
final class ErrorDeAlta extends \RuntimeException
{
    public function __construct(public readonly string $campo, string $mensaje)
    {
        parent::__construct($mensaje);
    }
}
