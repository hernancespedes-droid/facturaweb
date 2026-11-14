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
    <form method="post" action="<?= e(url('/cuenta-corriente/imputar')) ?>" class="stack"><?= csrf_field() ?>
        <input type="hidden" name="cliente" value="<?= (int) $cli['codigo'] ?>">
        <section class="card"><h2>Saldos a favor (qué se aplica)</h2>
            <div class="table-wrap"><table class="table table-cards"><thead><tr><th>Comprobante</th><th>Fecha</th><th class="num">Saldo a favor</th><th class="num">Aplicar</th></tr></thead><tbody>
            <?php foreach ($pend['creditos'] as $x): ?>
                <tr><td data-label="Comprobante"><?= e(CuentaCorrienteService::TIPOS[$x['tipo']] ?? $x['tipo']) ?> <?= e($x['letra']) ?> <?= e(numero_cbte($x['prefijo'], $x['numero_visible'])) ?></td>
                    <td data-label="Fecha"><?= e(fecha($x['fecha'])) ?></td><td data-label="Saldo a favor" class="num nw"><?= e(plata_dec($x['saldo'])) ?></td>
                    <td data-label="Aplicar" class="num"><input name="credito[<?= (int) $x['fe_clave'] ?>]" inputmode="decimal" data-decimales="2" aria-label="Importe a aplicar"></td></tr>
            <?php endforeach; ?></tbody></table></div></section>
        <section class="card"><h2>Deuda (qué se cancela)</h2>
            <div class="table-wrap"><table class="table table-cards"><thead><tr><th>Comprobante</th><th>Fecha</th><th class="num">Saldo</th><th class="num">Cancelar</th></tr></thead><tbody>
            <?php foreach ($pend['debitos'] as $x): ?>
                <tr><td data-label="Comprobante"><?= e(CuentaCorrienteService::TIPOS[$x['tipo']] ?? $x['tipo']) ?> <?= e($x['letra']) ?> <?= e(numero_cbte($x['prefijo'], $x['numero_visible'])) ?></td>
                    <td data-label="Fecha"><?= e(fecha($x['fecha'])) ?></td><td data-label="Saldo" class="num nw"><?= e(plata_dec($x['saldo'])) ?></td>
                    <td data-label="Cancelar" class="num"><input name="debito[<?= (int) $x['fe_clave'] ?>]" inputmode="decimal" data-decimales="2" aria-label="Importe a cancelar"></td></tr>
            <?php endforeach; ?></tbody></table></div></section>
        <p class="small muted">Lo que aplicás de los saldos a favor tiene que ser igual a lo que cancelás de la deuda.</p>
        <div class="acciones"><button class="btn" type="submit">Imputar</button><a class="btn btn-ghost" href="<?= e(url('/cuenta-corriente/cliente/' . (int) $cli['codigo'])) ?>">Cancelar</a></div>
    </form>
    <?php endif; ?>
<?php endif; ?>
