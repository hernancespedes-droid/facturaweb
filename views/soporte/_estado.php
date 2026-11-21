<?php
/** Badge del estado de un ticket. @var string $estado @var bool $master */
use App\Modules\Soporte\SoporteService;
$clase = ['nuevo' => 'badge-warn', 'en_curso' => '', 'esperando' => 'badge-warn', 'resuelto' => 'badge-ok', 'cerrado' => 'badge-off'][$estado] ?? '';
$textos = !empty($master) ? SoporteService::ESTADOS_MASTER : SoporteService::ESTADOS;
?><span class="badge <?= e($clase) ?>"><?= e($textos[$estado] ?? $estado) ?></span>
