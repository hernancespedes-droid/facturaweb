<?php
use App\Modules\Compras\ComprasService as S;

/** Subdiario de IVA compras de un período. @var string $periodo @var array $r */
$u = url('/compras/iva');
$m = static fn ($v) => number_format((float) $v, 2, ',', '.');
$t = $r['totales'];
$ant = date('Y-m', strtotime($periodo . '-01 -1 month'));
$sig = date('Y-m', strtotime($periodo . '-01 +1 month'));
?>
<div class="page-head">
    <div><p class="eyebrow">Compras</p><h1>Subdiario de IVA compras</h1></div>
    <div class="acciones">
        <a class="btn btn-ghost" href="<?= e($u . '?periodo=' . $ant) ?>" aria-label="Período anterior">‹</a>
        <span class="btn btn-ghost"><?= e(substr($periodo, 5) . '/' . substr($periodo, 0, 4)) ?></span>
        <a class="btn btn-ghost" href="<?= e($u . '?periodo=' . $sig) ?>" aria-label="Período siguiente">›</a>
        <a class="btn btn-ghost" href="<?= e($u . '?periodo=' . $periodo . '&excel=1') ?>">Excel</a>
    </div>
</div>
<p class="small muted">Por período de IVA (el que se eligió al cargar cada compra). Sin los gastos sin comprobante; las notas de crédito restan. El crédito fiscal es el IVA de los comprobantes A y M.</p>
<section class="kpis">
    <div class="kpi"><span>Crédito fiscal IVA</span><b><?= e('$ ' . $m($t['credito'])) ?></b></div>
    <div class="kpi"><span>Percepciones de IVA</span><b><?= e('$ ' . $m($t['perc_iva'])) ?></b></div>
    <div class="kpi"><span>Percepciones de Ingresos Brutos</span><b><?= e('$ ' . $m($t['perc_iibb'])) ?></b></div>
    <div class="kpi"><span>Total comprado</span><b><?= e('$ ' . $m($t['total'])) ?></b><span class="small muted"><?= count($r['filas']) ?> comprobantes</span></div>
</section>
<?php if ($r['filas'] === []): ?>
    <p class="muted vacio">No hay compras en este período.</p>
<?php else: ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th>Fecha</th><th>Comprobante</th><th>Proveedor</th><th>CUIT</th><th class="num">Neto gravado</th><th class="num">No gravado y exento</th>
            <?php foreach (array_keys(S::ALICUOTAS) as $k): ?><th class="num">IVA <?= e(str_replace('.', ',', (string) $k)) ?> %</th><?php endforeach; ?>
            <th class="num">Perc. IVA</th><th class="num">Perc. IIBB</th><th class="num">Otros</th><th class="num">Total</th></tr></thead>
        <tbody><?php foreach ($r['filas'] as $x): ?>
            <tr><td data-label="Fecha" class="nw"><?= e(fecha($x['fecha'])) ?></td>
                <td data-label="Comprobante"><a href="<?= e(url('/compras/ver/' . (int) $x['id'])) ?>"><?= e(S::titulo($x)) ?></a></td>
                <td data-label="Proveedor"><?= e($x['razon_social']) ?></td><td data-label="CUIT" class="nw"><?= e(cuit_texto($x['cuit'])) ?></td>
                <td data-label="Neto gravado" class="num"><?= e($m($x['gravado'])) ?></td>
                <td data-label="No gravado y exento" class="num"><?= e($m($x['no_gravado'] + $x['exento'])) ?></td>
                <?php foreach (array_keys(S::ALICUOTAS) as $k): $c = 'iva_' . str_replace('.', '_', (string) $k); ?><td data-label="IVA <?= e($k) ?> %" class="num"><?= abs($x[$c]) > 0.005 ? e($m($x[$c])) : '' ?></td><?php endforeach; ?>
                <td data-label="Perc. IVA" class="num"><?= abs($x['perc_iva']) > 0.005 ? e($m($x['perc_iva'])) : '' ?></td>
                <td data-label="Perc. IIBB" class="num"><?= abs($x['perc_iibb']) > 0.005 ? e($m($x['perc_iibb'])) : '' ?></td>
                <td data-label="Otros" class="num"><?= abs($x['perc_otras']) > 0.005 ? e($m($x['perc_otras'])) : '' ?></td>
                <td data-label="Total" class="num"><b><?= e($m($x['total'])) ?></b></td></tr>
        <?php endforeach; ?></tbody>
        <tfoot><tr><th colspan="4">Totales</th><th class="num"><?= e($m($t['gravado'])) ?></th><th class="num"><?= e($m($t['no_gravado'] + $t['exento'])) ?></th>
            <?php foreach (array_keys(S::ALICUOTAS) as $k): ?><th class="num"><?= e($m($t['iva_' . str_replace('.', '_', (string) $k)])) ?></th><?php endforeach; ?>
            <th class="num"><?= e($m($t['perc_iva'])) ?></th><th class="num"><?= e($m($t['perc_iibb'])) ?></th><th class="num"><?= e($m($t['perc_otras'])) ?></th><th class="num"><?= e($m($t['total'])) ?></th></tr></tfoot>
    </table></div>
<?php endif; ?>
