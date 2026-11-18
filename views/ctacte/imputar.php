<?php
use App\Modules\CuentaCorriente\CuentaCorrienteService;

/** @var ?array<string,mixed> $cli @var ?string $error @var array{debitos:array,creditos:array} $pend */
?>
<div class="page-head"><div><p class="eyebrow">Cuenta corriente</p><h1>Imputación manual</h1></div></div>
<?php if ($cli === null): ?>
    <p class="muted">Cancelá deuda con notas de crédito, anticipos de recibos o ajustes de crédito. Primero elegí el cliente.</p>
    <?php $accion = '/cuenta-corriente/imputar'; $boton = 'Seguir'; require __DIR__ . '/_picker.php'; ?>
<?php else: ?>
    <p><a href="<?= e(url('/cuenta-corriente/imputar')) ?>">← Elegir otro cliente</a> · <b><?= e($cli['razon_social']) ?></b> <span class="muted">(<?= (int) $cli['codigo'] ?>)</span></p>
    <?php if ($error): ?><div class="alerta alerta-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($pend['creditos'] === [] || $pend['debitos'] === []): ?>
        <p class="muted vacio"><?= $pend['creditos'] === [] ? 'El cliente no tiene saldos a favor para imputar.' : 'El cliente no tiene deuda para cancelar.' ?></p>
    <?php else: ?>
    <form method="post" action="<?= e(url('/cuenta-corriente/imputar')) ?>" class="stack" data-imputador><?= csrf_field() ?>
        <input type="hidden" name="cliente" value="<?= (int) $cli['codigo'] ?>">
        <section class="card imputador-resumen" aria-live="polite">
            <div><span class="muted small">1. A imputar (saldos a favor tildados)</span><b data-t-aimputar>$ 0,00</b></div>
            <div><span class="muted small">2. Ya imputado a la deuda</span><b data-t-imputado>$ 0,00</b></div>
            <div><span class="muted small">Falta imputar</span><b data-t-falta class="texto-error">$ 0,00</b></div>
        </section>
        <p class="small muted" data-ayuda>Primero tildá a la izquierda las notas de crédito y recibos que querés aplicar. Después tildá a la derecha las facturas y débitos: cada una se cancela hasta agotar lo que hay para imputar.</p>
        <div class="grid-2 imputador">
        <section class="card"><h2>Saldos a favor <span class="muted small">(notas de crédito y recibos)</span></h2>
            <div class="table-wrap"><table class="table table-cards"><thead><tr><th></th><th>Comprobante</th><th>Fecha</th><th class="num">Saldo a favor</th><th class="num">Aplicar</th></tr></thead><tbody>
            <?php foreach ($pend['creditos'] as $x): ?>
                <tr><td><input type="checkbox" data-marca-cr data-saldo="<?= e($x['saldo']) ?>" aria-label="Aplicar este saldo a favor"></td>
                    <td data-label="Comprobante"><?= e(CuentaCorrienteService::TIPOS[$x['tipo']] ?? $x['tipo']) ?> <?= e($x['letra']) ?> <?= e(numero_cbte($x['prefijo'], $x['numero_visible'])) ?></td>
                    <td data-label="Fecha"><?= e(fecha($x['fecha'])) ?></td><td data-label="Saldo a favor" class="num nw"><?= e(plata_dec($x['saldo'])) ?></td>
                    <td data-label="Aplicar" class="num"><input name="credito[<?= (int) $x['fe_clave'] ?>]" data-imp-cr inputmode="decimal" data-decimales="2" aria-label="Importe a aplicar"></td></tr>
            <?php endforeach; ?></tbody></table></div></section>
        <section class="card"><h2>Deuda <span class="muted small">(facturas y débitos)</span></h2>
            <div class="table-wrap"><table class="table table-cards"><thead><tr><th></th><th>Comprobante</th><th>Fecha</th><th class="num">Saldo</th><th class="num">Cancelar</th></tr></thead><tbody>
            <?php foreach ($pend['debitos'] as $x): ?>
                <tr><td><input type="checkbox" data-marca-db data-saldo="<?= e($x['saldo']) ?>" aria-label="Cancelar este comprobante"></td>
                    <td data-label="Comprobante"><?= e(CuentaCorrienteService::TIPOS[$x['tipo']] ?? $x['tipo']) ?> <?= e($x['letra']) ?> <?= e(numero_cbte($x['prefijo'], $x['numero_visible'])) ?></td>
                    <td data-label="Fecha"><?= e(fecha($x['fecha'])) ?></td><td data-label="Saldo" class="num nw"><?= e(plata_dec($x['saldo'])) ?></td>
                    <td data-label="Cancelar" class="num"><input name="debito[<?= (int) $x['fe_clave'] ?>]" data-imp-db inputmode="decimal" data-decimales="2" aria-label="Importe a cancelar"></td></tr>
            <?php endforeach; ?></tbody></table></div></section>
        </div>
        <div class="acciones"><button class="btn" type="submit" data-imputar-btn disabled>Imputar</button><a class="btn btn-ghost" href="<?= e(url('/cuenta-corriente/cliente/' . (int) $cli['codigo'])) ?>">Cancelar</a></div>
    </form>
    <script src="<?= e(asset('assets/imputador.js')) ?>" defer></script>
    <?php endif; ?>
<?php endif; ?>
