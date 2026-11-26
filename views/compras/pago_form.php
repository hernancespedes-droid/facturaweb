<?php
use App\Modules\Compras\OrdenesPagoService as O;

/**
 * Nueva orden de pago. @var array $datos @var array<string,string> $errores @var ?array $prov @var ?array $provElegir @var ?array $pend
 * @var array $cheques @var string $operacion @var ?array $turno
 */
$d = $datos;
$m = static fn ($v) => number_format((float) $v, 2, ',', '.');
$err = static fn (string $k) => isset($errores[$k]) ? '<p class="field-error">' . e($errores[$k]) . '</p>' : '';
$elegido = static fn (string $grupo, int $id) => trim((string) (is_array($d[$grupo] ?? null) ? ($d[$grupo][$id] ?? '') : ''));
$medios = array_values(array_filter(is_array($d['medios'] ?? null) ? $d['medios'] : [], static fn ($x) => is_array($x) && (($x['medio'] ?? '') !== '' || trim((string) ($x['importe'] ?? '')) !== '')));
if ($medios === []) {
    $medios = [['medio' => 'T']];
}
?>
<div class="page-head"><div><p class="eyebrow">Compras</p><h1>Nueva orden de pago</h1></div>
    <div class="acciones"><a class="btn btn-ghost" href="<?= e(url('/compras/pagos')) ?>">Órdenes emitidas</a></div></div>
<?php if (isset($errores['general'])): ?><div class="alerta alerta-error" role="alert"><?= e($errores['general']) ?></div><?php endif; ?>
<?php if ($errores !== [] && !isset($errores['general'])): ?><div class="alerta alerta-error" role="alert">Revisá lo marcado en rojo.</div><?php endif; ?>

<?php if ($prov === null): ?>
    <form method="get" action="<?= e(url('/compras/pagos/nueva')) ?>" class="card form-grid grid-campos" data-elegir-proveedor data-enviar-al-elegir>
        <h2>¿A quién le pagás?</h2>
        <?php $nombre = 'proveedor'; $titulo = 'Proveedor'; $autofocus = true; include __DIR__ . '/_proveedor.php'; ?>
        <div class="acciones"><button class="btn" type="submit">Ver lo que se le debe</button></div>
    </form>
