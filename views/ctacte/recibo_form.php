<?php
use App\Modules\CuentaCorriente\CuentaCorrienteService;

/** @var ?array<string,mixed> $cli @var array<string,mixed> $datos @var array<string,string> $errores @var array{debitos:array,creditos:array} $pend @var float $saldo
 *  @var array<int,array<string,mixed>> $sucursales @var array<int,array<string,mixed>> $formas @var array<int,array<string,mixed>> $bancos @var string $operacion */
if ($cli === null) {
    ?>
    <div class="page-head"><div><p class="eyebrow">Cuenta corriente</p><h1>Nuevo recibo</h1></div></div>
    <p class="muted">Primero elegí el cliente al que le cobrás.</p>
    <?php $accion = '/recibos/nuevo'; $boton = 'Seguir'; require __DIR__ . '/_picker.php'; ?>
    <?php
    return;
}
$err = static fn (string $k): string => isset($errores[$k]) ? '<small class="field-error">' . nl2br(e($errores[$k])) . '</small>' : '';
$v = static fn (string $k, string $d = ''): string => (string) ($datos[$k] ?? $d);
$pagos = array_values((array) ($datos['pagos'] ?? []));
if ($pagos === []) {
    $pagos = [[]];
}
$imputar = (array) ($datos['imputar'] ?? []);
$ret = (array) ($datos['ret'] ?? []);
$unaSucursal = count($sucursales) === 1 ? (int) array_key_first($sucursales) : 0;
$suc = $v('sucursal', (string) $unaSucursal);

/** Un renglón de medio de pago (el mismo marcado sirve para los que agrega el JS: __N__ es el índice). */
$filaPago = static function (string $n, array $p) use ($formas, $bancos): string {
    ob_start(); ?>
    <div class="pago-fila form-grid" data-pago>
        <label class="field"><span>Forma de pago</span><select name="pagos[<?= e($n) ?>][fpago]" data-fpago>
            <option value="">Elegí…</option>
            <?php foreach ($formas as $f): ?><option value="<?= e($f['codigo']) ?>" data-tipo="<?= (int) $f['tipo'] ?>" <?= ($p['fpago'] ?? '') === $f['codigo'] ? 'selected' : '' ?>><?= e($f['descripcion']) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Importe</span><input name="pagos[<?= e($n) ?>][importe]" value="<?= e($p['importe'] ?? '') ?>" inputmode="decimal" data-decimales="2" data-pago-importe></label>
        <label class="field" data-solo-cheque hidden><span>N.º de cheque</span><input name="pagos[<?= e($n) ?>][nro]" value="<?= e($p['nro'] ?? '') ?>" maxlength="20"></label>
        <label class="field" data-solo-cheque hidden><span>Banco</span><select name="pagos[<?= e($n) ?>][banco]"><option value="">Elegí…</option>
            <?php foreach ($bancos as $b): ?><option value="<?= (int) $b['codigo'] ?>" <?= (string) ($p['banco'] ?? '') === (string) $b['codigo'] ? 'selected' : '' ?>><?= e($b['descripcion']) ?></option><?php endforeach; ?></select></label>
        <label class="field" data-solo-cheque hidden><span>Fecha de cobro</span><input name="pagos[<?= e($n) ?>][vto]" value="<?= e($p['vto'] ?? '') ?>" placeholder="dd/mm/aaaa" inputmode="numeric"></label>
    </div>
    <?php return (string) ob_get_clean();
};
?>
<p><a href="<?= e(url('/recibos/nuevo')) ?>">← Elegir otro cliente</a></p>
<div class="page-head">
    <div><p class="eyebrow">Recibo · cliente <?= (int) $cli['codigo'] ?></p><h1><?= e($cli['razon_social']) ?></h1></div>
    <div class="acciones"><a class="btn btn-ghost" href="<?= e(url('/cuenta-corriente/cliente/' . (int) $cli['codigo'])) ?>">Ver su cuenta</a></div>
</div>
<?php if (isset($errores['general'])): ?><div class="alerta alerta-error" role="alert"><?= nl2br(e($errores['general'])) ?></div><?php endif; ?>
<?php if ($errores !== [] && !isset($errores['general'])): ?><div class="alerta alerta-error" role="alert">Revisá lo marcado: el recibo no se grabó.</div><?php endif; ?>
<?php if ($pend['creditos'] !== []): ?>
    <div class="alerta alerta-info small">Este cliente tiene <b><?= count($pend['creditos']) ?></b> saldo(s) a favor (notas de crédito o anticipos).
        <?php if (puede('ctacte.imputar')): ?>Aplicalos antes en <a href="<?= e(url('/cuenta-corriente/imputar?cliente=' . (int) $cli['codigo'])) ?>">Imputación manual</a> para cobrar solo la diferencia.<?php endif; ?></div>
<?php endif; ?>

