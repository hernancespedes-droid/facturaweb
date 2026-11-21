<?php
use App\Modules\Compras\ComprasListados as CL;

/** Resultado simple del mes: ventas menos compras y gastos. @var string $periodo @var array $r @var bool $verCostos */
$u = url('/compras/resultado');
$m = static fn ($v) => number_format((float) $v, 2, ',', '.');
$ant = date('Y-m', (int) strtotime($periodo . '-01 -1 month'));
$sig = date('Y-m', (int) strtotime($periodo . '-01 +1 month'));
$mes = null;
foreach ($r['meses'] as $x) {
    if ($x['mes'] === $periodo) {
        $mes = $x;
    }
}
$signo = static fn (float $v) => $v >= 0 ? 'resultado-pos' : 'resultado-neg';
$corto = static function (float $v): string {
    $a = abs($v);
    $t = $a >= 1000000 ? number_format($a / 1000000, 1, ',', '.') . ' M' : ($a >= 1000 ? number_format($a / 1000, 0, ',', '.') . ' mil' : number_format($a, 0, ',', '.'));
    return ($v < 0 ? '-' : '') . $t;
};

// gráfico: ventas y compras por mes (columnas) con el resultado arriba
$W = 720; $H = 240; $x0 = 56; $y0 = 200;
$max = 0.0;
foreach ($r['meses'] as $x) {
    $max = max($max, $x['ventas'], $x['compras']);
}
$max = $max > 0 ? $max * 1.1 : 1.0;
$banda = ($W - $x0 - 10) / max(1, count($r['meses']));
$bw = min(16.0, $banda * 0.32);
$y = static fn (float $v) => round($y0 - max(0.0, $v) / $max * ($y0 - 16), 1);
?>
<div class="page-head">
    <div><p class="eyebrow">Compras</p><h1>Resultado del mes</h1></div>
    <div class="acciones">
        <a class="btn btn-ghost" href="<?= e($u . '?periodo=' . $ant) ?>" aria-label="Mes anterior">‹</a>
        <span class="btn btn-ghost"><?= e(substr($periodo, 5) . '/' . substr($periodo, 0, 4)) ?></span>
        <a class="btn btn-ghost" href="<?= e($u . '?periodo=' . $sig) ?>" aria-label="Mes siguiente">›</a>
    </div>
</div>
<p class="small muted">Ventas sin IVA (lo facturado, con presupuestos y descontando notas de crédito) menos compras y gastos (el costo cargado en compras, sin IVA
    computable ni percepciones). Es un resultado simple: no descuenta la mercadería que quedó en stock.</p>

<section class="kpis">
    <div class="kpi"><span>Ventas sin IVA</span><b>$ <?= e($m($mes['ventas'] ?? 0)) ?></b></div>
    <div class="kpi"><span>Compras y gastos</span><b>$ <?= e($m($mes['compras'] ?? 0)) ?></b></div>
    <div class="kpi"><span><?= ($mes['resultado'] ?? 0) >= 0 ? 'Ganancia' : 'Pérdida' ?></span><b class="<?= $signo((float) ($mes['resultado'] ?? 0)) ?>">$ <?= e($m(abs((float) ($mes['resultado'] ?? 0)))) ?></b>
        <?php if (($mes['margen'] ?? null) !== null): ?><span class="small muted"><?= e(str_replace('.', ',', (string) $mes['margen'])) ?> % de las ventas</span><?php endif; ?></div>
    <?php if ($verCostos && $mes !== null && $mes['ventas'] > 0): ?>
        <div class="kpi"><span>Margen de lo vendido</span><b>$ <?= e($m($mes['ventas'] - $mes['costo_ventas'])) ?></b><span class="small muted">venta menos costo de los artículos</span></div>
    <?php endif; ?>
</section>

