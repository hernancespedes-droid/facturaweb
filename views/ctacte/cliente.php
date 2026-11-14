<?php
/** @var array<string,mixed> $cli @var array<string,string> $f @var array<string,mixed> $m @var array{debitos:array,creditos:array} $pend @var float $saldo */
$nombre = static fn (array $x): string => (\App\Modules\CuentaCorriente\CuentaCorrienteService::TIPOS[$x['tipo']] ?? $x['tipo']) . ' ' . $x['letra'];
$hoy = date('Y-m-d');
?>
<p><a href="<?= e(url('/cuenta-corriente')) ?>">← Cuenta corriente</a></p>
<div class="page-head">
    <div><p class="eyebrow">Cuenta corriente · cliente <?= (int) $cli['codigo'] ?><?= $cli['cuit'] ? ' · CUIT ' . e(cuit_texto($cli['cuit'])) : '' ?></p><h1><?= e($cli['razon_social']) ?></h1></div>
    <div class="acciones">
        <?php if (puede('ctacte.cobrar')): ?><a class="btn" href="<?= e(url('/recibos/nuevo?cliente=' . (int) $cli['codigo'])) ?>">+ Recibo</a><?php endif; ?>
        <?php if (puede('ctacte.imputar')): ?><a class="btn btn-ghost" href="<?= e(url('/cuenta-corriente/imputar?cliente=' . (int) $cli['codigo'])) ?>">Imputar</a><?php endif; ?>
        <?php if (puede('ctacte.ajustar')): ?><a class="btn btn-ghost" href="<?= e(url('/cuenta-corriente/ajuste?cliente=' . (int) $cli['codigo'])) ?>">Ajuste</a><?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('/cuenta-corriente/cliente/' . (int) $cli['codigo']) . '?' . http_build_query($f + ['excel' => '1'])) ?>">Excel</a>
    </div>
</div>
<section class="card"><dl class="datos datos-col">
    <div><dt>Saldo</dt><dd><b class="<?= $saldo > 0 ? 'texto-error' : '' ?>"><?= e(plata_dec($saldo)) ?></b> <span class="muted"><?= $saldo > 0 ? '(debe)' : ($saldo < 0 ? '(a favor del cliente)' : '') ?></span></dd></div>
    <div><dt>Comprobantes con saldo</dt><dd><?= count($pend['debitos']) ?> de deuda · <?= count($pend['creditos']) ?> a favor</dd></div>
</dl></section>

<?php if ($pend['debitos'] !== [] || $pend['creditos'] !== []): ?>
<h2>Pendiente</h2>
<div class="table-wrap"><table class="table table-cards">
    <thead><tr><th>Fecha</th><th>Comprobante</th><th>Vence</th><th class="num">Importe</th><th class="num">Saldo</th></tr></thead>
    <tbody>
    <?php foreach (array_merge($pend['debitos'], $pend['creditos']) as $x): $deuda = (int) $x['tico'] < 7; $vencido = $deuda && $x['vencimiento'] !== null && $x['vencimiento'] < $hoy; ?>
        <tr><td data-label="Fecha" class="nw"><?= e(fecha($x['fecha'])) ?></td>
            <td data-label="Comprobante" class="nw"><?= e($nombre($x)) ?> <?= e(numero_cbte($x['prefijo'], $x['numero_visible'])) ?></td>
            <td data-label="Vence" class="nw"><?= $deuda ? e(fecha($x['vencimiento'])) : '' ?><?= $vencido ? ' <span class="badge badge-warn">vencido</span>' : '' ?></td>
            <td data-label="Importe" class="num nw"><?= e(plata_dec((float) $x['neto'] + (float) $x['descuento'])) ?></td>
            <td data-label="Saldo" class="num nw"><b><?= $deuda ? '' : '−' ?><?= e(plata_dec($x['saldo'])) ?></b></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>

<h2>Movimientos</h2>
<form method="get" action="<?= e(url('/cuenta-corriente/cliente/' . (int) $cli['codigo'])) ?>" class="toolbar filtros">
    <label class="field"><span>Desde</span><input name="desde" value="<?= e($f['desde'] ?? '') ?>" placeholder="dd/mm/aaaa" inputmode="numeric"></label>
    <label class="field"><span>Hasta</span><input name="hasta" value="<?= e($f['hasta'] ?? '') ?>" placeholder="dd/mm/aaaa" inputmode="numeric"></label>
    <button class="btn btn-ghost" type="submit">Ver</button>
</form>
<?php if ($m['filas'] === []): ?>
    <p class="muted vacio">No hay movimientos en ese período.</p>
<?php else: ?>
<div class="table-wrap"><table class="table table-cards">
    <thead><tr><th>Fecha</th><th>Comprobante</th><th class="num">Debe</th><th class="num">Haber</th><th class="num">Saldo</th></tr></thead>
    <tbody>
    <?php if ($m['inicial'] != 0.0): ?><tr><td colspan="4"><i>Saldo anterior</i></td><td class="num nw"><?= e(plata_dec($m['inicial'])) ?></td></tr><?php endif; ?>
    <?php foreach ($m['filas'] as $x): ?>
        <tr><td data-label="Fecha" class="nw"><?= e(fecha($x['fecha'])) ?></td>
            <td data-label="Comprobante"><?php if ($x['tipo'] === 'RC' && $x['cobra_id']): ?><a href="<?= e(url('/recibos/' . (int) $x['cobra_id'])) ?>"><?= e($nombre($x)) ?> <?= e(numero_cbte($x['prefijo'], $x['numero'])) ?></a>
                <?php else: ?><?= e($nombre($x)) ?> <?= e(numero_cbte($x['prefijo'], $x['numero_visible'])) ?><?php endif; ?>
                <?= $x['observaciones'] ? '<small class="muted">· ' . e($x['observaciones']) . '</small>' : '' ?></td>
            <td data-label="Debe" class="num nw"><?= $x['debe'] > 0 ? e(plata_dec($x['debe'])) : '' ?></td>
            <td data-label="Haber" class="num nw"><?= $x['haber'] > 0 ? e(plata_dec($x['haber'])) : '' ?></td>
            <td data-label="Saldo" class="num nw"><b><?= e(plata_dec($x['acumulado'])) ?></b></td></tr>
    <?php endforeach; ?>
    <tr><td colspan="2"><b>Totales del período</b></td><td class="num nw"><b><?= e(plata_dec($m['debe'])) ?></b></td><td class="num nw"><b><?= e(plata_dec($m['haber'])) ?></b></td><td class="num nw"><b><?= e(plata_dec($m['final'])) ?></b></td></tr>
    </tbody>
</table></div>
<?php endif; ?>
