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

<?php if (isset($errores['mp'])): ?><div class="alerta alerta-error" role="alert"><b>Mercado Pago:</b> <?= e($errores['mp']) ?></div><?php endif; ?>
<?php if (!empty($c['stock_faltantes']) && !empty($c['stock_autorizado']['automatica'])): ?>
<div class="alerta alerta-warn" role="status" id="venta-sin-stock"><b>Se vende sin stock disponible:</b> tu rol puede hacerlo sin pedir autorización. Queda registrado con tu nombre.
    <ul class="list small"><?php foreach ($c['stock_faltantes'] as $f): ?><li><?= e($f['mensaje']) ?></li><?php endforeach; ?></ul></div>
<?php elseif (!empty($c['stock_faltantes'])): ?>
<section class="card alerta-stock" id="autorizacion-stock">
    <h2>Stock no disponible</h2>
    <ul class="list small">
        <?php foreach ($c['stock_faltantes'] as $f): ?><li><?= e($f['mensaje']) ?></li><?php endforeach; ?>
    </ul>
    <?php if (isset($errores['stock'])): ?><div class="alerta alerta-error" role="alert"><?= nl2br(e($errores['stock'])) ?></div><?php endif; ?>
    <p class="small">Venderlo igual necesita la <b>autorización de un supervisor</b>. Queda registrada con su nombre.</p>
    <?php if (puede('stock.autorizar')): ?>
        <label class="check"><input type="checkbox" name="autoriza_yo" form="form-emitir" value="1" <?= !empty($pagoCargado['autoriza_yo']) ? 'checked' : '' ?>> <b>Autorizo vender de todos modos</b> (lo autorizo yo)</label>
        <p class="small muted">O que lo autorice otro supervisor con su usuario y su clave:</p>
    <?php endif; ?>
    <div class="grid-2">
        <label class="field"><span>Usuario que autoriza</span><input name="autoriza_usuario" form="form-emitir" value="<?= e((string) ($pagoCargado['autoriza_usuario'] ?? '')) ?>" autocomplete="off"></label>
        <label class="field"><span>Su clave</span><input type="password" name="autoriza_clave" form="form-emitir" autocomplete="new-password"></label>
    </div>
</section>
<?php endif; ?>
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
                <td data-label="IVA"><?= $c['letra'] === 'C' || !empty($c['sin_iva']) ? '—' : ($it['exento'] ? 'Exento' : e(numero($it['alicuota']) . ' %')) ?></td>
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
    $totalPago = (float) $k['total'];
    $formaDefecto = (int) ($cli['forma_pago'] ?? 2) === 1 ? '1' : '2';
    $formId = 'form-emitir';
    $enfocarRecibido = !empty($urlVolver); // desde el kiosco la ventana «Cobrar venta» se abre sola
    require __DIR__ . '/_pago.php';
endif; ?>

<div class="actions">
    <form method="post" action="<?= e($urlEmitir ?? url('/comprobantes')) ?>" class="inline" id="form-emitir">
        <?= csrf_field() ?>
        <input type="hidden" name="datos" value="<?= e($datos) ?>">
        <input type="hidden" name="operacion" value="<?= e($operacion) ?>">
        <button class="btn" type="submit" data-un-solo-envio>Emitir <?= e(Tipos::titulo($c['tipo'], $c['letra'])) ?></button>
        <?php if (!empty($pagar) && \App\Modules\MercadoPago\MercadoPago::activo() && puede('mercadopago.cobrar')): ?>
            <button class="btn btn-mp" type="submit" formaction="<?= e(url('/mercadopago/iniciar')) ?>" title="Genera el QR y el link de pago; la venta se emite cuando Mercado Pago confirma la acreditación">Cobrar con Mercado Pago (QR / link)</button>
        <?php endif; ?>
    </form>
    <?php if (!empty($urlVolver)): ?>
        <a class="btn btn-ghost" href="<?= e($urlVolver) ?>">Volver al kiosco</a>
    <?php elseif (!empty($c['presupuesto'])): ?>
        <a class="btn btn-ghost" href="<?= e(url('/comprobantes/' . (int) $c['presupuesto'])) ?>">Volver al presupuesto</a>
    <?php else: ?>
    <form method="post" action="<?= e(url('/comprobantes/editar')) ?>" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="datos" value="<?= e($datos) ?>">
        <button class="btn btn-ghost" type="submit">Volver a editar</button>
    </form>
    <?php endif; ?>
</div>