<section class="grafico card">
    <h2>Últimos 12 meses</h2>
    <p class="small"><span class="pill" style="background:var(--g-marca, var(--brand));color:var(--on-brand)">Ventas</span> <span class="pill" style="background:var(--g-comp, #999);color:#fff">Compras y gastos</span></p>
    <svg class="g-svg" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="<?= e('Ventas contra compras: ' . implode('; ', array_map(static fn ($x) => CL::mesCorto($x['mes']) . ' resultado ' . $m($x['resultado']), $r['meses']))) ?>">
        <line x1="<?= $x0 ?>" y1="<?= $y0 ?>" x2="<?= $W - 10 ?>" y2="<?= $y0 ?>" stroke="currentColor" stroke-opacity=".3"/>
        <?php foreach ($r['meses'] as $i => $x): $bx = $x0 + $banda * $i + $banda / 2; ?>
            <rect x="<?= round($bx - $bw - 1, 1) ?>" y="<?= $y($x['ventas']) ?>" width="<?= $bw ?>" height="<?= round($y0 - $y($x['ventas']), 1) ?>" rx="3" fill="var(--g-marca, var(--brand))"><title><?= e(CL::mesCorto($x['mes']) . ' · ventas $ ' . $m($x['ventas'])) ?></title></rect>
            <rect x="<?= round($bx + 1, 1) ?>" y="<?= $y($x['compras']) ?>" width="<?= $bw ?>" height="<?= round($y0 - $y($x['compras']), 1) ?>" rx="3" fill="var(--g-comp, #999)"><title><?= e(CL::mesCorto($x['mes']) . ' · compras y gastos $ ' . $m($x['compras'])) ?></title></rect>
            <text x="<?= round($bx, 1) ?>" y="<?= $y0 + 16 ?>" text-anchor="middle" font-size="11" fill="currentColor" fill-opacity=".7"<?= $x['mes'] === $periodo ? ' font-weight="700"' : '' ?>><?= e(CL::mesCorto($x['mes'])) ?></text>
            <?php if (abs($x['resultado']) >= 0.5): ?><text x="<?= round($bx, 1) ?>" y="<?= $y0 + 32 ?>" text-anchor="middle" font-size="10" fill="<?= $x['resultado'] >= 0 ? 'var(--ok, #1e8e3e)' : 'var(--error, #c0392b)' ?>"><?= e($corto($x['resultado'])) ?></text><?php endif; ?>
        <?php endforeach; ?>
    </svg>
</section>

<div class="grid-2">
    <section class="card">
        <h2>Gastos del mes por concepto</h2>
        <?php if ($r['conceptos'] === []): ?><p class="muted">Sin compras en el mes.</p><?php else: ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Concepto</th><th class="num">Importe</th><th class="num">% de las ventas</th></tr></thead>
            <tbody><?php foreach ($r['conceptos'] as $c): ?><tr><td><?= e($c['nombre']) ?></td><td class="num"><?= e($m($c['importe'])) ?></td>
                <td class="num"><?= ($mes['ventas'] ?? 0) > 0 ? e(number_format($c['importe'] / $mes['ventas'] * 100, 1, ',', '.')) . ' %' : '' ?></td></tr><?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
    </section>
    <section class="card">
        <h2>Gastos del mes por centro</h2>
        <?php if ($r['centros'] === []): ?><p class="muted">Sin compras en el mes.</p><?php else: ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Centro</th><th class="num">Importe</th></tr></thead>
            <tbody><?php foreach ($r['centros'] as $c): ?><tr><td><?= e($c['nombre']) ?></td><td class="num"><?= e($m($c['importe'])) ?></td></tr><?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
    </section>
</div>

<h2>Mes a mes</h2>
<div class="table-wrap"><table class="table table-cards">
    <thead><tr><th>Mes</th><th class="num">Ventas sin IVA</th><th class="num">Compras y gastos</th><th class="num">Resultado</th><th class="num">% de las ventas</th></tr></thead>
    <tbody><?php foreach (array_reverse($r['meses']) as $x): ?>
        <tr><td data-label="Mes"><a href="<?= e($u . '?periodo=' . $x['mes']) ?>"><?= e(ucfirst(CL::mesCorto($x['mes']))) ?></a></td>
            <td data-label="Ventas" class="num"><?= e($m($x['ventas'])) ?></td><td data-label="Compras y gastos" class="num"><?= e($m($x['compras'])) ?></td>
            <td data-label="Resultado" class="num <?= $signo($x['resultado']) ?>"><?= e($m($x['resultado'])) ?></td>
            <td data-label="%" class="num"><?= $x['margen'] !== null ? e(str_replace('.', ',', (string) $x['margen'])) . ' %' : '' ?></td></tr>
    <?php endforeach; ?></tbody>
    <tfoot><tr><th>12 meses</th><th class="num"><?= e($m($r['total']['ventas'])) ?></th><th class="num"><?= e($m($r['total']['compras'])) ?></th>
        <th class="num <?= $signo($r['total']['resultado']) ?>"><?= e($m($r['total']['resultado'])) ?></th><th></th></tr></tfoot>
</table></div>
