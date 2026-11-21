<?php
use App\Modules\Compras\OrdenesPagoService as O;

/** Una orden de pago. @var array $o @var ?string $error @var bool $puedePagar @var bool $mailListo */
$m = static fn ($v) => number_format((float) $v, 2, ',', '.');
$vigente = $o['estado'] === 'V';
?>
<div class="page-head">
    <div><p class="eyebrow">Compras › Órdenes de pago</p><h1><?= e(O::numeroVisible((int) $o['numero'])) ?></h1></div>
    <div class="acciones">
        <a class="btn btn-ghost" href="<?= e(url('/compras/pagos/pdf/' . (int) $o['id'])) ?>" target="_blank" rel="noopener">PDF para imprimir</a>
        <?php if ($puedePagar): ?><a class="btn" href="<?= e(url('/compras/pagos/nueva?proveedor=' . (int) $o['proveedor_id'])) ?>">Otra orden a este proveedor</a><?php endif; ?>
    </div>
</div>
<?php if ($error !== null): ?><div class="alerta alerta-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if (!$vigente): ?><div class="alerta alerta-error">Anulada el <?= e(date('d/m/Y H:i', strtotime((string) $o['anulada_en']))) ?>: <?= e((string) $o['motivo_anulacion']) ?></div><?php endif; ?>

<section class="card resumen-fila" aria-label="Resumen">
    <div><span class="muted small">Proveedor</span><b><a href="<?= e(url('/compras/proveedores/cuenta?proveedor=' . (int) $o['proveedor_id'])) ?>"><?= e($o['razon_social']) ?></a></b></div>
    <div><span class="muted small">Fecha</span><b><?= e(fecha($o['fecha'])) ?></b></div>
    <div><span class="muted small">Total pagado</span><b>$ <?= e($m($o['pagado'])) ?></b></div>
    <?php if ((float) $o['retenciones'] > 0.005): ?><div><span class="muted small">Retenciones</span><b>$ <?= e($m($o['retenciones'])) ?></b></div><?php endif; ?>
    <?php if ((float) $o['anticipo'] > 0.005): ?><div><span class="muted small">Queda a favor</span><b>$ <?= e($m($o['anticipo'])) ?></b>
        <span class="small muted"><?= (float) $o['anticipo_saldo'] < (float) $o['anticipo'] - 0.005 ? 'usado: $ ' . e($m((float) $o['anticipo'] - (float) $o['anticipo_saldo'])) : 'sin usar' ?></span></div><?php endif; ?>
</section>

<?php if ($o['imputaciones'] !== []): ?>
<h2>Qué cancela</h2>
<div class="table-wrap"><table class="table table-cards">
    <thead><tr><th>Comprobante</th><th>Fecha</th><th>Vence</th><th class="num">Importe</th></tr></thead>
    <tbody><?php foreach ($o['imputaciones'] as $i): ?>
        <tr><td data-label="Comprobante"><?= $i['compra_id'] !== null ? '<a href="' . e(url('/compras/ver/' . (int) $i['compra_id'])) . '">' . e($i['titulo']) . '</a>' : e($i['titulo']) ?></td>
            <td data-label="Fecha"><?= $i['fecha'] ? e(fecha($i['fecha'])) : '' ?></td><td data-label="Vence"><?= $i['vence'] ? e(fecha($i['vence'])) : '' ?></td>
            <td data-label="Importe" class="num"><?= $i['credito'] ? '− ' : '' ?><?= e($m($i['importe'])) ?></td></tr>
    <?php endforeach; ?></tbody>
    <tfoot><tr><th colspan="3">Cancela <?= e($m($o['debitos'])) ?><?= (float) $o['creditos'] > 0.005 ? ' · descuenta ' . e($m($o['creditos'])) : '' ?></th><th class="num"><?= e($m((float) $o['debitos'] - (float) $o['creditos'])) ?></th></tr></tfoot>
</table></div>
<?php endif; ?>

<h2>Medios de pago</h2>
<?php if ($o['medios'] === []): ?><p class="muted">Sin medios: las notas de crédito cubrieron lo que se canceló.</p><?php else: ?>
<div class="table-wrap"><table class="table table-cards">
    <thead><tr><th>Medio</th><th>Detalle</th><th class="num">Importe</th></tr></thead>
    <tbody><?php foreach ($o['medios'] as $x): ?>
        <tr><td data-label="Medio"><?= e(O::MEDIOS[$x['medio']] ?? $x['medio']) ?></td><td data-label="Detalle"><?= e(O::detalleMedio($x)) ?></td><td data-label="Importe" class="num"><?= e($m($x['importe'])) ?></td></tr>
    <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
<?php if (trim((string) $o['observaciones']) !== ''): ?><p><b>Observaciones:</b> <?= e($o['observaciones']) ?></p><?php endif; ?>
<p class="small muted">Emitida por <?= e($o['usuario'] ?? '—') ?> el <?= e(date('d/m/Y H:i', strtotime((string) $o['creado_en']))) ?>.</p>

<?php if ($mailListo): ?>
<form method="post" action="<?= e(url('/compras/pagos/mail')) ?>" class="card toolbar"><?= csrf_field() ?>
    <input type="hidden" name="orden" value="<?= (int) $o['id'] ?>">
    <label class="field"><span>Mandar el PDF por mail a</span><input type="email" name="para" value="<?= e((string) $o['email']) ?>" required maxlength="120"></label>
    <button class="btn btn-ghost" type="submit">Enviar</button>
</form>
<?php endif; ?>

<?php if ($vigente && $puedePagar): ?>
<form method="post" action="<?= e(url('/compras/pagos/anular')) ?>" class="toolbar"><?= csrf_field() ?>
    <input type="hidden" name="orden" value="<?= (int) $o['id'] ?>">
    <input name="motivo" maxlength="160" required placeholder="Motivo de la anulación" aria-label="Motivo de la anulación">
    <button class="btn btn-ghost" type="submit">Anular orden de pago</button>
</form>
<p class="small muted">Anular deja otra vez pendientes los comprobantes, devuelve los cheques a la cartera y el efectivo a la caja (contra-asiento en tu turno).</p>
<?php endif; ?>
