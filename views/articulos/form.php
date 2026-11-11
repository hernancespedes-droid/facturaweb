<?php
use App\Core\Form;
use App\Modules\Articulos\ArticulosController as C;
use App\Modules\Articulos\ArticulosService as S;

/** @var array<string,mixed>|null $a */
/** @var array<string,mixed> $datos */
/** @var array<string,string> $errores */
/** @var array<string,array<string,string>> $refs */
/** @var bool $puedeEditar */
$grupos = [];
foreach (S::campos() as $campo => $def) {
    $grupos[$def['grupo'] ?? 'Datos'][$campo] = $def;
}
?>
<p><a href="<?= e(url('/articulos')) ?>">← Artículos</a></p>
<div class="page-head">
    <div>
        <p class="eyebrow"><?= $a === null ? 'Nuevo artículo' : 'Artículo ' . e($a['codigo']) ?></p>
        <h1><?= e($a === null ? 'Nuevo artículo' : $a['descripcion']) ?></h1>
        <?php if ($a !== null && $a['actualizacion']): ?><p class="small muted">Modificado: <?= e(date('d/m/Y H:i', (int) strtotime((string) $a['actualizacion']))) ?></p><?php endif; ?>
    </div>
    <?php if ($a !== null && $a['estado'] !== 'AC'): ?><span class="badge badge-off"><?= e(S::ESTADOS[$a['estado']] ?? $a['estado']) ?></span><?php endif; ?>
</div>
<?php if (isset($errores['general'])): ?><div class="alerta alerta-error"><?= e($errores['general']) ?></div><?php endif; ?>

<form method="post" action="<?= e($a === null ? url('/articulos') : C::urlFicha((string) $a['codigo'])) ?>" class="card form" novalidate>
    <?= csrf_field() ?>
    <?php foreach ($grupos as $grupo => $campos): ?>
        <h2><?= e($grupo) ?></h2>
        <div class="grid-2">
            <?php if ($grupo === 'Datos'): ?>
                <?= Form::campo('codigo', ['label' => 'Código', 'tipo' => 'texto', 'largo' => 15, 'requerido' => true,
                    'ayuda' => $a === null ? 'Hasta 15 caracteres. No se puede cambiar después.' : null], $a['codigo'] ?? ($datos['codigo'] ?? ''), $errores['codigo'] ?? null, [], $a !== null || !$puedeEditar) ?>
            <?php endif; ?>
            <?php foreach ($campos as $campo => $def): ?>
                <?= Form::campo($campo, $def, $datos[$campo] ?? null, $errores[$campo] ?? null, $refs[$campo] ?? [], !$puedeEditar) ?>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($puedeEditar): ?>
        <div class="actions"><button class="btn" type="submit"><?= $a === null ? 'Crear artículo' : 'Guardar cambios' ?></button></div>
    <?php endif; ?>
</form>
