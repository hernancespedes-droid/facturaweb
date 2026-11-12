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

<section class="card">
    <dl class="datos datos-col">
        <div><dt>Cliente</dt><dd><?= e($cli['razon_social']) ?> <span class="muted">(<?= e($cli['codigo']) ?>)</span></dd></div>
        <div><dt>CUIT</dt><dd><?= e(cuit_texto($cli['cuit']) ?: 'sin CUIT') ?></dd></div>
        <div><dt>Fecha</dt><dd><?= e(fecha($c['fecha'])) ?> <span class="muted small">(siempre la de hoy)</span></dd></div>
        <div><dt>Punto de venta</dt><dd><?= e(sprintf('%04d', $c['prefijo'])) ?></dd></div>
        <div><dt>Forma de pago</dt><dd><?= $c['forma_pago'] === 2 ? 'Cuenta corriente' : 'Contado' . ($c['pago'] ? ' · ' . e($c['pago']['descripcion']) : '') ?></dd></div>
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
<?php else: ?>
    <div class="alerta alerta-info small">Este comprobante queda como <b>interno, sin CAE ni validez fiscal</b>, con numeración propia (<?= e($etiquetaArca) ?>).</div>
<?php endif; ?>

<div class="actions">
    <form method="post" action="<?= e(url('/comprobantes')) ?>" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="datos" value="<?= e($datos) ?>">
        <input type="hidden" name="operacion" value="<?= e($operacion) ?>">
        <button class="btn" type="submit" data-un-solo-envio>Emitir <?= e(Tipos::titulo($c['tipo'], $c['letra'])) ?></button>
    </form>
    <form method="post" action="<?= e(url('/comprobantes/editar')) ?>" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="datos" value="<?= e($datos) ?>">
        <button class="btn btn-ghost" type="submit">Volver a editar</button>
    </form>
</div>
