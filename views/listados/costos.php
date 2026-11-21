<?php
/** Lista de costos y márgenes (permiso listados.costos). @var array<string,string> $f @var array $r @var array $opciones @var int $pagina @var int $porPagina */
$u = url('/listados/costos');
$link = static fn (int $p) => $u . '?' . http_build_query(['pagina' => $p] + $f);
$paginas = (int) ceil($r['total'] / max(1, $porPagina));
$m = static fn ($v) => number_format((float) $v, 2, ',', '.');
$pct = static fn ($v) => $v === null ? '—' : number_format((float) $v, 1, ',', '.') . ' %';
$ver = ['' => 'Todos', 'sin_costo' => 'Sin costo cargado', 'bajo_costo' => 'Precio menor al costo', 'margen_menor' => 'Margen menor a…'];
?>
<div class="page-head">
    <div><p class="eyebrow">Listados</p><h1>Costos y márgenes</h1></div>
    <div class="acciones"><button type="button" class="btn btn-ghost" onclick="window.print()">Imprimir</button></div>
</div>
<p class="small muted no-imprimir">Costo de cada artículo pasado a pesos y el margen real sobre el costo con el precio de lista 1 (sin IVA), comparado con el margen cargado en la ficha.</p>
<form method="get" action="<?= e($u) ?>" class="toolbar filtros no-imprimir">
    <input type="search" name="q" value="<?= e($f['q'] ?? '') ?>" placeholder="Código o descripción" aria-label="Buscar">
    <?php include __DIR__ . '/_rubros.php'; ?>
    <label class="field"><span>Proveedor (código)</span><input name="proveedor" value="<?= e($f['proveedor'] ?? '') ?>" inputmode="numeric" size="6"></label>
    <label class="field"><span>Moneda</span><select name="moneda"><option value="">Todas</option>
        <?php foreach (['$' => 'Pesos', 'u$s' => 'Dólares'] as $k => $x): ?><option value="<?= e($k) ?>" <?= ($f['moneda'] ?? '') === $k ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Mostrar</span><select name="ver">
        <?php foreach ($ver as $k => $x): ?><option value="<?= e($k) ?>" <?= ($f['ver'] ?? '') === $k ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Margen menor a (%)</span><input name="margen" value="<?= e($f['margen'] ?? '') ?>" inputmode="decimal" size="5"></label>
    <label class="field"><span>Los precios de la ficha son</span><select name="precios">
        <option value="neto" <?= !$r['con_iva'] ? 'selected' : '' ?>>Netos (sin IVA)</option>
        <option value="iva" <?= $r['con_iva'] ? 'selected' : '' ?>>Con IVA (precio final)</option></select></label>
    <label class="check"><input type="checkbox" name="bajas" value="1" <?= ($f['bajas'] ?? '') === '1' ? 'checked' : '' ?>> Incluir dados de baja</label>
    <button class="btn" type="submit">Ver</button>
</form>
<p class="small">Dólares a pesos: <?= $r['fecha_cotizacion'] !== null ? 'cotización <b>$ ' . e($m($r['cotizacion'])) . '</b> del ' . e(date('d/m/Y', (int) strtotime($r['fecha_cotizacion']))) : '<b>no hay cotización del dólar cargada</b> (se toma 1)' ?>.
    Margen real = (precio sin IVA − costo) ÷ costo.</p>
<?php if ($r['filas'] === []): ?>
    <p class="muted vacio">No hay artículos con esos filtros.</p>
<?php else: ?>
    <?= paginador($pagina, $paginas, $r['total'], $porPagina, $link, true) ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><?= th_orden('Código', 'codigo', $f, $u) ?><?= th_orden('Descripción', 'descripcion', $f, $u) ?><?= th_orden('Rubro', 'rubro', $f, $u) ?><?= th_orden('Proveedor', 'proveedor', $f, $u) ?>
            <th>Moneda</th><?= th_orden('Costo', 'costo', $f, $u, true) ?><?= th_orden('Costo en $', 'costo_pesos', $f, $u, true) ?><?= th_orden('Precio sin IVA', 'precio', $f, $u, true) ?>
            <?= th_orden('Margen real', 'margen', $f, $u, true) ?><?= th_orden('Margen ficha', 'ficha', $f, $u, true) ?><th class="num">Diferencia</th><?= th_orden('Actualizado', 'actualizacion', $f, $u, true) ?></tr></thead>
        <tbody>
        <?php foreach ($r['filas'] as $a): $real = $a['margen_real'] === null ? null : (float) $a['margen_real']; $bajo = $real !== null && $real < 0; ?>
            <tr><td data-label="Código" class="nw"><a href="<?= e(url('/articulos/ficha?codigo=' . rawurlencode((string) $a['codigo']))) ?>"><?= e($a['codigo']) ?></a></td>
                <td data-label="Descripción"><?= e($a['descripcion']) ?><?= $a['estado'] === 'BA' ? ' <span class="chip">baja</span>' : '' ?></td>
                <td data-label="Rubro"><?= e(trim((string) $a['rubro_desc'] . ' ' . ($a['subrubro'] !== null && $a['subrubro'] !== '' ? '/ ' . $a['subrubro'] : ''))) ?></td>
                <td data-label="Proveedor"><?= (int) $a['proveedor'] > 0 ? (int) $a['proveedor'] : '' ?></td>
                <td data-label="Moneda"><?= e($a['moneda']) ?></td>
                <td data-label="Costo" class="num"><?= (float) $a['costo'] > 0 ? e($m($a['costo'])) : '<span class="chip">sin costo</span>' ?></td>
                <td data-label="Costo en $" class="num"><?= e($m($a['costo_pesos'])) ?></td>
                <td data-label="Precio sin IVA" class="num"><?= e($m($a['precio'])) ?></td>
                <td data-label="Margen real" class="num"><b class="<?= $bajo ? 'texto-error' : '' ?>"><?= e($pct($real)) ?></b><?= $bajo ? ' <span class="chip">bajo el costo</span>' : '' ?></td>
                <td data-label="Margen ficha" class="num"><?= e($pct((float) $a['margen'])) ?></td>
                <td data-label="Diferencia" class="num"><?= $real === null ? '—' : e(number_format($real - (float) $a['margen'], 1, ',', '.') . ' pts') ?></td>
                <td data-label="Actualizado" class="nw"><?= $a['actualizacion'] ? e(date('d/m/Y', (int) strtotime($a['actualizacion']))) : '' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
