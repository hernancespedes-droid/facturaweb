<?php
/**
 * Actualizar precios con los márgenes (permiso articulos.precios). Vista previa de cada lista (actual → nuevo) y se graba solo lo tildado.
 * @var array<string,mixed> $f @var array $r @var array $cfg @var ?array $pend @var array<string,string> $rubros
 */
use App\Modules\Articulos\Precios;

$m = static fn ($v) => number_format((float) $v, 2, ',', '.');
$ver = ['automaticos' => 'Solo los de precio automático', 'cambian' => 'Todos los que cambiarían', 'todos' => 'Todos los activos'];
$red = \App\Modules\Tablas\Definiciones::REDONDEOS[(string) $cfg['redondeo']] ?? '';
$general = array_filter($cfg['margenes'], static fn ($x) => $x !== null);
?>
<p><a href="<?= e(url('/articulos')) ?>">← Artículos</a></p>
<div class="page-head"><div><p class="eyebrow">Artículos</p><h1>Precios por margen</h1></div>
    <div class="actions"><a class="btn btn-ghost" href="<?= e(url('/articulos/actualizacion')) ?>">Actualización por filtro (inflación)</a></div></div>
<?php if ($pend !== null): ?>
    <div class="alerta alerta-info" role="status"><b><?= e($pend['motivo']) ?></b> ¿Actualizo también los precios con los márgenes? Revisá cómo quedan, destildá los que no querés tocar y confirmá.</div>
<?php else: ?>
    <p class="small muted">Recalcula los precios de cada lista con el costo y el margen (del artículo, de su rubro o el general de Parámetros). Solo se graba lo que tildás.</p>
    <form method="get" action="<?= e(url('/articulos/precios')) ?>" class="toolbar filtros">
        <input type="search" name="q" value="<?= e($f['q'] ?? '') ?>" placeholder="Código o descripción" aria-label="Buscar">
        <label class="field"><span>Rubro</span><select name="rubro"><option value="">Todos</option>
            <?php foreach ($rubros as $k => $t): ?><option value="<?= e((string) $k) ?>" <?= ($f['rubro'] ?? '') === (string) $k ? 'selected' : '' ?>><?= e($k . ' - ' . $t) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Proveedor (código)</span><input name="proveedor" value="<?= e($f['proveedor'] ?? '') ?>" inputmode="numeric" size="6"></label>
        <label class="field"><span>Mostrar</span><select name="ver">
            <?php foreach ($ver as $k => $t): ?><option value="<?= e($k) ?>" <?= $f['ver'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
        <button class="btn" type="submit">Ver</button>
    </form>
<?php endif; ?>
<p class="small">Márgenes generales: <?= $general === [] ? '<b>no hay</b> (se cargan en Tablas → Parámetros → Precios)' : e(implode(' · ', array_map(static fn ($k) => Precios::LISTAS[$k][1] . ' ' . number_format((float) $general[$k], 2, ',', '.') . ' %', array_keys($general)))) ?>.
    <?= $cfg['suma_iva'] ? 'Los precios incluyen IVA.' : 'Los precios van sin IVA.' ?> <?= e($red) ?>.</p>
<?php if ($r['limite']): ?><p class="alerta alerta-error">Hay más de 1.000 artículos: se muestran los primeros. Filtrá por rubro o proveedor.</p><?php endif; ?>
<?php if ($r['filas'] === []): ?>
    <p class="muted vacio">No hay artículos con esos filtros<?= $f['ver'] === 'automaticos' && $pend === null ? ' (¿ningún artículo tiene «Precio automático»? Elegí «Todos los que cambiarían»)' : '' ?>.</p>
    <?php if ($pend !== null): ?><p><a class="btn" href="<?= e((string) $pend['volver']) ?>">Seguir</a></p><?php endif; ?>
<?php else: ?>
<form method="post" action="<?= e(url('/articulos/precios')) ?>" data-precios-masivo>
    <?= csrf_field() ?>
    <?php if ($pend !== null): ?><input type="hidden" name="pendientes" value="1"><?php endif; ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th><label class="check"><input type="checkbox" data-tildar-todos checked aria-label="Tildar todos"> Actualizar</label></th><th>Código</th><th>Descripción</th><th>Rubro</th><th class="num">Costo</th>
            <?php foreach (Precios::LISTAS as [, $nombre]): ?><th class="num"><?= e($nombre) ?></th><?php endforeach; ?><th>Automático</th></tr></thead>
        <tbody>
        <?php foreach ($r['filas'] as $x): $a = $x['a']; $c = $x['calc']; ?>
            <tr><td data-label="Actualizar"><input type="checkbox" name="codigos[]" value="<?= e($a['codigo']) ?>" <?= $c['cambia'] ? 'checked' : 'disabled' ?> aria-label="Actualizar <?= e($a['codigo']) ?>"></td>
                <td data-label="Código" class="nw"><a href="<?= e(url('/articulos/ficha') . '?' . http_build_query(['codigo' => $a['codigo']])) ?>"><?= e($a['codigo']) ?></a></td>
                <td data-label="Descripción"><?= e($a['descripcion']) ?><?= $a['moneda'] === 'u$s' ? ' <span class="chip">u$s</span>' : '' ?></td>
                <td data-label="Rubro"><?= e((string) ($a['rubro_nombre'] ?? '')) ?></td>
                <td data-label="Costo" class="num"><?= (float) $a['costo'] > 0 ? e($m($a['costo'])) : '<span class="chip">sin costo</span>' ?></td>
                <?php foreach ($c['listas'] as $l): ?>
                    <td data-label="<?= e($l['nombre']) ?>" class="num"><?php if ($l['nuevo'] === null): ?><?= e($m($l['actual'])) ?><?php if ($l['margen'] === null): ?> <span class="small muted">sin margen</span><?php endif; ?>
                        <?php elseif (abs($l['nuevo'] - $l['actual']) < 0.005): ?><?= e($m($l['actual'])) ?>
                        <?php else: ?><s class="muted"><?= e($m($l['actual'])) ?></s> <b><?= e($m($l['nuevo'])) ?></b><?php endif; ?>
                        <?php if ($l['margen'] !== null): ?><br><span class="small muted"><?= e(number_format((float) $l['margen'], 2, ',', '.') . ' % ' . $l['origen']) ?></span><?php endif; ?></td>
                <?php endforeach; ?>
                <td data-label="Automático"><?= (int) $a['precio_automatico'] === 1 ? 'Sí' : 'No' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div class="actions">
        <label class="check"><input type="checkbox" name="automatico" value="1"> Dejar los tildados con «Precio automático» (la ficha los recalcula sola al cambiar el costo)</label>
        <button class="btn" type="submit" name="accion" value="si">Actualizar los precios tildados</button>
        <?php if ($pend !== null): ?><button class="btn btn-ghost" type="submit" name="accion" value="no" formnovalidate>No, dejar los precios como están</button><?php endif; ?>
    </div>
</form>
<script>
(function () {
  var t = document.querySelector('[data-tildar-todos]');
  if (!t) return;
  t.addEventListener('change', function () {
    document.querySelectorAll('[data-precios-masivo] input[name="codigos[]"]:not(:disabled)').forEach(function (c) { c.checked = t.checked; });
  });
})();
</script>
<?php endif; ?>
