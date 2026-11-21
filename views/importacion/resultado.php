<?php /** @var string $t @var array $r @var bool $sigue */
use App\Modules\Importacion\Destinos;
$n = static fn (int $x) => number_format($x, 0, ',', '.');
$mensajes = array_merge(array_map(static fn ($m) => [$m[0], 'Error', $m[1]], $r['errores']), array_map(static fn ($m) => [$m[0], 'Aviso', $m[1]], $r['avisos']));
usort($mensajes, static fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
$verbo = $r['simulado'] ? 'se agregarían' : 'se agregaron';
?>
<div class="page-head"><div><p class="eyebrow">Importar datos · <?= e(Destinos::TITULOS[$r['destino']] ?? '') ?></p><h1><?= $r['simulado'] ? 'Prueba (no se grabó nada)' : ($r['grabado'] ? 'Importación grabada' : 'No se importó nada') ?></h1></div></div>

<?php if ($r['grabado']): ?>
<div class="alerta alerta-ok" role="status">Listo: <?= $n($r['nuevas']) ?> nuevas y <?= $n($r['actualizadas']) ?> actualizadas de <b><?= e($r['nombre']) ?></b>.</div>
<?php if (!empty($r['preguntar_precios'])): ?>
<div class="alerta alerta-info" role="status">Cambió el costo de algunos artículos. <a class="btn" href="<?= e($r['preguntar_precios']) ?>">¿Actualizo los precios con los márgenes?</a></div>
<?php endif; ?>
<?php elseif (!$r['simulado'] && $r['con_error'] > 0): ?>
<div class="alerta alerta-error" role="alert">Hay <?= $n($r['con_error']) ?> fila(s) con error: no se grabó nada. Corregí el archivo, o volvé a las columnas y tildá «importar igual las demás».</div>
<?php elseif (!$r['simulado']): ?>
<div class="alerta alerta-error" role="alert">No había nada nuevo para grabar.</div>
<?php endif; ?>

<section class="card">
    <div class="resumen-cajas">
        <p><b><?= $n($r['filas']) ?></b> filas leídas</p>
        <p><b><?= $n($r['nuevas']) ?></b> <?= $r['simulado'] ? 'nuevas' : 'nuevas' ?></p>
        <p><b><?= $n($r['actualizadas']) ?></b> <?= $r['destino'] === 'stock' ? 'movimientos de stock' : 'actualizadas' ?></p>
        <p><b><?= $n($r['salteadas']) ?></b> salteadas<?= $r['destino'] === 'subdiario' ? ' (ya estaban)' : ($r['destino'] === 'stock' ? ' (sin cambio)' : '') ?></p>
        <p><b><?= $n($r['con_error']) ?></b> con error</p>
    </div>
    <?php if ($r['simulado']): ?><p class="small muted">Es una prueba: <?= $verbo ?> <?= $n($r['nuevas']) ?> y se actualizarían <?= $n($r['actualizadas']) ?>. Revisá los errores y avisos y, si está bien, importá.</p><?php endif; ?>
    <div class="acciones">
        <?php if ($sigue): ?>
        <a class="btn <?= $r['simulado'] && $r['con_error'] === 0 ? 'btn-ghost' : '' ?>" href="<?= e(url('/importar/columnas?t=' . $t)) ?>">Volver a las columnas</a>
        <?php if ($r['simulado'] && $r['con_error'] === 0 && ($r['nuevas'] + $r['actualizadas']) > 0): ?>
        <form method="post" action="<?= e(url('/importar/procesar')) ?>" data-confirmar="Se van a grabar los datos del archivo. ¿Importar?" class="inline"><?= csrf_field() ?>
            <input type="hidden" name="t" value="<?= e($t) ?>"><input type="hidden" name="repetir" value="1">
            <button class="btn" type="submit" name="accion" value="importar">Importar ahora</button></form>
        <?php endif; ?>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('/importar')) ?>">Importar otro archivo</a>
    </div>
</section>

<?php if ($mensajes !== []): ?>
<section class="card">
    <h2>Errores y avisos por fila</h2>
    <?php if (count($r['errores']) >= \App\Modules\Importacion\Importador::MAX_MENSAJES || count($r['avisos']) >= \App\Modules\Importacion\Importador::MAX_MENSAJES): ?><p class="small muted">Se muestran los primeros <?= \App\Modules\Importacion\Importador::MAX_MENSAJES ?> de cada tipo.</p><?php endif; ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th class="num">Fila</th><th>Tipo</th><th>Detalle</th></tr></thead>
        <tbody><?php foreach ($mensajes as $m): ?>
            <tr class="<?= $m[1] === 'Error' ? 'fila-error' : '' ?>"><td data-label="Fila" class="num"><?= (int) $m[0] ?></td><td data-label="Tipo"><?= e($m[1]) ?></td><td data-label="Detalle"><?= e($m[2]) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
</section>
<?php endif; ?>
