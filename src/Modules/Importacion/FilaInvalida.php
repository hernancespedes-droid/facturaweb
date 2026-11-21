<?php
declare(strict_types=1);

namespace App\Modules\Importacion;

/** Una fila del archivo que no se puede importar (los mensajes van al usuario con el número de fila). */
final class FilaInvalida extends \RuntimeException
{
    /** @param string[] $mensajes */
    public function __construct(public readonly array $mensajes)
    {
        parent::__construct(implode(' ', $mensajes));
    }
}
