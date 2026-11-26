<?php
use App\Modules\Stock\Existencias;

/** Existencias por depósito a una fecha. @var array<string,string> $f @var array $r @var array $depositos @var array $opciones @var bool $costos @var int $pagina @var int $porPagina */
$u = url('/stock/existencias');
$link = static fn (int $p) => $u . '?' . http_build_query(['pagina' => $p] + $f);
$paginas = (int) ceil($r['total'] / max(1, $porPagina));
$n = static fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',');
$m = static fn ($v) => number_format((float) $v, 2, ',', '.');
$valorizar = $costos && in_array($f['valorizar'] ?? '', ['costo', 'precio'], true);
$cols = ($f['deposito'] ?? '') === '' ? $depositos : array_values(array_filter($depositos, static fn (array $d) => (string) $d['id'] === $f['deposito']));
?>
<div class="page-head">
    <div><p class="eyebrow">Stock</p><h1>Existencias por depósito</h1></div>
    <div class="acciones"><button type="button" class="btn btn-ghost" onclick="window.print()">Imprimir</button></div>
</div>
<p class="small muted no-imprimir">Stock de cada artículo por depósito, hoy o a una fecha pasada (se suman los movimientos hasta ese día inclusive). Solo artículos que controlan stock.</p>
<form method="get" action="<?= e($u) ?>" class="toolbar filtros no-imprimir">
    <label class="field"><span>A la fecha</span><input name="fecha" value="<?= e($f['fecha'] ?? '') ?>" placeholder="hoy" inputmode="numeric" data-fecha></label>
    <input type="search" name="q" value="<?= e($f['q'] ?? '') ?>" placeholder="Código o descripción" aria-label="Buscar">
    <?php include __DIR__ . '/../listados/_rubros.php'; ?>
    <label class="field"><span>Proveedor (código)</span><input name="proveedor" value="<?= e($f['proveedor'] ?? '') ?>" inputmode="numeric" size="6"></label>
    <label class="field"><span>Depósito</span><select name="deposito"><option value="">Todos</option>
        <?php foreach ($depositos as $d): ?><option value="<?= (int) $d['id'] ?>" <?= ($f['deposito'] ?? '') === (string) $d['id'] ? 'selected' : '' ?>><?= e($d['nombre']) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Mostrar</span><select name="mostrar">
        <?php foreach (Existencias::MOSTRAR as $k => $x): ?><option value="<?= e($k) ?>" <?= ($f['mostrar'] ?? '') === $k ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></label>
    <?php if ($costos): ?>
        <label class="field"><span>Valorizar</span><select name="valorizar"><option value="">No</option>
            <option value="costo" <?= ($f['valorizar'] ?? '') === 'costo' ? 'selected' : '' ?>>A costo</option>
            <option value="precio" <?= ($f['valorizar'] ?? '') === 'precio' ? 'selected' : '' ?>>A precio de lista 1</option></select></label>
    <?php endif; ?>
    <button class="btn" type="submit">Ver</button>
</form>
<p class="small">Stock <?= $r['fecha'] === null ? 'de <b>hoy</b>' : 'al <b>' . e(date('d/m/Y', (int) strtotime($r['fecha']))) . '</b>' ?>:
    <b><?= e($n($r['totales']['unidades'])) ?></b> unidades en <?= (int) $r['total'] ?> artículos<?= $valorizar ? ', valorizado <b>$ ' . e($m($r['totales']['valorizado'])) . '</b> (' . (($f['valorizar'] ?? '') === 'precio' ? 'precio de lista 1' : 'costo') . ' de hoy)' : '' ?>.</p>
<?php if ($r['filas'] === []): ?>
    <p class="muted vacio">No hay artículos con esos filtros.</p>
<?php else: ?>
    <?= paginador($pagina, $paginas, $r['total'], $porPagina, $link, true) ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><?= th_orden('Código', 'codigo', $f, $u) ?><?= th_orden('Descripción', 'descripcion', $f, $u) ?><?= th_orden('Rubro', 'rubro', $f, $u) ?>
            <?php if (count($cols) > 1): foreach ($cols as $d): ?><?= th_orden((string) $d['nombre'], 'dep_' . (int) $d['id'], $f, $u, true) ?><?php endforeach; endif; ?>
            <?= th_orden('Total', 'total', $f, $u, true) ?><th class="num">Bultos</th><?= th_orden('Mínimo', 'minimo', $f, $u, true) ?><th class="num">Falta para el mínimo</th>
            <?php if ($valorizar): ?><?= th_orden('Valorizado', 'valorizado', $f, $u, true) ?><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($r['filas'] as $x): $tot = (float) $x['total']; $min = (float) $x['minimo']; ?>
            <tr><td data-label="Código" class="nw"><a href="<?= e(url('/stock/articulo/' . rawurlencode((string) $x['codigo']))) ?>"><?= e($x['codigo']) ?></a></td>
                <td data-label="Descripción"><?= e($x['descripcion']) ?></td>
                <td data-label="Rubro"><?= e((string) $x['rubro']) ?></td>
                <?php if (count($cols) > 1): foreach ($cols as $d): ?><td data-label="<?= e($d['nombre']) ?>" class="num"><?= e($n($x['dep_' . (int) $d['id']])) ?></td><?php endforeach; endif; ?>
                <td data-label="Total" class="num"><b class="<?= $tot < 0 ? 'texto-error' : '' ?>"><?= e($n($tot)) ?></b><?= $min > 0 && $tot < $min ? ' <span class="chip">bajo mínimo</span>' : '' ?></td>
                <td data-label="Bultos" class="num"><?= (int) $x['unidades_por_bulto'] > 0 ? e($n($tot / (int) $x['unidades_por_bulto'])) : '—' ?></td>
                <td data-label="Mínimo" class="num"><?= $min > 0 ? e($n($min)) : '' ?></td>
                <td data-label="Falta para el mínimo" class="num"><?= $min > 0 && $tot < $min ? e($n($min - $tot)) : '' ?></td>
                <?php if ($valorizar): ?><td data-label="Valorizado" class="num"><?= e($m($x['valorizado'])) ?></td><?php endif; ?></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
