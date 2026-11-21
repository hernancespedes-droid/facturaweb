<?php
declare(strict_types=1);

namespace App\Modules\Soporte;

/** El master no contestó o no reconoció a la instalación: el mensaje es para mostrar al usuario. */
final class SoporteNoDisponible extends \RuntimeException
{
}
