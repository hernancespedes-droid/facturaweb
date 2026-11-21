<?php
use App\Core\Form;
use App\Modules\Articulos\ArticulosController as C;
use App\Modules\Articulos\ArticulosService as S;

/** @var array<string,mixed>|null $a */
/** @var array<string,mixed> $datos */
/** @var array<string,string> $errores */
/** @var array<string,array<string,string>> $refs */
/** @var bool $puedeEditar */
/** @var bool $puedePrecios */
/** @var array<string,mixed> $precios datos para recalcular en el navegador (Precios::datosFicha) */
/** @var array<int,array<string,mixed>> $historial */
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
<form method="post" action="<?= e($a === null ? url('/articulos') : C::urlFicha((string) $a['codigo'])) ?>" class="card form" novalidate data-precios="<?= e((string) json_encode($precios)) ?>">
    <?= csrf_field() ?>
    <?php foreach ($grupos as $grupo => $campos): ?>
        <h2><?= e($grupo) ?></h2>
        <div class="grid-2">
            <?php if ($grupo === 'Datos'): ?>
                <?= Form::campo('codigo', ['label' => 'Código', 'tipo' => 'texto', 'largo' => 15, 'requerido' => true,
                    'ayuda' => $a === null ? 'Hasta 15 caracteres. No se puede cambiar después.' : null], $a['codigo'] ?? ($datos['codigo'] ?? ''), $errores['codigo'] ?? null, [], $a !== null || !$puedeEditar) ?>
            <?php endif; ?>
            <?php foreach ($campos as $campo => $def): ?>
                <?= Form::campo($campo, $def, $datos[$campo] ?? null, $errores[$campo] ?? null, $refs[$campo] ?? [],
                    !$puedeEditar || (!$puedePrecios && in_array($campo, S::CAMPOS_PRECIOS, true))) ?>
            <?php endforeach; ?>
        </div>
        <?php if ($grupo === 'Precios'): ?>
            <div class="precios-calculo" data-precios-resumen hidden aria-live="polite"></div>
            <?php if (!$puedePrecios): ?><p class="small muted">El costo, los márgenes y el precio automático los cambia quien tiene el permiso «Artículos: márgenes, costo…».</p><?php endif; ?>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($puedeEditar): ?>
        <div class="actions"><button class="btn" type="submit"><?= $a === null ? 'Crear artículo' : 'Guardar cambios' ?></button></div>
    <?php endif; ?>
</form>

<?php if ($historial !== []):
    $m = static fn ($v) => number_format((float) $v, 2, ',', '.');
    $par = static fn (array $h, string $k) => abs((float) $h[$k . '_anterior'] - (float) $h[$k]) < 0.00005 ? e($m($h[$k])) : '<s class="muted">' . e($m($h[$k . '_anterior'])) . '</s> ' . e($m($h[$k])); ?>
<section class="card" id="historial-precios">
    <h2>Cambios de costo y precios</h2>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th>Fecha</th><th>Origen</th><th>Usuario</th><th class="num">Costo</th><th class="num">Lista 1</th><th class="num">Lista 2</th><th class="num">Lista 3</th><th class="num">Oferta</th></tr></thead>
        <tbody>
        <?php foreach ($historial as $h): ?>
            <tr><td data-label="Fecha" class="nw"><?= e(date('d/m/Y H:i', (int) strtotime((string) $h['fecha']))) ?></td>
                <td data-label="Origen"><?= e(\App\Modules\Articulos\Precios::ORIGENES[$h['origen']] ?? $h['origen']) ?><?= $h['detalle'] !== '' ? ' <span class="small muted">' . e($h['detalle']) . '</span>' : '' ?></td>
                <td data-label="Usuario"><?= e((string) ($h['usuario'] ?? '')) ?></td>
                <td data-label="Costo" class="num"><?= $par($h, 'costo') ?></td>
                <td data-label="Lista 1" class="num"><?= $par($h, 'l1') ?></td>
                <td data-label="Lista 2" class="num"><?= $par($h, 'l2') ?></td>
                <td data-label="Lista 3" class="num"><?= $par($h, 'l3') ?></td>
                <td data-label="Oferta" class="num"><?= $par($h, 'oferta') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php endif; ?>
<script src="<?= e(asset('assets/articulo-precios.js')) ?>" defer></script>
