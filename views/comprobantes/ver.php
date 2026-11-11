<?php
use App\Modules\Comprobantes\Tipos;

/** @var array<string,mixed> $h */
/** @var array<string,mixed> $emisor */
/** @var float $porAcreditar */
/** @var bool $puedeEmitir */
$titulo = Tipos::titulo($h['tipo'], $h['letra']);
$cli = $h['cliente_actual'];
$razon = $h['cliente_razon_social'] ?? ($cli['razon_social'] ?? '');
$disc = $h['letra'] === 'A';
$conCae = $h['fe_ccae'] !== '' && $h['fe_ccae'] !== '.';
$subtotal = \App\Modules\Comprobantes\ComprobanteImpreso::subtotal($h);
$descImporte = \App\Modules\Comprobantes\ComprobanteImpreso::descuento($h);
?>
<p><a href="<?= e(url('/comprobantes')) ?>">← Comprobantes</a></p>
<div class="page-head">
    <div>
        <p class="eyebrow"><?= e(fecha($h['fecha'])) ?> · <?= $conCae ? 'Con CAE' : 'Interno, sin CAE' ?><?= $h['estado'] !== '' ? ' · ANULADO' : '' ?></p>
        <h1><?= e($titulo) ?> <?= e(numero_visible($h)) ?></h1>
    </div>
    <div class="acciones">
        <a class="btn" href="<?= e(url('/comprobantes/' . $h['fe_clave'] . '/imprimir')) ?>" target="_blank" rel="noopener">Imprimir</a>
        <a class="btn btn-ghost" href="<?= e(url('/comprobantes/' . $h['fe_clave'] . '/pdf')) ?>" target="_blank" rel="noopener">PDF</a>
        <?php if ($puedeEmitir && in_array($h['tipo'], ['FC', 'ND'], true) && $porAcreditar > 0): ?>
            <a class="btn btn-ghost" href="<?= e(url('/comprobantes/nuevo') . '?tipo=NC&desde=' . $h['fe_clave']) ?>">Hacer nota de crédito</a>
            <a class="btn btn-ghost" href="<?= e(url('/comprobantes/nuevo') . '?tipo=ND&desde=' . $h['fe_clave']) ?>">Hacer nota de débito</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$conCae): ?><div class="alerta alerta-info small">Comprobante interno: todavía no tiene CAE de ARCA (la integración es la etapa 3). No tiene validez fiscal.</div><?php endif; ?>

<section class="card">
    <dl class="datos datos-col">
        <div><dt>Cliente</dt><dd><a href="<?= e(url('/clientes/' . $h['cliente'])) ?>"><?= e($razon) ?></a> <span class="muted">(<?= e($h['cliente']) ?>)</span></dd></div>
        <div><dt>CUIT</dt><dd><?= e(cuit_texto($h['cuit_cliente']) ?: 'sin CUIT') ?></dd></div>
        <?php if (!empty($h['cliente_domicilio'] ?? $cli['domicilio'] ?? '')): ?><div><dt>Domicilio</dt><dd><?= e(trim(($h['cliente_domicilio'] ?? $cli['domicilio'] ?? '') . ' ' . ($h['cliente_localidad'] ?? $cli['localidad'] ?? ''))) ?></dd></div><?php endif; ?>
        <div><dt>Forma de pago</dt><dd><?= (int) $h['forma_pago'] === 2 ? 'Cuenta corriente' : 'Contado' . ($h['pagos'] !== [] ? ' · ' . e($h['pagos'][0]['fpago_descripcion'] ?? $h['pagos'][0]['p_fpago']) : '') ?></dd></div>
        <?php if ($h['asociado_clave'] !== null): ?>
            <div><dt>Corrige</dt><dd><a href="<?= e(url('/comprobantes/' . $h['asociado_clave'])) ?>"><?= e(Tipos::titulo($h['fe_tipocbte_asoc'], $h['letra']) . ' ' . numero_cbte($h['prefijo'], $h['fe_nrocbte_asoc'])) ?></a></dd></div>
        <?php elseif ($h['fe_nrocbte_asoc'] > 0): ?>
            <div><dt>Corrige</dt><dd><?= e(Tipos::titulo($h['fe_tipocbte_asoc'], $h['letra']) . ' ' . numero_cbte($h['prefijo'], $h['fe_nrocbte_asoc'])) ?></dd></div>
        <?php endif; ?>
        <?php if ($h['observaciones']): ?><div><dt>Observaciones</dt><dd><?= e($h['observaciones']) ?></dd></div><?php endif; ?>
        <?php if ($h['orden_compra']): ?><div><dt>Orden de compra</dt><dd><?= e($h['orden_compra']) ?></dd></div><?php endif; ?>
        <?php if ($conCae): ?><div><dt>CAE</dt><dd><?= e($h['fe_ccae']) ?><?= $h['fe_dvtocae'] ? ' · vence ' . e(fecha($h['fe_dvtocae'])) : '' ?></dd></div><?php endif; ?>
    </dl>
