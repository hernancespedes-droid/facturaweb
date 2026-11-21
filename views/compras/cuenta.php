<?php
/** Cuenta corriente de un proveedor. @var ?array $prov @var ?array $r @var string $desde @var string $hasta @var ?array $pend @var bool $puedePagar */
$u = url('/compras/proveedores/cuenta');
$m = static fn ($v) => number_format((float) $v, 2, ',', '.');
$q = ['proveedor' => (int) ($prov['id'] ?? 0), 'desde' => $desde, 'hasta' => $hasta];
?>
<div class="page-head">
    <div><p class="eyebrow">Compras › Proveedores</p><h1>Cuenta corriente</h1></div>
    <?php if ($prov !== null): ?><div class="acciones">
        <a class="btn btn-ghost" href="<?= e($u . '?' . http_build_query(['excel' => 1] + $q)) ?>">Excel</a>
        <?php if ($puedePagar): ?><a class="btn" href="<?= e(url('/compras/pagos/nueva?proveedor=' . (int) $prov['id'])) ?>">Nueva orden de pago</a><?php endif; ?>
    </div><?php endif; ?>
</div>
<form method="get" action="<?= e($u) ?>" class="toolbar filtros" data-enviar-al-elegir>
    <?php $nombre = 'proveedor'; $titulo = 'Proveedor'; $autofocus = $prov === null; include __DIR__ . '/_proveedor.php'; ?>
    <label class="field"><span>Desde</span><input name="desde" value="<?= e($desde) ?>" placeholder="dd/mm/aaaa" inputmode="numeric"></label>
    <label class="field"><span>Hasta</span><input name="hasta" value="<?= e($hasta) ?>" placeholder="dd/mm/aaaa" inputmode="numeric"></label>
    <button class="btn" type="submit">Ver</button>
</form>

<?php if ($prov === null): ?>
    <p class="muted vacio">Elegí un proveedor para ver sus movimientos.</p>
<?php else: ?>
    <section class="card resumen-fila" aria-label="Saldo">
        <div><span class="muted small"><?= $pend['saldo'] >= 0 ? 'Le debemos' : 'Tenemos a favor' ?></span><b>$ <?= e($m(abs($pend['saldo']))) ?></b></div>
        <div><span class="muted small">Comprobantes pendientes</span><b><?= count($pend['debitos']) ?></b></div>
        <?php if ($pend['creditos'] !== [] || $pend['anticipos'] !== []): ?><div><span class="muted small">Notas de crédito y anticipos sin usar</span><b><?= count($pend['creditos']) + count($pend['anticipos']) ?></b></div><?php endif; ?>
        <?php if ($prov['cbu_alias'] !== ''): ?><div><span class="muted small">CBU / alias</span><b><?= e($prov['cbu_alias']) ?></b></div><?php endif; ?>
    </section>
    <?php if ($r['filas'] === []): ?>
        <p class="muted vacio">Sin movimientos a cuenta corriente en esas fechas.</p>
    <?php else: ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th>Fecha</th><th>Comprobante</th><th>Vence</th><th class="num">Debe</th><th class="num">Haber</th><th class="num">Saldo</th></tr></thead>
        <tbody>
        <?php if ($desde !== ''): ?><tr><td colspan="5" data-label="">Saldo anterior</td><td class="num" data-label="Saldo"><?= e($m($r['anterior'])) ?></td></tr><?php endif; ?>
        <?php foreach ($r['filas'] as $x): $link = $x['clase'] === 'orden' ? url('/compras/pagos/ver/' . $x['id']) : url('/compras/ver/' . $x['id']); ?>
            <tr><td data-label="Fecha" class="nw"><?= e(fecha($x['fecha'])) ?></td><td data-label="Comprobante"><a href="<?= e($link) ?>"><?= e($x['titulo']) ?></a></td>
                <td data-label="Vence"><?= $x['vence'] ? e(fecha($x['vence'])) : '' ?></td>
                <td data-label="Debe" class="num"><?= $x['debe'] > 0 ? e($m($x['debe'])) : '' ?></td><td data-label="Haber" class="num"><?= $x['haber'] > 0 ? e($m($x['haber'])) : '' ?></td>
                <td data-label="Saldo" class="num"><b><?= e($m($x['saldo'])) ?></b></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="small muted">Debe: compras a cuenta corriente. Haber: notas de crédito, órdenes de pago y pagos. El saldo es lo que se le debe al proveedor (negativo: a favor nuestro). Las compras pagadas en el momento no figuran.</p>
    <?php endif; ?>
<?php endif; ?>
<script src="<?= e(asset('assets/ventana-lista.js')) ?>" defer></script>
<script src="<?= e(asset('assets/cliente-picker.js')) ?>" defer></script>
