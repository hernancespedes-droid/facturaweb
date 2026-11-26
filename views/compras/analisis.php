<?php
use App\Modules\Compras\ComprasListados as L;

/** Análisis de gastos. @var array $f @var array $r @var bool $porMes @var ?array $prov @var array $centros @var array $conceptos */
$u = url('/compras/analisis');
$m = static fn ($v) => number_format((float) $v, 2, ',', '.');
$pct = static fn ($v) => $v === null ? '—' : number_format((float) $v, 1, ',', '.') . ' %';
[$titulo] = L::AGRUPAR[$r['agrupar']];
?>
<div class="page-head">
    <div><p class="eyebrow">Compras</p><h1>Análisis de gastos</h1></div>
    <div class="acciones"><a class="btn btn-ghost" href="<?= e($u . '?' . http_build_query(['excel' => 1] + $f)) ?>">Excel</a></div>
</div>
<p class="small muted">Cuánto se gastó, agrupado como elijas. Se suma el costo de cada compra repartido entre los centros (sin IVA computable ni percepciones); las notas de crédito restan y las anuladas no cuentan.</p>
<form method="get" action="<?= e($u) ?>" class="toolbar filtros">
    <label class="field"><span>Desde</span><input name="desde" value="<?= e($f['desde'] ?? '') ?>" placeholder="dd/mm/aaaa" data-fecha inputmode="numeric"></label>
    <label class="field"><span>Hasta</span><input name="hasta" value="<?= e($f['hasta'] ?? '') ?>" placeholder="dd/mm/aaaa" data-fecha inputmode="numeric"></label>
    <label class="field"><span>Agrupar por</span><select name="agrupar">
        <?php foreach (L::AGRUPAR as $k => $a): ?><option value="<?= e($k) ?>" <?= $r['agrupar'] === $k ? 'selected' : '' ?>><?= e($a[0]) ?></option><?php endforeach; ?></select></label>
    <?php $nombre = 'proveedor'; $titulo2 = $titulo; $titulo = 'Proveedor'; include __DIR__ . '/_proveedor.php'; $titulo = $titulo2; ?>
    <?php include __DIR__ . '/_filtros_centro.php'; ?>
    <label class="check"><input type="checkbox" name="por_mes" value="1" <?= ($f['por_mes'] ?? '') === '1' ? 'checked' : '' ?>> Una columna por mes</label>
    <button class="btn" type="submit">Ver</button>
</form>

<?php if ($r['filas'] === []): ?>
    <p class="muted vacio">No hay gastos para esos filtros.</p>
<?php else: ?>
    <section class="card resumen-fila"><div><span class="muted small">Total del período</span><b><?= e('$ ' . $m($r['total'])) ?></b></div>
        <div><span class="muted small"><?= e($titulo) ?></span><b><?= count($r['filas']) ?></b></div></section>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th><?= e($titulo) ?></th>
            <?php if ($porMes): foreach ($r['meses'] as $ym): ?><th class="num"><?= e(L::mesCorto($ym)) ?></th><?php endforeach; endif; ?>
            <th class="num">Total</th><th class="num">% del total</th><th class="num">Comprobantes</th></tr></thead>
        <tbody><?php foreach ($r['filas'] as $x): ?>
            <tr><td data-label="<?= e($titulo) ?>"><?= e($r['agrupar'] === 'mes' ? L::mesCorto($x['grupo']) : $x['nombre']) ?></td>
                <?php if ($porMes): foreach ($r['meses'] as $ym): ?><td data-label="<?= e(L::mesCorto($ym)) ?>" class="num"><?= isset($x['meses'][$ym]) ? e($m($x['meses'][$ym])) : '' ?></td><?php endforeach; endif; ?>
                <td data-label="Total" class="num"><b><?= e($m($x['total'])) ?></b></td>
                <td data-label="% del total" class="num"><?= e($pct($x['pct'])) ?></td>
                <td data-label="Comprobantes" class="num"><?= (int) $x['comprobantes'] ?></td></tr>
        <?php endforeach; ?></tbody>
        <tfoot><tr><th>Total</th>
            <?php if ($porMes): foreach ($r['meses'] as $ym): ?><th class="num"><?= e($m($r['totales_mes'][$ym] ?? 0)) ?></th><?php endforeach; endif; ?>
            <th class="num"><?= e($m($r['total'])) ?></th><th class="num">100 %</th><th></th></tr></tfoot>
    </table></div>
<?php endif; ?>
<script src="<?= e(asset('assets/ventana-lista.js')) ?>" defer></script>
<script src="<?= e(asset('assets/cliente-picker.js')) ?>" defer></script>
