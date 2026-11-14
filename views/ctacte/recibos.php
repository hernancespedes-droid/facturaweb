<?php
/** @var array<string,string> $f @var array<int,array<string,mixed>> $filas @var int $total @var int $pagina @var int $porPagina */
$link = static fn (int $p) => url('/recibos') . '?' . http_build_query(['pagina' => $p] + $f);
$paginas = (int) ceil($total / max(1, $porPagina));
?>
<div class="page-head">
    <div><p class="eyebrow">Cuenta corriente</p><h1>Recibos</h1></div>
    <div class="acciones">
        <?php if (puede('ctacte.cobrar')): ?><a class="btn" href="<?= e(url('/recibos/nuevo')) ?>">+ Nuevo recibo</a><?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('/recibos') . '?' . http_build_query($f + ['excel' => '1'])) ?>">Excel</a>
    </div>
</div>
<form method="get" action="<?= e(url('/recibos')) ?>" class="toolbar filtros">
    <label class="field"><span>Desde</span><input name="desde" value="<?= e($f['desde'] ?? '') ?>" placeholder="dd/mm/aaaa" inputmode="numeric"></label>
    <label class="field"><span>Hasta</span><input name="hasta" value="<?= e($f['hasta'] ?? '') ?>" placeholder="dd/mm/aaaa" inputmode="numeric"></label>
    <input name="cliente" value="<?= e($f['cliente'] ?? '') ?>" placeholder="Cliente o número de recibo" type="search" aria-label="Cliente">
    <button class="btn btn-ghost" type="submit">Buscar</button>
</form>
<?php if ($filas === []): ?>
    <p class="muted vacio">No hay recibos para esos filtros.</p>
<?php else: ?>
    <?= paginador($pagina, $paginas, $total, $porPagina, $link, true) ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th>Fecha</th><th>Recibo</th><th>Cliente</th><th class="num">Cancela</th><th class="num">Cobrado</th><th>Estado</th></tr></thead>
        <tbody>
        <?php foreach ($filas as $r): ?>
            <tr><td data-label="Fecha" class="nw"><?= e(fecha($r['fecha'])) ?></td>
                <td data-label="Recibo" class="nw"><a href="<?= e(url('/recibos/' . (int) $r['id'])) ?>"><b>Recibo</b> <?= e(numero_cbte($r['prefijo'], $r['recibo'])) ?></a></td>
                <td data-label="Cliente"><?= e($r['razon_social'] ?? '') ?> <small class="muted">(<?= (int) $r['cliente'] ?>)</small></td>
                <td data-label="Cancela" class="num nw"><?= e(plata_dec($r['bruto'])) ?></td>
                <td data-label="Cobrado" class="num nw"><?= e(plata_dec($r['neto'])) ?></td>
                <td data-label="Estado"><?= $r['estado'] === 'A' ? '<span class="badge badge-off">Anulado</span>' : '' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