<form method="post" action="<?= e(url('/recibos')) ?>" class="stack" data-recibo><?= csrf_field() ?>
    <input type="hidden" name="cliente" value="<?= (int) $cli['codigo'] ?>">
    <input type="hidden" name="operacion" value="<?= e($operacion) ?>">
    <input type="hidden" data-neto value="0">
    <section class="card form-grid">
        <label class="field<?= isset($errores['fecha']) ? ' has-error' : '' ?>"><span>Fecha *</span><input name="fecha" value="<?= e($v('fecha', date('d/m/Y'))) ?>" inputmode="numeric" required><?= $err('fecha') ?></label>
        <?php if ($sucursales === []): ?>
            <div class="alerta alerta-error">No hay sucursales con punto de venta. Se cargan en Tablas → Sucursales.</div>
        <?php elseif (count($sucursales) > 1): ?>
            <label class="field<?= isset($errores['sucursal']) ? ' has-error' : '' ?>"><span>Sucursal *</span><select name="sucursal" required><option value="">Elegí…</option>
                <?php foreach ($sucursales as $s): ?><option value="<?= (int) $s['codigo'] ?>" <?= $suc === (string) $s['codigo'] ? 'selected' : '' ?>><?= e($s['descripcion']) ?> (<?= (int) $s['prefijo'] ?>)</option><?php endforeach; ?></select><?= $err('sucursal') ?></label>
        <?php else: ?><input type="hidden" name="sucursal" value="<?= $unaSucursal ?>"><?php endif; ?>
    </section>

    <section class="card">
        <h2>Qué se cancela</h2>
        <?php if ($pend['debitos'] === []): ?>
            <p class="muted">El cliente no tiene deuda pendiente: lo que cobres queda como saldo a favor (cobro a cuenta).</p>
        <?php else: ?>
            <div class="table-wrap"><table class="table table-cards">
                <thead><tr><th></th><th>Fecha</th><th>Comprobante</th><th>Vence</th><th class="num">Saldo</th><th class="num">Cobrar</th></tr></thead>
                <tbody>
                <?php foreach ($pend['debitos'] as $x): $id = (int) $x['fe_clave']; $tild = isset($imputar[$id]) && trim((string) $imputar[$id]) !== ''; ?>
                    <tr><td><input type="checkbox" data-marca data-saldo="<?= e($x['saldo']) ?>" <?= $tild ? 'checked' : '' ?> aria-label="Cobrar este comprobante"></td>
                        <td data-label="Fecha" class="nw"><?= e(fecha($x['fecha'])) ?></td>
                        <td data-label="Comprobante" class="nw"><?= e(CuentaCorrienteService::TIPOS[$x['tipo']] ?? $x['tipo']) ?> <?= e($x['letra']) ?> <?= e(numero_cbte($x['prefijo'], $x['numero_visible'])) ?></td>
                        <td data-label="Vence" class="nw"><?= e(fecha($x['vencimiento'])) ?></td>
                        <td data-label="Saldo" class="num nw"><?= e(plata_dec($x['saldo'])) ?></td>
                        <td data-label="Cobrar" class="num"><input name="imputar[<?= $id ?>]" value="<?= e((string) ($imputar[$id] ?? '')) ?>" inputmode="decimal" data-decimales="2" data-imputar aria-label="Importe a cobrar"></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <button type="button" class="btn btn-ghost btn-sm" data-cobrar-todo>Tildar toda la deuda</button>
        <?php endif; ?>
        <?= $err('imputar') ?>
        <label class="field<?= isset($errores['anticipo']) ? ' has-error' : '' ?>"><span>A cuenta (queda como saldo a favor para imputar después)</span>
            <input name="anticipo" value="<?= e($v('anticipo')) ?>" inputmode="decimal" data-decimales="2" data-anticipo><?= $err('anticipo') ?></label>
    </section>

    <details class="card"<?= $v('descuento') !== '' || array_filter($ret) !== [] ? ' open' : '' ?>>
        <summary><b>Descuento por pronto pago y retenciones</b> <span class="muted small">(si no hay, dejalo cerrado)</span></summary>
        <div class="form-grid">
            <label class="field<?= isset($errores['descuento']) ? ' has-error' : '' ?>"><span>Descuento (con IVA)</span><input name="descuento" value="<?= e($v('descuento')) ?>" inputmode="decimal" data-decimales="2" data-descuento><?= $err('descuento') ?></label>
            <?php foreach (CuentaCorrienteService::RETENCIONES as $cod => $nombre): ?>
                <label class="field"><span>Retención <?= e($nombre) ?></span><input name="ret[<?= $cod ?>]" value="<?= e((string) ($ret[$cod] ?? '')) ?>" inputmode="decimal" data-decimales="2" data-ret></label>
            <?php endforeach; ?>
        </div>
        <?= $err('ret') ?>
    </details>

    <section class="card">
        <h2>Cómo te pagan</h2>
        <div data-pagos>
            <?php foreach ($pagos as $i => $p): ?><?= $filaPago((string) $i, (array) $p) ?><?php endforeach; ?>
        </div>
        <template data-plantilla-pago><?= $filaPago('__N__', []) ?></template>
        <?= $err('pagos') ?>
        <div class="acciones"><button type="button" class="btn btn-ghost btn-sm" data-agregar-pago>+ Otro medio de pago</button>
            <button type="button" class="btn btn-ghost btn-sm" data-completar>Completar el primero con lo que falta</button></div>
    </section>

    <section class="card">
        <dl class="datos datos-col">
            <div><dt>Se cancela</dt><dd data-t-bruto>$ 0,00</dd></div>
            <div><dt>Se cobra (neto)</dt><dd><b data-t-neto>$ 0,00</b></dd></div>
            <div><dt>Medios de pago</dt><dd data-t-pagos>$ 0,00</dd></div>
            <div><dt>Control</dt><dd data-t-dif>—</dd></div>
        </dl>
        <label class="field"><span>Observaciones</span><input name="observaciones" value="<?= e($v('observaciones')) ?>" maxlength="255"></label>
    </section>
    <div class="acciones"><button class="btn" type="submit" data-confirmar-recibo>Grabar recibo</button><a class="btn btn-ghost" href="<?= e(url('/recibos')) ?>">Cancelar</a></div>
</form>
<script src="<?= e(asset('assets/recibo.js')) ?>" defer></script>
