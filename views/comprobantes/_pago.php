<?php
/**
 * Cómo se paga (una o varias formas de pago). Espera $totalPago, $formaDefecto ('1' contado, '2' cuenta corriente), $errores, $pagoCargado, $formas, $bancos,
 * $puedeCredito y $formId (id del <form> al que pertenecen los campos). Opcional $enfocarRecibido: el cursor arranca en «Recibido» (kiosco).
 */
$pv = static fn (string $k, string $d = ''): string => (string) ($pagoCargado[$k] ?? $d);
$forma = $pv('forma_pago', $formaDefecto);
$cargadas = array_values(array_filter((array) ($pagoCargado['pagos'] ?? []), 'is_array'));
if ($cargadas === []) {
    $cargadas = [['fpago' => $pv('fpago'), 'recibido' => $pv('recibido'), 'nro' => $pv('pago_nro'), 'banco' => $pv('pago_banco'), 'vto' => $pv('pago_vto')]];
}
$erroresPago = array_intersect_key($errores, array_flip(['forma_pago', 'credito', 'pagos', 'fpago', 'recibido', 'pago_nro', 'pago_banco', 'pago_vto']));
$yaEf = false;
// Un medio de pago de la ventana «Cobrar venta» (D-136): con qué y cuánto paga. Lo que se paga de más es el vuelto (informativo).
$lineaHtml = static function (string $n, array $p, bool $primera) use ($formas, $bancos, $formId, &$yaEf): string {
    $imp = (string) ($p['importe'] ?? '');
    if ($imp === '' && (string) ($p['recibido'] ?? '') !== '') {
        $imp = (string) $p['recibido']; // lo cargado con la pantalla anterior
    }
    ob_start(); ?>
    <div class="pago-linea cobro-medio" data-linea>
        <span class="cobro-icono" data-icono aria-hidden="true"></span>
        <label class="field"><span>Medio</span>
            <select name="pagos[<?= e($n) ?>][fpago]" form="<?= e($formId) ?>" data-fpago>
                <?php foreach ($formas as $f): $sel = ($p['fpago'] ?? '') === (string) $f['codigo'] || (($p['fpago'] ?? '') === '' && $primera && (int) $f['tipo'] === 1 && !$yaEf); if ((int) $f['tipo'] === 1) { $yaEf = true; } ?>
                    <option value="<?= e($f['codigo']) ?>" data-tipo="<?= (int) $f['tipo'] ?>" data-vuelto="<?= (int) $f['acepta_vuelto'] ?>" <?= $sel ? 'selected' : '' ?>><?= e((int) $f['tipo'] === 6 ? 'Cuenta corriente (queda como deuda)' : $f['descripcion']) ?></option>
                <?php endforeach; ?>
            </select></label>
        <label class="field cobro-importe"><span>Importe</span>
            <input name="pagos[<?= e($n) ?>][importe]" form="<?= e($formId) ?>" value="<?= e($imp) ?>" inputmode="decimal" data-decimales="2" data-importe-pago placeholder="El total" autocomplete="off"></label>
        <div class="grid-2 pago-cheque" data-cheque hidden>
            <label class="field"><span>Número del cheque</span><input name="pagos[<?= e($n) ?>][nro]" form="<?= e($formId) ?>" value="<?= e((string) ($p['nro'] ?? '')) ?>" maxlength="20"></label>
            <label class="field"><span>Banco</span>
                <select name="pagos[<?= e($n) ?>][banco]" form="<?= e($formId) ?>"><option value="">—</option>
                    <?php foreach ($bancos as $b): ?><option value="<?= e($b['codigo']) ?>" <?= (string) ($p['banco'] ?? '') === (string) $b['codigo'] ? 'selected' : '' ?>><?= e($b['descripcion']) ?></option><?php endforeach; ?>
                </select></label>
            <label class="field"><span>Vencimiento del cheque</span><input name="pagos[<?= e($n) ?>][vto]" form="<?= e($formId) ?>" type="date" value="<?= e((string) ($p['vto'] ?? '')) ?>"></label>
        </div>
        <button type="button" class="btn btn-ghost btn-sm pago-quitar" data-quitar-pago aria-label="Quitar este medio de pago" title="Quitar">✕</button>
    </div>
    <?php return (string) ob_get_clean();
};
$turnoCaja = \App\Modules\Caja\CajaService::contexto()['turno_id'] ?? null;
$avisoErrores = static function () use ($erroresPago): void {
    foreach ($erroresPago as $m) { ?><div class="alerta alerta-error" role="alert"><?= nl2br(e($m)) ?></div><?php }
};
?>
<section class="card" data-pago data-total="<?= e((string) $totalPago) ?>" id="como-se-paga"<?= !empty($enfocarRecibido) ? ' data-abrir-solo' : '' ?><?= $erroresPago !== [] ? ' data-con-errores' : '' ?>>
    <h2>Cómo se paga</h2>
    <p class="total-cobrar">Total a cobrar: <b><?= e(plata_dec($totalPago)) ?></b></p>
    <?php $avisoErrores(); ?>
    <fieldset class="field">
        <legend><span>Forma de pago *</span></legend>
        <label class="check"><input type="radio" name="forma_pago" form="<?= e($formId) ?>" value="1" <?= $forma === '1' ? 'checked' : '' ?>> Contado</label>
        <label class="check"><input type="radio" name="forma_pago" form="<?= e($formId) ?>" value="2" <?= $forma === '2' ? 'checked' : '' ?>> Cuenta corriente</label>
    </fieldset>
    <div data-contado hidden>
        <p class="cobro-resumen-corto" data-resumen-corto></p>
        <button type="button" class="btn" data-abrir-cobro>Cobrar venta…</button>
    </div>
    <?php if (isset($errores['credito']) && $puedeCredito): ?>
        <label class="check"><input type="checkbox" name="excede_ok" form="<?= e($formId) ?>" value="1" <?= $pv('excede_ok') === '1' ? 'checked' : '' ?>> <b>Autorizo exceder el crédito del cliente</b></label>
    <?php endif; ?>

    <dialog class="ventana cobro" data-cobro aria-labelledby="cobro-titulo">
        <div class="ventana-cab cobro-cab">
            <h2 id="cobro-titulo">Cobrar venta</h2>
            <?php if ($turnoCaja !== null): ?><span class="badge badge-ok">Turno abierto</span><?php endif; ?>
            <button type="button" class="btn btn-ghost btn-sm" data-cerrar-cobro aria-label="Cerrar">✕</button>
        </div>
        <?php $avisoErrores(); ?>
        <div class="cobro-cuerpo">
            <div class="cobro-medios">
                <h3>Medios de pago</h3>
                <p class="small muted">Cuánto paga con cada medio. Se pueden mezclar; lo que se pague de más es el vuelto.</p>
                <div data-lineas>
                    <?php foreach ($cargadas as $i => $p) { echo $lineaHtml((string) $i, $p, $i === 0); } ?>
                </div>
                <button type="button" class="btn btn-ghost cobro-agregar" data-agregar-pago>+ Agregar otro medio</button>
                <div class="vuelto-botones" role="group" aria-label="Te entrega"><span class="small muted">Te entrega <span data-medio-activo></span>:</span><span data-v-sugeridos></span></div>
                <div class="vuelto-botones" role="group" aria-label="Sumar billetes"><span class="small muted">Sumar billetes:</span><span data-v-billetes></span></div>
            </div>
            <aside class="cobro-resumen" aria-live="polite">
                <h3>Resumen</h3>
                <dl>
                    <div><dt>Total de la venta</dt><dd data-r-total><?= e(plata_dec($totalPago)) ?></dd></div>
                    <div><dt>Pagado</dt><dd data-r-pagado></dd></div>
                    <div class="cobro-vuelto" data-r-caja><dt data-r-etiqueta>Vuelto</dt><dd data-r-vuelto></dd></div>
                </dl>
                <p class="small vuelto-desglose" data-v-desglose hidden></p>
                <p class="small muted" data-r-nota>El vuelto es informativo: si la diferencia es un recargo, no se entrega.</p>
                <button type="button" class="btn cobro-confirmar" data-confirmar-cobro>Confirmar cobro</button>
                <p class="small muted cobro-quien"><?= e((string) (\App\Modules\Auth\Auth::usuario()['nombre'] ?? '')) ?><?= $turnoCaja !== null ? ' · turno N.º ' . (int) $turnoCaja : '' ?></p>
            </aside>
        </div>
    </dialog>
    <template data-plantilla-pago><?php $yaEf = true; echo $lineaHtml('__N__', [], false); ?></template>
</section>
<script src="<?= e(asset('assets/vuelto.js')) ?>" defer></script>
<script src="<?= e(asset('assets/pago.js')) ?>" defer></script>
