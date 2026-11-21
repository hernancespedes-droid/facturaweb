<?php
/** Presupuesto contra real de un mes. @var string $periodo @var array $r @var bool $puedeCentros */
$u = url('/compras/presupuesto');
$m = static fn ($v) => number_format((float) $v, 2, ',', '.');
$ant = date('Y-m', strtotime($periodo . '-01 -1 month'));
$sig = date('Y-m', strtotime($periodo . '-01 +1 month'));
$mes = \App\Modules\Compras\ComprasListados::mesCorto($periodo);
?>
<div class="page-head">
    <div><p class="eyebrow">Compras</p><h1>Presupuesto contra real</h1></div>
    <div class="acciones">
        <a class="btn btn-ghost" href="<?= e($u . '?periodo=' . $ant) ?>" aria-label="Mes anterior">‹</a>
        <span class="btn btn-ghost"><?= e(substr($periodo, 5) . '/' . substr($periodo, 0, 4)) ?></span>
        <a class="btn btn-ghost" href="<?= e($u . '?periodo=' . $sig) ?>" aria-label="Mes siguiente">›</a>
        <?php if ($puedeCentros): ?><a class="btn" href="<?= e(url('/compras/centros?periodo=' . $periodo . '#presupuesto')) ?>">Cargar presupuesto</a><?php endif; ?>
    </div>
</div>
<section class="kpis">
    <div class="kpi"><span>Presupuesto del mes</span><b><?= e('$ ' . $m($r['presupuesto'])) ?></b></div>
    <div class="kpi"><span>Gastado</span><b><?= e('$ ' . $m($r['gastado'])) ?></b></div>
    <div class="kpi"><span>Diferencia</span><b><?= e(($r['presupuesto'] - $r['gastado'] < 0 ? '−' : '') . '$ ' . $m(abs($r['presupuesto'] - $r['gastado']))) ?></b>
        <span class="small"><?= $r['con_presupuesto'] === 0 ? '<span class="muted">Sin presupuesto cargado</span>' : ($r['sobre'] > 0 ? '<span class="pill pill-mal">' . (int) $r['sobre'] . ' centros se pasaron</span>' : '<span class="pill pill-ok">Todos dentro del presupuesto</span>') ?></span></div>
</section>
<div class="table-wrap"><table class="table table-cards">
    <thead><tr><th>Centro</th><th class="num">Presupuesto</th><th class="num">Gastado</th><th class="num">Diferencia</th><th class="num">Uso</th><th>Semáforo</th></tr></thead>
    <tbody><?php foreach ($r['filas'] as $x): if ($x['activo'] === 0 && abs($x['gastado']) < 0.005) continue; $uso = $x['uso']; ?>
        <tr><td data-label="Centro" class="<?= $x['nivel'] ? 'centro-hijo' : '' ?>"><?= $x['nivel'] ? '— ' : '' ?><?= e($x['nombre']) ?></td>
            <td data-label="Presupuesto" class="num"><?= $x['presupuesto'] > 0.005 ? e($m($x['presupuesto'])) : '—' ?></td>
            <td data-label="Gastado" class="num"><?= e($m($x['gastado'])) ?></td>
            <td data-label="Diferencia" class="num"><?= $x['presupuesto'] > 0.005 ? '<span class="' . ($x['diferencia'] < 0 ? 'texto-error' : '') . '">' . e($m($x['diferencia'])) . '</span>' : '' ?></td>
            <td data-label="Uso" class="num"><?= $uso === null ? '' : e(number_format($uso, 0, ',', '.') . ' %') ?></td>
            <td data-label="Semáforo"><?php if ($uso !== null): ?><div class="medidor" title="<?= e(number_format($uso, 0) . ' % usado') ?>"><i class="<?= $uso > 100 ? 'pasado' : ($uso >= 90 ? 'alto' : '') ?>" style="width:<?= min(100, max(1, (int) round($uso))) ?>%"></i></div><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody>
</table></div>
<p class="small muted">Los centros principales suman lo de sus centros de segundo nivel. Verde: menos del 90 % usado; amarillo: del 90 al 100 %; rojo: se pasó.</p>
