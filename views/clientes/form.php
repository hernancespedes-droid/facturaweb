<?php
use App\Core\Form;
use App\Modules\Clientes\ClientesService as S;

/** @var array<string,mixed>|null $c */
/** @var array<string,mixed> $datos */
/** @var array<string,string> $errores */
/** @var array<string,array<string,string>> $refs */
/** @var int|null $proximo */
/** @var bool $puedeEditar */
$grupos = [];
foreach (S::campos() as $campo => $def) {
    $grupos[$def['grupo'] ?? 'Datos'][$campo] = $def;
}
$soloLectura = !$puedeEditar;
?>
<p><a href="<?= e(url('/clientes')) ?>">← Clientes</a></p>
<div class="page-head">
    <div>
        <p class="eyebrow"><?= $c === null ? 'Nuevo cliente · código ' . e($proximo) . ' (se confirma al grabar)' : 'Cliente ' . e($c['codigo']) ?></p>
        <h1><?= e($c === null ? 'Nuevo cliente' : $c['razon_social']) ?></h1>
        <?php if ($c !== null): ?>
            <p class="small muted">Alta: <?= e(fecha($c['fecha_alta']) ?: '—') ?>
                <?= $c['ultima_compra'] ? ' · Última compra: ' . e(fecha($c['ultima_compra'])) : '' ?>
                <?= $c['actualizacion'] ? ' · Modificado: ' . e(date('d/m/Y H:i', (int) strtotime((string) $c['actualizacion']))) : '' ?></p>
        <?php endif; ?>
    </div>
    <div class="acciones">
        <?php if ($c !== null && (int) $c['activo'] !== 1): ?><span class="badge badge-off">Inactivo</span><?php endif; ?>
        <?php if ($c !== null && puede('ctacte.ver')): ?><a class="btn btn-ghost" href="<?= e(url('/cuenta-corriente/cliente/' . (int) $c['codigo'])) ?>">Cuenta corriente</a><?php endif; ?>
    </div>
</div>
<?php if (isset($errores['general'])): ?><div class="alerta alerta-error"><?= e($errores['general']) ?></div><?php endif; ?>

<form method="post" action="<?= e(url($c === null ? '/clientes' : '/clientes/' . $c['codigo'])) ?>" class="card form" novalidate>
    <?= csrf_field() ?>
    <?php foreach ($grupos as $grupo => $campos): ?>
        <h2><?= e($grupo) ?></h2>
        <div class="grid-2">
            <?php foreach ($campos as $campo => $def): ?>
                <?= Form::campo($campo, $def, $datos[$campo] ?? null, $errores[$campo] ?? null, $refs[$campo] ?? [], $soloLectura) ?>
                <?php if ($campo === 'cuit' && isset($errores['cuit_repetido'])): ?>
                    <label class="check"><input type="checkbox" name="cuit_repetido" value="1"> Es otro cliente con el mismo CUIT</label>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($puedeEditar): ?>
        <div class="actions"><button class="btn" type="submit"><?= $c === null ? 'Crear cliente' : 'Guardar cambios' ?></button></div>
    <?php endif; ?>
</form>

<?php if ($c !== null && $puedeEditar): ?>
    <form method="post" action="<?= e(url('/clientes/' . $c['codigo'] . '/activo')) ?>" class="zona-peligro"
        <?= (int) $c['activo'] === 1 ? 'data-confirmar="El cliente no va a aparecer para facturar. ¿Lo desactivamos?"' : '' ?>>
        <?= csrf_field() ?>
        <input type="hidden" name="activo" value="<?= (int) $c['activo'] === 1 ? '0' : '1' ?>">
        <button class="btn btn-sm <?= (int) $c['activo'] === 1 ? 'btn-danger' : '' ?>" type="submit"><?= (int) $c['activo'] === 1 ? 'Desactivar cliente' : 'Activar cliente' ?></button>
    </form>
<?php endif; ?>
