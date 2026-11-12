<?php
/** @var array<string,mixed> $art @var array $filas @var int $total @var int $pagina @var int $porPagina @var array $porDeposito @var float $saldo */
use App\Modules\Stock\StockService;
$link = static fn (int $p) => url('/stock/articulo/' . rawurlencode((string) $art['codigo'])) . '?pagina=' . $p;
$paginas = (int) ceil($total / max(1, $porPagina));
$n = static fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',');
?>
<div class="page-head">
    <div><p class="eyebrow">Stock</p><h1><?= e($art['codigo']) ?> · <?= e($art['descripcion']) ?></h1></div>
    <?php if (puede('stock.mover')): ?><div class="acciones"><a class="btn" href="<?= e(url('/stock/ajuste') . '?articulo=' . rawurlencode((string) $art['codigo'])) ?>">Ingreso / ajuste</a>
        <a class="btn btn-ghost" href="<?= e(url('/stock/transferir') . '?articulo=' . rawurlencode((string) $art['codigo'])) ?>">Transferir</a></div><?php endif; ?>
</div>
<section class="card">
    <p>Stock total: <b><?= e($n($saldo)) ?></b> unidades<?= (int) $art['unidades_por_bulto'] > 0 ? ' (' . e($n($saldo / (int) $art['unidades_por_bulto'])) . ' bultos de ' . (int) $art['unidades_por_bulto'] . ')' : '' ?>
        · mínimo <?= e($n($art['stock_minimo_unidades'])) ?><?= (int) $art['controla_stock'] === 1 ? '' : ' · <span class="chip">no controla stock</span>' ?></p>
    <?php foreach ($porDeposito as $d): ?><span class="chip"><?= e($d['nombre']) ?>: <?= e($n($d['saldo'])) ?></span> <?php endforeach; ?>
</section>
<?php if ($filas === []): ?><p class="muted vacio">Todavía no hay movimientos.</p><?php else: ?>
    <?= paginador($pagina, $paginas, $total, $porPagina, $link, true) ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th>Fecha</th><th>Movimiento</th><th>Depósito</th><th class="num">Cantidad</th><th>Detalle</th><th>Usuario</th></tr></thead>
        <tbody><?php foreach ($filas as $m): ?>
            <tr><td data-label="Fecha" class="nw"><?= e(date('d/m/Y H:i', (int) strtotime($m['fecha']))) ?></td>
                <td data-label="Movimiento"><?= e(StockService::ORIGENES[$m['origen']] ?? $m['origen']) ?></td>
                <td data-label="Depósito"><?= e($m['deposito']) ?></td>
                <td data-label="Cantidad" class="num"><b class="<?= (float) $m['cantidad'] < 0 ? 'texto-error' : '' ?>"><?= (float) $m['cantidad'] > 0 ? '+' : '' ?><?= e($n($m['cantidad'])) ?></b></td>
                <td data-label="Detalle"><?= $m['subdiario_id'] ? '<a href="' . e(url('/comprobantes/' . (int) $m['subdiario_id'])) . '">ver comprobante</a> ' : '' ?><?= e((string) $m['motivo']) ?></td>
                <td data-label="Usuario"><?= e((string) $m['usuario']) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
<?php endif; ?>
