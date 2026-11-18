<?php
use App\Modules\Comprobantes\Tipos;

/** @var array<string,mixed> $c cbte armado */
/** @var string $datos JSON del formulario */
/** @var string $operacion */
$k = $c['calculo'];
$cli = $c['cliente'];
$disc = $c['letra'] === 'A';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Revisá antes de emitir · todavía no se grabó nada</p>
        <h1><?= e(Tipos::titulo($c['tipo'], $c['letra'])) ?> a <?= e($cli['razon_social']) ?></h1>
    </div>
</div>

<?php if (!empty($c['advertencias'])): ?>
<div class="alerta alerta-info" role="status"><b>Stock:</b> <?= e(implode(' · ', $c['advertencias'])) ?></div>
<?php endif; ?>
<section class="card">
    <dl class="datos datos-col">
        <div><dt>Cliente</dt><dd><?= e($cli['razon_social']) ?> <span class="muted">(<?= e($cli['codigo']) ?>)</span></dd></div>
        <div><dt>CUIT</dt><dd><?= e(cuit_texto($cli['cuit']) ?: 'sin CUIT') ?></dd></div>
        <div><dt>Fecha</dt><dd><?= e(fecha($c['fecha'])) ?> <span class="muted small">(siempre la de hoy)</span></dd></div>
        <div><dt>Punto de venta</dt><dd><?= e(sprintf('%04d', $c['prefijo'])) ?></dd></div>
        <?php if ($c['forma_pago'] !== null && empty($pagar)): ?><div><dt>Forma de pago</dt><dd><?= $c['forma_pago'] === 2 ? 'Cuenta corriente' : 'Contado' . ($c['pago'] ? ' · ' . e($c['pago']['descripcion']) : '') ?></dd></div><?php endif; ?>
        <?php if ($c['asociado']): ?><div><dt>Corrige</dt><dd><?= e(Tipos::titulo($c['asociado']['tipo'], $c['asociado']['letra']) . ' ' . numero_cbte($c['prefijo'], $c['asociado']['comprobante']) . ' del ' . fecha($c['asociado']['fecha'])) ?></dd></div><?php endif; ?>
        <?php if ($c['concepto'] > 1): ?><div><dt>Servicio</dt><dd><?= e(fecha($c['serv_desde']) . ' al ' . fecha($c['serv_hasta']) . ' · pago hasta el ' . fecha($c['vto_pago'])) ?></dd></div><?php endif; ?>
        <?php if ($c['descuento'] > 0): ?><div><dt>Descuento</dt><dd><?= e(numero($c['descuento'])) ?> %</dd></div><?php endif; ?>
        <?php if ($c['observaciones'] !== ''): ?><div><dt>Observaciones</dt><dd><?= e($c['observaciones']) ?></dd></div><?php endif; ?>
        <?php if ($c['email']): ?><div><dt>Mails</dt><dd><?= e($c['email']) ?></dd></div><?php endif; ?>
    </dl>
    <?php if ($c['excede_credito']): ?><div class="alerta alerta-warn">Este comprobante excede el crédito del cliente y lo autorizaste.</div><?php endif; ?>
</section>