<?php else: ?>
<form method="post" action="<?= e(url('/compras/pagos/nueva')) ?>" id="form-op">
    <?= csrf_field() ?>
    <input type="hidden" name="operacion" value="<?= e($operacion) ?>">
    <input type="hidden" name="proveedor" value="<?= (int) $prov['id'] ?>">

    <section class="card form-grid grid-campos">
        <h2><?= e($prov['razon_social']) ?></h2>
        <p class="small">C.U.I.T. <?= e(cuit_texto((string) $prov['cuit'])) ?><?= $prov['cbu_alias'] !== '' ? ' · CBU / alias: <b>' . e($prov['cbu_alias']) . '</b>' : '' ?>
            · <a href="<?= e(url('/compras/pagos/nueva')) ?>">Otro proveedor</a> · <a href="<?= e(url('/compras/proveedores/cuenta?proveedor=' . (int) $prov['id'])) ?>">Cuenta corriente</a></p>
        <label class="field"><span>Fecha</span><input name="fecha" value="<?= e((string) ($d['fecha'] ?? date('d/m/Y'))) ?>" inputmode="numeric" placeholder="dd/mm/aaaa" data-fecha><?= $err('fecha') ?></label>
        <label class="field"><span>Observaciones</span><input name="observaciones" value="<?= e((string) ($d['observaciones'] ?? '')) ?>" maxlength="255"></label>
    </section>

    <section class="card">
        <h2>Qué se paga</h2>
        <?= $err('imputaciones') ?>
        <?php if ($pend['debitos'] === [] && $pend['creditos'] === [] && $pend['anticipos'] === []): ?>
            <p class="muted">No le debemos nada a este proveedor. Si le pagás igual, el importe queda como anticipo para la próxima compra.</p>
        <?php endif; ?>
        <?php if ($pend['debitos'] !== []): ?>
        <div class="table-wrap"><table class="table op-tabla" data-grilla="no">
            <thead><tr><th><input type="checkbox" data-todos="deb" aria-label="Tildar todas"></th><th>Comprobante</th><th>Fecha</th><th>Vence</th><th class="num">Saldo</th><th class="num">A pagar</th></tr></thead>
            <tbody><?php foreach ($pend['debitos'] as $x): $v = $elegido('deb', (int) $x['id']); $vencido = $x['vence'] !== null && $x['vence'] < date('Y-m-d'); ?>
                <tr><td><input type="checkbox" data-tilde aria-label="Pagar <?= e($x['titulo']) ?>" <?= $v !== '' ? 'checked' : '' ?>></td>
                    <td data-label="Comprobante"><a href="<?= e(url('/compras/ver/' . (int) $x['id'])) ?>" target="_blank" rel="noopener"><?= e($x['titulo']) ?></a></td>
                    <td data-label="Fecha"><?= e(fecha($x['fecha'])) ?></td>
                    <td data-label="Vence"><?= $x['vence'] ? e(fecha($x['vence'])) : '' ?><?= $vencido ? ' <span class="pill pill-mal">vencida</span>' : '' ?></td>
                    <td data-label="Saldo" class="num"><?= e($m($x['saldo'])) ?></td>
                    <td data-label="A pagar" class="num"><input name="deb[<?= (int) $x['id'] ?>]" value="<?= e($v) ?>" inputmode="decimal" data-decimales="2" data-saldo="<?= e((string) $x['saldo']) ?>" data-imp="deb" aria-label="Importe a pagar de <?= e($x['titulo']) ?>"></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
        <?php if ($pend['creditos'] !== [] || $pend['anticipos'] !== []): ?>
        <h3>A favor nuestro: se descuenta</h3>
        <div class="table-wrap"><table class="table op-tabla" data-grilla="no">
            <thead><tr><th></th><th>Comprobante</th><th>Fecha</th><th class="num">Disponible</th><th class="num">Descontar</th></tr></thead>
            <tbody>
            <?php foreach ($pend['creditos'] as $x): $v = $elegido('cre', (int) $x['id']); ?>
                <tr><td><input type="checkbox" data-tilde aria-label="Descontar <?= e($x['titulo']) ?>" <?= $v !== '' ? 'checked' : '' ?>></td><td data-label="Comprobante"><?= e($x['titulo']) ?></td><td data-label="Fecha"><?= e(fecha($x['fecha'])) ?></td>
                    <td data-label="Disponible" class="num"><?= e($m($x['saldo'])) ?></td>
                    <td data-label="Descontar" class="num"><input name="cre[<?= (int) $x['id'] ?>]" value="<?= e($v) ?>" inputmode="decimal" data-decimales="2" data-saldo="<?= e((string) $x['saldo']) ?>" data-imp="cre" aria-label="Descontar de <?= e($x['titulo']) ?>"></td></tr>
            <?php endforeach; ?>
            <?php foreach ($pend['anticipos'] as $x): $v = $elegido('ant', (int) $x['id']); ?>
                <tr><td><input type="checkbox" data-tilde aria-label="Descontar <?= e($x['titulo']) ?>" <?= $v !== '' ? 'checked' : '' ?>></td><td data-label="Comprobante"><?= e($x['titulo']) ?></td><td data-label="Fecha"><?= e(fecha($x['fecha'])) ?></td>
                    <td data-label="Disponible" class="num"><?= e($m($x['anticipo_saldo'])) ?></td>
                    <td data-label="Descontar" class="num"><input name="ant[<?= (int) $x['id'] ?>]" value="<?= e($v) ?>" inputmode="decimal" data-decimales="2" data-saldo="<?= e((string) $x['anticipo_saldo']) ?>" data-imp="cre" aria-label="Descontar de <?= e($x['titulo']) ?>"></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Con qué se paga</h2>
        <?= $err('medios') ?>
        <div class="table-wrap"><table class="table op-medios" data-grilla="no">
            <thead><tr><th>Medio</th><th>Cheque, certificado o detalle</th><th class="num">Importe</th><th></th></tr></thead>
            <tbody data-filas-medios>
            <?php foreach ($medios as $i => $x): $med = (string) ($x['medio'] ?? ''); ?>
                <tr data-medio-fila>
                    <td><select name="medios[<?= $i ?>][medio]" data-medio aria-label="Medio de pago">
                        <?php foreach (O::MEDIOS as $k => $t): ?><option value="<?= e($k) ?>" <?= $med === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></td>
                    <td><div class="op-detalle">
                        <select name="medios[<?= $i ?>][cheque]" data-solo="Q" data-cheque aria-label="Cheque de la cartera"><option value="">Elegí un cheque de la cartera…</option>
                            <?php foreach ($cheques as $ch): ?><option value="<?= (int) $ch['id'] ?>" data-importe="<?= e((string) $ch['importe']) ?>" <?= (int) ($x['cheque'] ?? 0) === (int) $ch['id'] ? 'selected' : '' ?>>
                                N° <?= e((string) $ch['numero']) ?> · <?= e((string) ($ch['banco'] ?? '')) ?> · paga <?= e($ch['vence'] ? fecha($ch['vence']) : '—') ?> · $ <?= e($m($ch['importe'])) ?></option><?php endforeach; ?></select>
                        <select name="medios[<?= $i ?>][retencion]" data-solo="R" aria-label="Retención"><option value="">¿Qué retención?</option>
                            <?php foreach (O::RETENCIONES as $k => $t): ?><option value="<?= e($k) ?>" <?= ($x['retencion'] ?? '') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
                        <input name="medios[<?= $i ?>][numero]" value="<?= e((string) ($x['numero'] ?? '')) ?>" maxlength="30" data-solo="P R" placeholder="N° de cheque o certificado" aria-label="Número">
                        <input name="medios[<?= $i ?>][vence]" value="<?= e((string) ($x['vence'] ?? '')) ?>" data-solo="P" placeholder="Paga el dd/mm/aaaa" data-fecha inputmode="numeric" aria-label="Fecha de pago del cheque">
                        <input name="medios[<?= $i ?>][detalle]" value="<?= e((string) ($x['detalle'] ?? '')) ?>" maxlength="120" data-solo="E T P O" placeholder="Banco, cuenta, número de operación…" aria-label="Detalle">
                    </div></td>
                    <td class="num"><input name="medios[<?= $i ?>][importe]" value="<?= e((string) ($x['importe'] ?? '')) ?>" inputmode="decimal" data-decimales="2" data-medio-imp aria-label="Importe"></td>
                    <td><button type="button" class="btn btn-ghost btn-sm" data-quitar aria-label="Quitar">✕</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <p><button type="button" class="btn btn-ghost btn-sm" data-agregar-medio>+ Agregar medio de pago</button>
            <?php if ($cheques === []): ?><span class="small muted">No hay cheques de terceros en la cartera.</span><?php endif; ?></p>
        <?php if ($turno === null): ?><p class="small"><span class="pill pill-warn">Sin turno de caja</span> Para pagar en efectivo abrí tu turno en <a href="<?= e(url('/caja')) ?>">Mi caja</a>.</p><?php endif; ?>

        <div class="compra-total" data-op-totales>
            <div><span class="muted small">A pagar</span><br><b data-op-apagar>$ 0,00</b></div>
            <div><span class="muted small">Medios</span><br><b data-op-pagado>$ 0,00</b></div>
            <div><span class="muted small" data-op-dif-titulo>Diferencia</span><br><b data-op-dif>$ 0,00</b></div>
        </div>
    </section>

    <div class="acciones">
        <button class="btn" type="submit">Emitir orden de pago</button>
        <a class="btn btn-ghost" href="<?= e(url('/compras/a-pagar')) ?>">Cancelar</a>
    </div>
</form>
<?php endif; ?>
<script src="<?= e(asset('assets/ventana-lista.js')) ?>" defer></script>
<script src="<?= e(asset('assets/cliente-picker.js')) ?>" defer></script>
<script src="<?= e(asset('assets/ordenes-pago.js')) ?>" defer></script>
