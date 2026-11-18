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

<?php if ($a !== null): ?>
<section class="card" id="foto">
    <h2>Foto</h2>
    <div class="foto-articulo">
        <?php if ($foto !== null): ?>
            <a href="<?= e($foto['grande']) ?>" data-ampliar title="Tocá para ampliar y hacer zoom"><img src="<?= e($foto['chica']) ?>" alt="<?= e($a['descripcion']) ?>" class="foto-previa"></a>
        <?php else: ?>
            <p class="muted">Este artículo todavía no tiene foto.</p>
        <?php endif; ?>
        <?php if ($puedeEditar): ?>
            <form method="post" action="<?= e(url('/articulos/foto') . '?' . http_build_query(['codigo' => $a['codigo']])) ?>" enctype="multipart/form-data" class="toolbar">
                <?= csrf_field() ?>
                <label class="field"><span><?= $foto !== null ? 'Cambiar la foto' : 'Subir una foto' ?> (JPG, PNG o WEBP, hasta 8 MB)</span>
                    <input type="file" name="foto" accept="image/*" required></label>
                <button class="btn" type="submit">Subir foto</button>
            </form>
            <?php if ($foto !== null): ?>
                <form method="post" action="<?= e(url('/articulos/foto/quitar') . '?' . http_build_query(['codigo' => $a['codigo']])) ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn-ghost" type="submit">Quitar la foto</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>
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
