<?php
/** @var array<string,string> $f @var array<int,array<string,mixed>> $filas @var int $total @var array<string,float> $totales @var int $pagina @var int $porPagina */
$link = static fn (int $p) => url('/cuenta-corriente') . '?' . http_build_query(['pagina' => $p] + $f);
$paginas = (int) ceil($total / max(1, $porPagina));
?>
<div class="page-head">
    <div><p class="eyebrow">Cuenta corriente</p><h1>Deuda de clientes</h1></div>
    <div class="acciones">
        <?php if (puede('ctacte.cobrar')): ?><a class="btn" href="<?= e(url('/recibos/nuevo')) ?>">+ Nuevo recibo</a><?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('/cuenta-corriente') . '?' . http_build_query($f + ['excel' => '1'])) ?>">Excel</a>
    </div>
</div>
<?php $cli = null; $accion = '/cuenta-corriente/cliente'; $boton = 'Ver la cuenta del cliente'; require __DIR__ . '/_picker.php'; ?>
<form method="get" action="<?= e(url('/cuenta-corriente')) ?>" class="toolbar filtros">
    <input name="q" value="<?= e($f['q'] ?? '') ?>" placeholder="Filtrar por nombre o código" type="search" aria-label="Filtrar">
    <label class="field"><span>Vencido al</span><input name="al" value="<?= e($f['al'] ?? '') ?>" placeholder="hoy" inputmode="numeric"></label>
    <label class="check"><input type="checkbox" name="solo_deudores" value="1" <?= ($f['solo_deudores'] ?? '') === '1' ? 'checked' : '' ?>> Solo los que deben</label>
    <button class="btn btn-ghost" type="submit">Buscar</button>
</form>
<?php if ($filas === []): ?>
    <p class="muted vacio">No hay clientes con saldo para esos filtros.</p>
<?php else: ?>
    <div class="resumen-cifras small"><b>Deuda:</b> <?= e(plata_dec($totales['deuda'])) ?> · <b>A favor:</b> <?= e(plata_dec($totales['a_favor'])) ?> · <b>Saldo:</b> <?= e(plata_dec($totales['saldo'])) ?> · <b>Vencido:</b> <span class="texto-error"><?= e(plata_dec($totales['vencido'])) ?></span> · <?= (int) $total ?> cliente(s)</div>
    <?= paginador($pagina, $paginas, $total, $porPagina, $link, true) ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th>Cliente</th><th class="num">Deuda</th><th class="num">A favor</th><th class="num">Saldo</th><th class="num">Vencido</th><th>Más antiguo</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($filas as $r): ?>
            <tr><td data-label="Cliente"><a href="<?= e(url('/cuenta-corriente/cliente/' . (int) $r['cliente'])) ?>"><?= e($r['razon_social'] ?? '(cliente borrado)') ?></a> <small class="muted">(<?= (int) $r['cliente'] ?>)</small></td>
                <td data-label="Deuda" class="num nw"><?= e(plata_dec($r['deuda'])) ?></td>
                <td data-label="A favor" class="num nw"><?= (float) $r['a_favor'] > 0 ? e(plata_dec($r['a_favor'])) : '—' ?></td>
                <td data-label="Saldo" class="num nw"><b><?= e(plata_dec($r['saldo'])) ?></b></td>
                <td data-label="Vencido" class="num nw"><?= (float) $r['vencido'] > 0 ? '<span class="texto-error">' . e(plata_dec($r['vencido'])) . '</span>' : '—' ?></td>
                <td data-label="Más antiguo" class="nw"><?= e(fecha($r['primer_vencimiento'])) ?></td>
                <td class="nw"><?php if (puede('ctacte.cobrar')): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('/recibos/nuevo?cliente=' . (int) $r['cliente'])) ?>">Cobrar</a><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
