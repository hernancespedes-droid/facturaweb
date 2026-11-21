<?php
use App\Modules\Compras\ProveedoresService as P;

/** Proveedores. @var array $f @var array $r @var int $pagina @var int $porPagina @var bool $puedeCargar */
$u = url('/compras/proveedores');
$m = static fn ($v) => number_format((float) $v, 2, ',', '.');
$link = static fn (int $p) => $u . '?' . http_build_query(['pagina' => $p] + $f);
$paginas = (int) ceil($r['total'] / max(1, $porPagina));
?>
<div class="page-head">
    <div><p class="eyebrow">Compras</p><h1>Proveedores</h1></div>
    <div class="acciones">
        <a class="btn btn-ghost" href="<?= e($u . '?' . http_build_query(['excel' => 1] + $f)) ?>">Excel</a>
        <?php if ($puedeCargar): ?><a class="btn" href="<?= e(url('/compras/proveedores/ficha')) ?>">Nuevo proveedor</a><?php endif; ?>
    </div>
</div>
<form method="get" action="<?= e($u) ?>" class="toolbar filtros">
    <input type="search" name="q" value="<?= e($f['q'] ?? '') ?>" placeholder="Nombre, código o CUIT" aria-label="Buscar">
    <select name="estado" aria-label="Estado"><?php foreach (['activos' => 'Activos', 'inactivos' => 'Inactivos', 'todos' => 'Todos'] as $k => $t): ?><option value="<?= e($k) ?>" <?= ($f['estado'] ?? 'activos') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
    <button class="btn" type="submit">Buscar</button>
</form>
<?php if ($r['filas'] === []): ?>
    <p class="muted vacio">No hay proveedores<?= ($f['q'] ?? '') !== '' ? ' con esa búsqueda' : ' cargados todavía' ?>.</p>
<?php else: ?>
    <?= paginador($pagina, $paginas, $r['total'], $porPagina, $link, true) ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><?= th_orden('Código', 'codigo', $f, $u) ?><?= th_orden('Razón social', 'razon_social', $f, $u) ?><?= th_orden('CUIT', 'cuit', $f, $u) ?><th>Condición</th>
            <?= th_orden('Localidad', 'localidad', $f, $u) ?><th>Centro y concepto habitual</th><?= th_orden('Le debemos', 'saldo', $f, $u, true) ?></tr></thead>
        <tbody><?php foreach ($r['filas'] as $x): ?>
            <tr<?= (int) $x['activo'] === 0 ? ' class="muted"' : '' ?>><td data-label="Código" class="num"><?= (int) $x['codigo'] ?></td>
                <td data-label="Razón social"><a href="<?= e(url('/compras/proveedores/ficha?id=' . (int) $x['id'])) ?>"><?= e($x['razon_social']) ?></a></td>
                <td data-label="CUIT" class="nw"><?= e(cuit_texto($x['cuit'])) ?></td>
                <td data-label="Condición" class="small"><?= e(P::CONDICIONES[(int) $x['iva']] ?? '') ?></td>
                <td data-label="Localidad"><?= e($x['localidad']) ?></td>
                <td data-label="Centro y concepto" class="small"><?= e(trim(($x['centro'] ?? '') . ' · ' . ($x['concepto'] ?? ''), ' ·')) ?></td>
                <td data-label="Le debemos" class="num"><?= abs((float) $x['saldo']) > 0.005 ? '<b>' . e($m($x['saldo'])) . '</b>' : '' ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?= paginador($pagina, $paginas, $r['total'], $porPagina, $link) ?>
<?php endif; ?>
