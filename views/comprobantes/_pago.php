<?php
/**
 * Cómo se paga (una o varias formas de pago). Espera $totalPago, $formaDefecto ('1' contado, '2' cuenta corriente), $errores, $pagoCargado, $formas, $bancos,
 * $puedeCredito y $formId (id del <form> al que pertenecen los campos).
 */
$pv = static fn (string $k, string $d = ''): string => (string) ($pagoCargado[$k] ?? $d);
$forma = $pv('forma_pago', $formaDefecto);
$cargadas = array_values(array_filter((array) ($pagoCargado['pagos'] ?? []), 'is_array'));
if ($cargadas === []) {
    $cargadas = [['fpago' => $pv('fpago'), 'recibido' => $pv('recibido'), 'nro' => $pv('pago_nro'), 'banco' => $pv('pago_banco'), 'vto' => $pv('pago_vto')]];
}
$erroresPago = array_intersect_key($errores, array_flip(['forma_pago', 'credito', 'pagos', 'fpago', 'recibido', 'pago_nro', 'pago_banco', 'pago_vto']));
$yaEf = false;
$lineaHtml = static function (string $n, array $p, bool $primera) use ($formas, $bancos, $formId, &$yaEf): string {
    ob_start(); ?>
    <div class="pago-linea" data-linea>
        <label class="field"><span>Se paga con</span>
            <select name="pagos[<?= e($n) ?>][fpago]" form="<?= e($formId) ?>" data-fpago>
                <?php foreach ($formas as $f): $sel = ($p['fpago'] ?? '') === (string) $f['codigo'] || (($p['fpago'] ?? '') === '' && $primera && (int) $f['tipo'] === 1 && !$yaEf); if ((int) $f['tipo'] === 1) { $yaEf = true; } ?>
                    <option value="<?= e($f['codigo']) ?>" data-tipo="<?= (int) $f['tipo'] ?>" data-vuelto="<?= (int) $f['acepta_vuelto'] ?>" <?= $sel ? 'selected' : '' ?>><?= e((int) $f['tipo'] === 6 ? 'Cuenta corriente (queda como deuda)' : $f['descripcion']) ?></option>
                <?php endforeach; ?>
            </select></label>
        <label class="field"><span>Importe</span>
            <input name="pagos[<?= e($n) ?>][importe]" form="<?= e($formId) ?>" value="<?= e((string) ($p['importe'] ?? '')) ?>" inputmode="decimal" data-decimales="2" data-importe-pago placeholder="El total"></label>
        <label class="field" data-recibido-caja hidden><span>Recibido (lo que entrega el cliente)</span>
            <input name="pagos[<?= e($n) ?>][recibido]" form="<?= e($formId) ?>" value="<?= e((string) ($p['recibido'] ?? '')) ?>" inputmode="decimal" data-decimales="2" data-recibido placeholder="Vacío = el importe justo"></label>
        <div class="grid-2 pago-cheque" data-cheque hidden>
            <label class="field"><span>Número del cheque</span><input name="pagos[<?= e($n) ?>][nro]" form="<?= e($formId) ?>" value="<?= e((string) ($p['nro'] ?? '')) ?>" maxlength="20"></label>
            <label class="field"><span>Banco</span>
                <select name="pagos[<?= e($n) ?>][banco]" form="<?= e($formId) ?>"><option value="">—</option>
                    <?php foreach ($bancos as $b): ?><option value="<?= e($b['codigo']) ?>" <?= (string) ($p['banco'] ?? '') === (string) $b['codigo'] ? 'selected' : '' ?>><?= e($b['descripcion']) ?></option><?php endforeach; ?>
                </select></label>
            <label class="field"><span>Vencimiento del cheque</span><input name="pagos[<?= e($n) ?>][vto]" form="<?= e($formId) ?>" type="date" value="<?= e((string) ($p['vto'] ?? '')) ?>"></label>
        </div>
        <button type="button" class="btn btn-ghost btn-sm pago-quitar" data-quitar-pago aria-label="Quitar esta forma de pago" title="Quitar">✕</button>
    </div>
    <?php return (string) ob_get_clean();
};
?>
<section class="card" data-pago data-total="<?= e((string) $totalPago) ?>" id="como-se-paga">
    <h2>Cómo se paga</h2>
    <p class="total-cobrar">Total a cobrar: <b><?= e(plata_dec($totalPago)) ?></b></p>
    <?php foreach ($erroresPago as $m): ?><div class="alerta alerta-error" role="alert"><?= nl2br(e($m)) ?></div><?php endforeach; ?>
    <fieldset class="field">
        <legend><span>Forma de pago *</span></legend>
        <label class="check"><input type="radio" name="forma_pago" form="<?= e($formId) ?>" value="1" <?= $forma === '1' ? 'checked' : '' ?>> Contado</label>
        <label class="check"><input type="radio" name="forma_pago" form="<?= e($formId) ?>" value="2" <?= $forma === '2' ? 'checked' : '' ?>> Cuenta corriente</label>
    </fieldset>
    <div data-contado hidden>
        <div data-lineas>
            <?php foreach ($cargadas as $i => $p) { echo $lineaHtml((string) $i, $p, $i === 0); } ?>
        </div>
        <p class="acciones">
            <button type="button" class="btn btn-ghost btn-sm" data-agregar-pago>+ Agregar otra forma de pago</button>
            <button type="button" class="btn btn-ghost btn-sm" data-completar-pago hidden>Completar con lo que falta</button>
        </p>
        <p class="small muted" data-resumen-pago hidden></p>
        <div class="alerta alerta-warn vuelto" role="status" data-vuelto-aviso hidden></div>
        <template data-plantilla-pago><?php $yaEf = true; echo $lineaHtml('__N__', [], false); ?></template>
    </div>
    <?php if (isset($errores['credito']) && $puedeCredito): ?>
        <label class="check"><input type="checkbox" name="excede_ok" form="<?= e($formId) ?>" value="1" <?= $pv('excede_ok') === '1' ? 'checked' : '' ?>> <b>Autorizo exceder el crédito del cliente</b></label>
    <?php endif; ?>
</section>
<script src="<?= e(asset('assets/pago.js')) ?>" defer></script>
