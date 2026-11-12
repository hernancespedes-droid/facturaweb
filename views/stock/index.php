<?php
/** @var array<string,string> $f @var array<int,array<string,mixed>> $filas @var int $total @var int $pagina @var int $porPagina @var array $depositos @var bool $activo @var bool $puedeMover */
$link = static fn (int $p) => url('/stock') . '?' . http_build_query(['pagina' => $p] + $f);
$paginas = (int) ceil($total / max(1, $porPagina));
$n = static fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',');
?>
<div class="page-head">
    <div><p class="eyebrow">Stock</p><h1>Saldos de stock</h1></div>
    <?php if ($puedeMover): ?><div class="acciones"><a class="btn" href="<?= e(url('/stock/ajuste')) ?>">Ingreso / ajuste</a><a class="btn btn-ghost" href="<?= e(url('/stock/transferir')) ?>">Transferir</a></div><?php endif; ?>
</div>
<?php if (!$activo): ?>
    <div class="alerta alerta-info">El control de stock está <b>apagado</b>: las facturas todavía no descuentan. Se activa en Sistema → Configuración (<code>stock.controlar</code>).
        Antes cargá el stock inicial con «Ingreso / ajuste».</div>
<?php endif; ?>
<form method="get" action="<?= e(url('/stock')) ?>" class="toolbar filtros">
    <input name="q" value="<?= e($f['q'] ?? '') ?>" placeholder="Código, SKU, código de barras o descripción" type="search" aria-label="Buscar">
    <select name="deposito" aria-label="Depósito"><option value="">Todos los depósitos</option>
        <?php foreach ($depositos as $d): ?><option value="<?= (int) $d['id'] ?>" <?= (string) ($f['deposito'] ?? '') === (string) $d['id'] ? 'selected' : '' ?>><?= e($d['nombre']) ?></option><?php endforeach; ?></select>
    <label class="check"><input type="checkbox" name="bajo" value="1" <?= ($f['bajo'] ?? '') === '1' ? 'checked' : '' ?>> Bajo el mínimo</label>
    <label class="check"><input type="checkbox" name="todos" value="1" <?= ($f['todos'] ?? '') === '1' ? 'checked' : '' ?>> Incluir sin stock</label>
    <button class="btn btn-ghost" type="submit">Buscar</button>
</form>
<?php if ($filas === []): ?>
    <p class="muted vacio">No hay artículos con esos filtros.</p>
<?php else: ?>
    <?= paginador($pagina, $paginas, $total, $porPagina, $link, true) ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th>Código</th><th>Descripción</th><th>SKU</th><th class="num">Stock (unidades)</th><th class="num">Bultos</th><th class="num">Mínimo</th></tr></thead>
        <tbody>
        <?php foreach ($filas as $r): $bajo = (float) $r['saldo'] < (float) $r['minimo']; ?>
            <tr><td data-label="Código" class="nw"><a href="<?= e(url('/stock/articulo/' . rawurlencode((string) $r['codigo']))) ?>"><?= e($r['codigo']) ?></a></td>
                <td data-label="Descripción"><?= e($r['descripcion']) ?></td>
                <td data-label="SKU"><?= e((string) $r['sku']) ?></td>
                <td data-label="Stock" class="num"><b class="<?= (float) $r['saldo'] < 0 ? 'texto-error' : '' ?>"><?= e($n($r['saldo'])) ?></b><?= $bajo ? ' <span class="chip">bajo mínimo</span>' : '' ?></td>
                <td data-label="Bultos" class="num"><?= (int) $r['unidades_por_bulto'] > 0 ? e($n((float) $r['saldo'] / (int) $r['unidades_por_bulto'])) : '—' ?></td>
                <td data-label="Mínimo" class="num"><?= e($n($r['minimo'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
<?php if (puede('stock.administrar')): ?>
    <form method="post" action="<?= e(url('/stock/recalcular')) ?>" data-confirmar="Se recalculan todos los saldos sumando los movimientos. ¿Seguro?"><?= csrf_field() ?>
        <p class="small muted">Los saldos se guardan al lado de cada movimiento para que la consulta sea instantánea. Si alguna vez dudás de un saldo:
            <button class="btn btn-ghost btn-sm" type="submit">Recalcular saldos desde los movimientos</button></p>
    </form>
<?php endif; ?>
