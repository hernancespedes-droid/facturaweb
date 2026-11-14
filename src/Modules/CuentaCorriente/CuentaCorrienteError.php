<?php
declare(strict_types=1);

namespace App\Modules\CuentaCorriente;

/** Un problema de negocio al grabar en la cuenta corriente (el mensaje se le muestra al usuario). */
final class CuentaCorrienteError extends \RuntimeException
{
}
