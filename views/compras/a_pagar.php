<?php
use App\Modules\Compras\ComprasService as S;

/** A pagar y vencimientos. @var array $f @var array $r @var ?array $prov @var bool $puedePagar @var ?array $turno */
$u = url('/compras/a-pagar');
$m = static fn ($v) => number_format((float) $v, 2, ',', '.');
$t = $r['totales'];
$tramos = ['por_vencer' => 'Por vencer', 'd30' => 'Vencido 1 a 30 días', 'd60' => 'Vencido 31 a 60', 'mas60' => 'Vencido más de 60'];
?>
<div class="page-head">
    <div><p class="eyebrow">Compras</p><h1>A pagar y vencimientos</h1></div>
    <div class="acciones"><a class="btn btn-ghost" href="<?= e($u . '?' . http_build_query(['excel' => 1] + $f)) ?>">Excel</a></div>
</div>
<form method="get" action="<?= e($u) ?>" class="toolbar filtros">
    <?php $nombre = 'proveedor'; $titulo = 'Proveedor'; include __DIR__ . '/_proveedor.php'; ?>
    <label class="field"><span>Que venzan hasta</span><input name="vence_hasta" value="<?= e($f['vence_hasta'] ?? '') ?>" placeholder="dd/mm/aaaa" inputmode="numeric"></label>
    <button class="btn" type="submit">Ver</button>
</form>

<section class="kpis" aria-label="Resumen">
    <div class="kpi"><span>Le debemos en total</span><b><?= e('$ ' . $m($t['saldo'])) ?></b></div>
    <div class="kpi"><span>Vence en los próximos 7 días</span><b><?= e('$ ' . $m($t['semana'])) ?></b><span class="small muted"><?= (int) $t['vence_semana_cant'] ?> comprobantes</span></div>
    <div class="kpi"><span>Vencido</span><b><?= e('$ ' . $m($t['d30'] + $t['d60'] + $t['mas60'])) ?></b>
        <span class="small"><?= ($t['d30'] + $t['d60'] + $t['mas60']) > 0.005 ? '<span class="pill pill-mal">hay que ponerse al día</span>' : '<span class="pill pill-ok">nada vencido</span>' ?></span></div>
</section>

<?php if ($r['detalle'] === []): ?>
    <p class="muted vacio">No hay nada pendiente de pago.</p>
<?php else: ?>
    <h2>Saldos por proveedor</h2>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th>Proveedor</th><th class="num">Saldo</th><?php foreach ($tramos as $tt): ?><th class="num"><?= e($tt) ?></th><?php endforeach; ?><th>Próximo vencimiento</th><th>CBU / alias</th></tr></thead>
        <tbody><?php foreach ($r['proveedores'] as $p): ?>
            <tr><td data-label="Proveedor"><a href="<?= e(url('/compras/proveedores/ficha?id=' . (int) $p['id'])) ?>"><?= e($p['razon_social']) ?></a></td>
                <td data-label="Saldo" class="num"><b><?= e($m($p['saldo'])) ?></b></td>
                <?php foreach (array_keys($tramos) as $k): ?><td data-label="<?= e($tramos[$k]) ?>" class="num"><?= abs($p[$k]) > 0.005 ? e($m($p[$k])) : '' ?></td><?php endforeach; ?>
                <td data-label="Próximo vencimiento"><?= $p['proximo'] ? e(fecha($p['proximo'])) : '' ?></td>
                <td data-label="CBU / alias" class="small"><?= e($p['cbu_alias']) ?></td></tr>
        <?php endforeach; ?></tbody>
        <tfoot><tr><th>Total</th><th class="num"><?= e($m($t['saldo'])) ?></th><?php foreach (array_keys($tramos) as $k): ?><th class="num"><?= e($m($t[$k])) ?></th><?php endforeach; ?><th></th><th></th></tr></tfoot>
    </table></div>

    <h2>Comprobantes por vencimiento</h2>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th>Vence</th><th>Proveedor</th><th>Comprobante</th><th class="num">Saldo</th><th>Estado</th><?php if ($puedePagar): ?><th>Pagar</th><?php endif; ?></tr></thead>
        <tbody><?php foreach ($r['detalle'] as $x): $d = (int) $x['dias']; ?>
            <tr><td data-label="Vence" class="nw"><?= $x['vence'] ? e(fecha($x['vence'])) : e(fecha($x['fecha'])) ?></td>
                <td data-label="Proveedor"><?= e($x['razon_social']) ?></td>
                <td data-label="Comprobante"><a href="<?= e(url('/compras/ver/' . (int) $x['id'])) ?>"><?= e(S::titulo($x)) ?></a></td>
                <td data-label="Saldo" class="num"><b><?= e($m($x['saldo_signo'])) ?></b></td>
                <td data-label="Estado"><?= $x['tipo'] === 'NC' ? '<span class="pill">a favor</span>' : ($d > 0 ? '<span class="pill pill-mal">vencida hace ' . $d . ' días</span>' : ($d >= -7 ? '<span class="pill pill-warn">' . ($d === 0 ? 'vence hoy' : 'vence en ' . -$d . ' días') . '</span>' : '<span class="pill pill-ok">al día</span>')) ?></td>
                <?php if ($puedePagar): ?><td data-label="Pagar"><a class="btn btn-sm" href="<?= e(url('/compras/pagos/nueva?compra=' . (int) $x['id'])) ?>"><?= $x['tipo'] === 'NC' ? 'Descontar' : 'Pagar' ?></a></td><?php endif; ?></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <p class="small muted">Se paga con una orden de pago: ahí se pueden sumar otras facturas del mismo proveedor y descontar sus notas de crédito y anticipos.</p>
<?php endif; ?>
<script src="<?= e(asset('assets/ventana-lista.js')) ?>" defer></script>
<script src="<?= e(asset('assets/cliente-picker.js')) ?>" defer></script>
