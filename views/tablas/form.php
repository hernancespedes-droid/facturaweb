<?php
use App\Core\Form;
use App\Modules\Tablas\Definiciones;

/** @var array<string,mixed> $def */
/** @var array<string,mixed>|null $fila */
/** @var array<string,mixed> $datos */
/** @var array<string,string> $errores */
/** @var array<string,array<string,string>> $refs */
$ruta = '/tablas/' . $def['tabla'];
$clave = Definiciones::clave($def);
// Campos agrupados en secciones (el grupo '' va primero).
$grupos = [];
foreach ($def['campos'] as $campo => $c) {
    $grupos[$c['grupo'] ?? ''][$campo] = $c;
}
?>
<p><a href="<?= e(url($def['unico'] ? '/tablas' : $ruta)) ?>">← <?= e($def['unico'] ? 'Tablas' : $def['titulo']) ?></a></p>
<div class="page-head">
    <div>
        <h1><?= e($title) ?></h1>
        <?php if ($def['ayuda'] !== ''): ?><p class="muted small"><?= e($def['ayuda']) ?></p><?php endif; ?>
    </div>
</div>
<?php if (isset($errores['general'])): ?><div class="alerta alerta-error"><?= e($errores['general']) ?></div><?php endif; ?>

<form method="post" action="<?= e(url($fila === null ? $ruta : $ruta . '/' . (int) $fila['id'])) ?>" class="card form" novalidate>
    <?= csrf_field() ?>
    <?php foreach ($grupos as $grupo => $campos): ?>
        <?php if ($grupo !== ''): ?><h2><?= e($grupo) ?></h2><?php endif; ?>
        <div class="grid-2">
            <?php foreach ($campos as $campo => $c): ?>
                <?= Form::campo($campo, $c, $datos[$campo] ?? null, $errores[$campo] ?? null, $refs[$campo] ?? [], $fila !== null && in_array($campo, $clave, true)) ?>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($fila !== null && $clave !== []): ?><p class="small muted">El código no se cambia: lo usan los clientes, artículos y comprobantes.</p><?php endif; ?>
    <div class="actions"><button class="btn" type="submit"><?= $fila === null ? 'Agregar' : 'Guardar cambios' ?></button></div>
</form>

<?php if ($fila !== null && !$def['unico']): ?>
    <form method="post" action="<?= e(url($ruta . '/' . (int) $fila['id'] . '/borrar')) ?>" class="zona-peligro" data-confirmar="¿Borrar este registro? No se puede deshacer.">
        <?= csrf_field() ?>
        <button class="btn btn-danger btn-sm" type="submit">Borrar</button>
    </form>
<?php endif; ?>