</section>

<div class="table-wrap">
    <table class="table table-cards">
        <thead><tr><th>Descripción</th><th>Cantidad</th><th>Precio</th><th>IVA</th><th>Importe</th></tr></thead>
        <tbody>
        <?php foreach ($h['items'] as $it): ?>
            <tr><td data-label="Descripción" class="pre-linea"><?= e($it['observaciones']) ?><?= $it['articulo'] ? ' <small class="muted">' . e($it['articulo']) . '</small>' : '' ?></td>
                <td data-label="Cantidad"><?= e(numero($it['unidades'], 3)) ?></td>
                <td data-label="Precio" class="nw"><?= e(numero($it['precio'], 4)) ?></td>
                <td data-label="IVA"><?= $h['letra'] === 'C' ? '—' : ((float) $it['por_iva'] > 0 ? e(numero($it['por_iva']) . ' %') : 'Exento') ?></td>
                <td data-label="Importe" class="nw"><?= e(plata_dec($it['total_item'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($h['items'] === []): ?><tr><td colspan="5" class="muted">Este comprobante no tiene renglones cargados.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<section class="card totales-rev">
    <dl class="datos datos-col">
        <?php if ($h['items'] !== []): ?><div><dt><?= $disc ? 'Subtotal neto' : 'Subtotal' ?></dt><dd class="nw"><?= e(plata_dec($subtotal)) ?></dd></div><?php endif; ?>
        <?php if ($descImporte > 0): ?><div><dt>Descuento <?= e(numero($h['porcentaje_descuento'])) ?> %</dt><dd class="nw">− <?= e(plata_dec($descImporte)) ?></dd></div><?php endif; ?>
        <?php if ($disc): foreach ($h['iva'] as $g): ?>
            <div><dt>IVA <?= e(numero($g['alicuota'])) ?> % <span class="muted small">(sobre <?= e(plata_dec($g['monto'])) ?>)</span></dt><dd class="nw"><?= e(plata_dec($g['importe'])) ?></dd></div>
        <?php endforeach; if ((float) $h['neto_exento'] > 0): ?><div><dt>Exento</dt><dd class="nw"><?= e(plata_dec($h['neto_exento'])) ?></dd></div><?php endif; endif; ?>
        <div class="total-final"><dt>Total</dt><dd class="nw"><b><?= e(plata_dec($h['neto'])) ?></b></dd></div>
    </dl>
    <?php if ($h['letra'] === 'B'): ?><p class="small muted">Precios con IVA incluido. IVA contenido: <?= e(plata_dec($h['iva_1'])) ?>.</p><?php endif; ?>
</section>

<?php if ($h['notas'] !== []): ?>
    <section class="card">
        <h2>Notas asociadas</h2>
        <ul class="list small">
            <?php foreach ($h['notas'] as $n): ?>
                <li><a href="<?= e(url('/comprobantes/' . $n['fe_clave'])) ?>"><?= e(Tipos::titulo($n['tipo'], $n['letra']) . ' ' . numero_visible($n)) ?></a>
                    · <?= e(fecha($n['fecha'])) ?> · <?= e(plata_dec($n['neto'])) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php if ($puedeEmitir): ?>
    <section class="card" id="mail">
        <h2>Enviar por mail</h2>
        <?php if (!\App\Core\Mailer::configurado()): ?>
            <p class="small muted">El envío por mail no está configurado en este servidor (sección <code>mail</code> de <code>config/local.php</code>).</p>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/comprobantes/' . $h['fe_clave'] . '/mail')) ?>" class="toolbar">
            <?= csrf_field() ?>
            <label class="field"><span>Mails (separados por punto y coma)</span>
                <input name="email" value="<?= e($h['fe_cemailenvio'] ?: ($cli['emailfc'] ?? '')) ?>" maxlength="255" placeholder="cliente@mail.com"></label>
            <button class="btn" type="submit" <?= \App\Core\Mailer::configurado() ? '' : 'disabled' ?>>Enviar</button>
        </form>
        <?php if ($h['fe_dfechaenvio']): ?><p class="small muted">Último envío: <?= e(fecha($h['fe_dfechaenvio'])) ?>.</p><?php endif; ?>
    </section>
<?php endif; ?>
