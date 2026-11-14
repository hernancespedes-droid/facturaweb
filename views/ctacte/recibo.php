<?php
/** @var array<string,mixed> $c @var bool $puedeAnular */
$cli = $c['cliente_datos'];
$anulado = $c['estado'] === 'A';
?>
<p><a href="<?= e(url('/recibos')) ?>">← Recibos</a></p>
<div class="page-head">
    <div><p class="eyebrow"><?= e(fecha($c['fecha'])) ?><?= $anulado ? ' · ANULADO' : '' ?></p><h1>Recibo <?= e(numero_cbte($c['prefijo'], $c['recibo'])) ?></h1></div>
    <div class="acciones">
        <a class="btn" href="<?= e(url('/recibos/' . (int) $c['id'] . '/imprimir')) ?>" target="_blank" rel="noopener">Imprimir</a>
        <a class="btn btn-ghost" href="<?= e(url('/cuenta-corriente/cliente/' . (int) $cli['codigo'])) ?>">Cuenta del cliente</a>
        <?php if ($puedeAnular && !$anulado): ?>
            <form method="post" action="<?= e(url('/recibos/' . (int) $c['id'] . '/anular')) ?>" data-confirmar="Se anula el recibo: la deuda que cancelaba vuelve a su saldo y se borran sus medios de pago. ¿Seguimos?"><?= csrf_field() ?>
                <button class="btn btn-ghost" type="submit">Anular</button></form>
        <?php endif; ?>
    </div>
</div>
<?php if ($anulado): ?><div class="alerta alerta-warn">Recibo anulado: no cancela deuda ni cuenta como cobranza.</div><?php endif; ?>
<section class="card"><dl class="datos datos-col">
    <div><dt>Cliente</dt><dd><a href="<?= e(url('/clientes/' . (int) $cli['codigo'])) ?>"><?= e($cli['razon_social']) ?></a> <span class="muted">(<?= (int) $cli['codigo'] ?>)</span></dd></div>
    <div><dt>CUIT</dt><dd><?= e(cuit_texto($cli['cuit']) ?: 'sin CUIT') ?></dd></div>
    <div><dt>Se cancela</dt><dd><?= e(plata_dec($c['bruto'])) ?></dd></div>
    <?php if ($c['descuento'] > 0): ?><div><dt>Descuento</dt><dd>− <?= e(plata_dec($c['descuento'])) ?></dd></div><?php endif; ?>
    <?php foreach ($c['retenciones'] as $x): ?><div><dt>Retención <?= e($x['descripcion']) ?></dt><dd>− <?= e(plata_dec($x['importe'])) ?><?= $x['certificado'] !== '' ? ' <small class="muted">cert. ' . e($x['certificado']) . '</small>' : '' ?></dd></div><?php endforeach; ?>
    <div><dt>Cobrado</dt><dd><b><?= e(plata_dec($c['neto'])) ?></b></dd></div>
    <?php if ($c['anticipo'] > 0 && !$anulado): ?><div><dt>Saldo a favor (a cuenta)</dt><dd><?= e(plata_dec($c['anticipo'])) ?></dd></div><?php endif; ?>
    <?php if ($c['observaciones']): ?><div><dt>Observaciones</dt><dd><?= e($c['observaciones']) ?></dd></div><?php endif; ?>
</dl></section>
<?php if ($c['imputaciones'] !== []): ?>
<h2>Comprobantes que cancela</h2>
<div class="table-wrap"><table class="table table-cards"><thead><tr><th>Comprobante</th><th>Fecha</th><th class="num">Importe</th></tr></thead><tbody>
<?php foreach ($c['imputaciones'] as $i): ?>
    <tr><td data-label="Comprobante"><?= e(\App\Modules\CuentaCorriente\CuentaCorrienteService::TIPOS[$i['tipodb']] ?? $i['tipodb']) ?> <?= e($i['letradb']) ?> <?= e(numero_cbte($c['prefijo'], $i['numero_visible'] ?? $i['nrcodb'])) ?></td>
        <td data-label="Fecha"><?= e(fecha($i['fecodb'])) ?></td><td data-label="Importe" class="num nw"><?= e(plata_dec($i['importe'])) ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?>
<?php if ($c['pagos'] !== []): ?>
<h2>Cómo se pagó</h2>
<div class="table-wrap"><table class="table table-cards"><thead><tr><th>Forma</th><th>Detalle</th><th>Cobro</th><th class="num">Importe</th></tr></thead><tbody>
<?php foreach ($c['pagos'] as $p): ?>
    <tr><td data-label="Forma"><?= e($p['fpago_descripcion'] ?? $p['p_fpago']) ?></td>
        <td data-label="Detalle"><?= (int) ($p['fpago_tipo'] ?? 0) === 5 ? e('N.º ' . $p['p_nro'] . ' · ' . ($p['banco_descripcion'] ?? '')) : e((string) $p['p_nro']) ?></td>
        <td data-label="Cobro"><?= e(fecha($p['p_fecvto'])) ?></td><td data-label="Importe" class="num nw"><?= e(plata_dec($p['p_importe'])) ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?>