<div class="table-wrap">
    <table class="table table-cards">
        <thead><tr><th>Descripción</th><th>Cantidad</th><th><?= $disc ? 'Precio neto' : 'Precio' ?></th><th>IVA</th><th>Importe</th></tr></thead>
        <tbody>
        <?php foreach ($k['items'] as $it): ?>
            <tr><td data-label="Descripción"><?= e($it['descripcion']) ?><?= ($it['texto'] ?? '') !== '' ? '<div class="small muted pre-linea">' . e($it['texto']) . '</div>' : '' ?><?= $it['articulo'] ? ' <small class="muted">' . e($it['articulo']) . '</small>' : '' ?></td>
                <td data-label="Cantidad"><?= e(numero($it['cantidad'], 3)) ?></td>
                <td data-label="Precio" class="nw"><?= e(numero($it['precio'], 4)) ?></td>
                <td data-label="IVA"><?= $c['letra'] === 'C' ? '—' : ($it['exento'] ? 'Exento' : e(numero($it['alicuota']) . ' %')) ?></td>
                <td data-label="Importe" class="nw"><?= e(plata_dec($it['importe'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<section class="card totales-rev">
    <dl class="datos datos-col">
        <div><dt><?= $disc ? 'Subtotal neto' : 'Subtotal' ?></dt><dd class="nw"><?= e(plata_dec($k['subtotal'])) ?></dd></div>
        <?php if ($k['descuento_importe'] > 0): ?><div><dt>Descuento <?= e(numero($k['descuento'])) ?> %</dt><dd class="nw">− <?= e(plata_dec($k['descuento_importe'])) ?></dd></div><?php endif; ?>
        <?php if ($disc): foreach ($k['iva'] as $g): ?>
            <div><dt>IVA <?= e(numero($g['alicuota'])) ?> % <span class="muted small">(sobre <?= e(plata_dec($g['base'])) ?>)</span></dt><dd class="nw"><?= e(plata_dec($g['importe'])) ?></dd></div>
        <?php endforeach; if ($k['neto_exento'] > 0): ?><div><dt>Exento</dt><dd class="nw"><?= e(plata_dec($k['neto_exento'])) ?></dd></div><?php endif; endif; ?>
        <div class="total-final"><dt>Total</dt><dd class="nw"><b><?= e(plata_dec($k['total'])) ?></b></dd></div>
    </dl>
    <?php if ($c['letra'] === 'B'): ?><p class="small muted">Los precios incluyen IVA. IVA contenido: <?= e(plata_dec($k['iva_total'])) ?> (Ley 27.743).</p><?php endif; ?>
</section>

<?php if ($c['electronica']): ?>
    <div class="alerta alerta-info small">Al emitir se pide el <b>CAE a ARCA</b> (<?= e($etiquetaArca) ?>) y el comprobante toma el número que dé ARCA. Si ARCA no lo autoriza, no se graba nada.</div>
<?php elseif (!empty($c['interno'])): ?>
    <div class="alerta alerta-info small"><?= $c['tipo'] === 'PR'
        ? 'El presupuesto <b>no va a ARCA ni al subdiario</b>: tiene numeración propia (letra X) y descuenta stock.'
        : 'La nota de crédito X <b>no va a ARCA ni al subdiario</b>: repone el stock y deja saldo a favor del cliente.' ?></div>
<?php else: ?>
    <div class="alerta alerta-info small">Este comprobante queda como <b>interno, sin CAE ni validez fiscal</b>, con numeración propia (<?= e($etiquetaArca) ?>).</div>
<?php endif; ?>
<?php if (!empty($c['presupuesto'])): ?>
    <div class="alerta alerta-warn small">Factura generada desde el presupuesto: va <b>solo al subdiario</b> (sin caja, sin cuenta corriente y sin stock) y <b>un presupuesto se factura una sola vez</b>.</div>
<?php endif; ?>

<?php if (!empty($pagar)):
    $pv = static fn (string $k, string $d = ''): string => (string) ($pagoCargado[$k] ?? $d);
    $err = static fn (string $k): string => isset($errores[$k]) ? '<small class="field-error">' . nl2br(e($errores[$k])) . '</small>' : '';
    $forma = $pv('forma_pago', (int) ($cli['forma_pago'] ?? 2) === 1 ? '1' : '2');
    $fpagoElegida = $pv('fpago');
    ?>
<section class="card" data-pago data-total="<?= e((string) $k['total']) ?>" id="como-se-paga">
    <h2>Cómo se paga</h2>
    <p class="total-cobrar">Total a cobrar: <b><?= e(plata_dec($k['total'])) ?></b></p>
    <?php foreach (array_intersect_key($errores, array_flip(['forma_pago', 'credito'])) as $m): ?><div class="alerta alerta-error" role="alert"><?= nl2br(e($m)) ?></div><?php endforeach; ?>
    <fieldset class="field">
        <legend><span>Forma de pago *</span></legend>
        <label class="check"><input type="radio" name="forma_pago" form="form-emitir" value="1" <?= $forma === '1' ? 'checked' : '' ?>> Contado</label>
        <label class="check"><input type="radio" name="forma_pago" form="form-emitir" value="2" <?= $forma === '2' ? 'checked' : '' ?>> Cuenta corriente</label>
    </fieldset>
    <div data-contado hidden>
        <div class="grid-2">
            <label class="field<?= isset($errores['fpago']) ? ' has-error' : '' ?>" for="fpago"><span>Se paga con</span>
                <select id="fpago" name="fpago" form="form-emitir" data-fpago>
                    <?php $yaEf = false; foreach ($formas as $f): $sel = $fpagoElegida === (string) $f['codigo'] || ($fpagoElegida === '' && (int) $f['tipo'] === 1 && !$yaEf); if ((int) $f['tipo'] === 1) { $yaEf = true; } ?>
                        <option value="<?= e($f['codigo']) ?>" data-tipo="<?= (int) $f['tipo'] ?>" data-vuelto="<?= (int) $f['acepta_vuelto'] ?>" <?= $sel ? 'selected' : '' ?>><?= e($f['descripcion']) ?></option>
                    <?php endforeach; ?>
                </select><?= $err('fpago') ?></label>
            <label class="field<?= isset($errores['recibido']) ? ' has-error' : '' ?>" data-recibido-caja hidden><span>Recibido (lo que entrega el cliente)</span>
                <input name="recibido" form="form-emitir" value="<?= e($pv('recibido')) ?>" inputmode="decimal" data-decimales="2" data-recibido placeholder="Vacío = el total justo"><?= $err('recibido') ?></label>
        </div>
        <div class="alerta alerta-warn vuelto" role="status" data-vuelto-aviso hidden></div>
        <div class="grid-2" data-cheque hidden>
            <label class="field<?= isset($errores['pago_nro']) ? ' has-error' : '' ?>"><span>Número del cheque</span>
                <input name="pago_nro" form="form-emitir" value="<?= e($pv('pago_nro')) ?>" maxlength="20"><?= $err('pago_nro') ?></label>
            <label class="field<?= isset($errores['pago_banco']) ? ' has-error' : '' ?>"><span>Banco</span>
                <select name="pago_banco" form="form-emitir"><option value="">—</option>
                    <?php foreach ($bancos as $b): ?><option value="<?= e($b['codigo']) ?>" <?= $pv('pago_banco') === (string) $b['codigo'] ? 'selected' : '' ?>><?= e($b['descripcion']) ?></option><?php endforeach; ?>
                </select><?= $err('pago_banco') ?></label>
            <label class="field<?= isset($errores['pago_vto']) ? ' has-error' : '' ?>"><span>Vencimiento del cheque</span>
                <input name="pago_vto" form="form-emitir" type="date" value="<?= e($pv('pago_vto')) ?>"><?= $err('pago_vto') ?></label>
        </div>
    </div>
    <?php if (isset($errores['credito']) && $puedeCredito): ?>
        <label class="check"><input type="checkbox" name="excede_ok" form="form-emitir" value="1" <?= $pv('excede_ok') === '1' ? 'checked' : '' ?>> <b>Autorizo exceder el crédito del cliente</b></label>
    <?php endif; ?>
</section>
<script src="<?= e(asset('assets/pago.js')) ?>" defer></script>
<?php endif; ?>

<div class="actions">
    <form method="post" action="<?= e(url('/comprobantes')) ?>" class="inline" id="form-emitir">
        <?= csrf_field() ?>
        <input type="hidden" name="datos" value="<?= e($datos) ?>">
        <input type="hidden" name="operacion" value="<?= e($operacion) ?>">
        <button class="btn" type="submit" data-un-solo-envio>Emitir <?= e(Tipos::titulo($c['tipo'], $c['letra'])) ?></button>
    </form>
    <?php if (!empty($c['presupuesto'])): ?>
        <a class="btn btn-ghost" href="<?= e(url('/comprobantes/' . (int) $c['presupuesto'])) ?>">Volver al presupuesto</a>
    <?php else: ?>
    <form method="post" action="<?= e(url('/comprobantes/editar')) ?>" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="datos" value="<?= e($datos) ?>">
        <button class="btn btn-ghost" type="submit">Volver a editar</button>
    </form>
    <?php endif; ?>
</div>
