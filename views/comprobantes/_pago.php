<?php
/** Cómo se paga: espera $totalPago, $formaDefecto ('1' contado, '2' cuenta corriente), $errores, $pagoCargado, $formas, $bancos, $puedeCredito y $formId (id del <form>). */
    $pv = static fn (string $k, string $d = ''): string => (string) ($pagoCargado[$k] ?? $d);
    $err = static fn (string $k): string => isset($errores[$k]) ? '<small class="field-error">' . nl2br(e($errores[$k])) . '</small>' : '';
    $forma = $pv('forma_pago', $formaDefecto);
    $fpagoElegida = $pv('fpago');
    ?>
<section class="card" data-pago data-total="<?= e((string) $totalPago) ?>" id="como-se-paga">
    <h2>Cómo se paga</h2>
    <p class="total-cobrar">Total a cobrar: <b><?= e(plata_dec($totalPago)) ?></b></p>
    <?php foreach (array_intersect_key($errores, array_flip(['forma_pago', 'credito'])) as $m): ?><div class="alerta alerta-error" role="alert"><?= nl2br(e($m)) ?></div><?php endforeach; ?>
    <fieldset class="field">
        <legend><span>Forma de pago *</span></legend>
        <label class="check"><input type="radio" name="forma_pago" form="<?= e($formId) ?>" value="1" <?= $forma === '1' ? 'checked' : '' ?>> Contado</label>
        <label class="check"><input type="radio" name="forma_pago" form="<?= e($formId) ?>" value="2" <?= $forma === '2' ? 'checked' : '' ?>> Cuenta corriente</label>
    </fieldset>
    <div data-contado hidden>
        <div class="grid-2">
            <label class="field<?= isset($errores['fpago']) ? ' has-error' : '' ?>" for="fpago"><span>Se paga con</span>
                <select id="fpago" name="fpago" form="<?= e($formId) ?>" data-fpago>
                    <?php $yaEf = false; foreach ($formas as $f): $sel = $fpagoElegida === (string) $f['codigo'] || ($fpagoElegida === '' && (int) $f['tipo'] === 1 && !$yaEf); if ((int) $f['tipo'] === 1) { $yaEf = true; } ?>
                        <option value="<?= e($f['codigo']) ?>" data-tipo="<?= (int) $f['tipo'] ?>" data-vuelto="<?= (int) $f['acepta_vuelto'] ?>" <?= $sel ? 'selected' : '' ?>><?= e($f['descripcion']) ?></option>
                    <?php endforeach; ?>
                </select><?= $err('fpago') ?></label>
            <label class="field<?= isset($errores['recibido']) ? ' has-error' : '' ?>" data-recibido-caja hidden><span>Recibido (lo que entrega el cliente)</span>
                <input name="recibido" form="<?= e($formId) ?>" value="<?= e($pv('recibido')) ?>" inputmode="decimal" data-decimales="2" data-recibido placeholder="Vacío = el total justo"><?= $err('recibido') ?></label>
        </div>
        <div class="alerta alerta-warn vuelto" role="status" data-vuelto-aviso hidden></div>
        <div class="grid-2" data-cheque hidden>
            <label class="field<?= isset($errores['pago_nro']) ? ' has-error' : '' ?>"><span>Número del cheque</span>
                <input name="pago_nro" form="<?= e($formId) ?>" value="<?= e($pv('pago_nro')) ?>" maxlength="20"><?= $err('pago_nro') ?></label>
            <label class="field<?= isset($errores['pago_banco']) ? ' has-error' : '' ?>"><span>Banco</span>
                <select name="pago_banco" form="<?= e($formId) ?>"><option value="">—</option>
                    <?php foreach ($bancos as $b): ?><option value="<?= e($b['codigo']) ?>" <?= $pv('pago_banco') === (string) $b['codigo'] ? 'selected' : '' ?>><?= e($b['descripcion']) ?></option><?php endforeach; ?>
                </select><?= $err('pago_banco') ?></label>
            <label class="field<?= isset($errores['pago_vto']) ? ' has-error' : '' ?>"><span>Vencimiento del cheque</span>
                <input name="pago_vto" form="<?= e($formId) ?>" type="date" value="<?= e($pv('pago_vto')) ?>"><?= $err('pago_vto') ?></label>
        </div>
    </div>
    <?php if (isset($errores['credito']) && $puedeCredito): ?>
        <label class="check"><input type="checkbox" name="excede_ok" form="<?= e($formId) ?>" value="1" <?= $pv('excede_ok') === '1' ? 'checked' : '' ?>> <b>Autorizo exceder el crédito del cliente</b></label>
    <?php endif; ?>
</section>
<script src="<?= e(asset('assets/pago.js')) ?>" defer></script>
