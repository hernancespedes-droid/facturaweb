<?php
use App\Modules\Comprobantes\Tipos;
use App\Modules\Listados\Estadistica;

/**
 * Estadística de ventas y Rentabilidad de ventas (la misma pantalla; la rentabilidad pone el margen adelante).
 * @var array<string,string> $f @var bool $detalle @var bool $rentabilidad @var bool $costos @var array $opciones @var array $r @var ?array $d @var int $pagina @var int $porPagina
 */
$u = url($rentabilidad ? '/listados/rentabilidad' : '/listados/estadistica');
$m = static fn ($v) => number_format((float) $v, 2, ',', '.');
$n = static fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',');
$pct = static fn ($v) => $v === null ? '—' : number_format((float) $v, 1, ',', '.') . ' %';
$t = $r['totales'];
[$tituloGrupo] = Estadistica::AGRUPAR[$r['agrupar']];
$tipos = ['FC' => 'Facturas', 'ND' => 'Notas de débito', 'NC' => 'Notas de crédito', 'PR' => 'Presupuestos', 'NX' => 'Notas de crédito X', 'CM' => 'Cambios'];
?>
<div class="page-head">
    <div><p class="eyebrow">Listados</p><h1><?= $rentabilidad ? 'Rentabilidad de ventas' : 'Estadística de ventas' ?></h1></div>
    <div class="acciones"><button type="button" class="btn btn-ghost" onclick="window.print()">Imprimir</button></div>
</div>
<p class="small muted no-imprimir">Lo vendido en el período (facturas, notas, presupuestos y cambios; las notas de crédito restan). La factura que sale de un presupuesto no se cuenta dos veces.
    <?= $costos ? 'El margen es la venta neta menos el costo grabado al vender; si una venta no tiene costo grabado, se usa el de hoy y se marca «estimado».' : '' ?></p>

<form method="get" action="<?= e($u) ?>" class="toolbar filtros no-imprimir">
    <label class="field"><span>Desde</span><input name="desde" value="<?= e($f['desde'] ?? '') ?>" placeholder="dd/mm/aaaa" data-fecha inputmode="numeric"></label>
    <label class="field"><span>Hasta</span><input name="hasta" value="<?= e($f['hasta'] ?? '') ?>" placeholder="dd/mm/aaaa" data-fecha inputmode="numeric"></label>
    <label class="field"><span>Agrupar por</span><select name="agrupar">
        <?php foreach (Estadistica::AGRUPAR as $k => $a): ?><option value="<?= e($k) ?>" <?= $r['agrupar'] === $k ? 'selected' : '' ?>><?= e($a[0]) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Comprobantes</span><select name="tipo"><option value="">Todos</option>
        <?php foreach ($tipos as $k => $x): ?><option value="<?= e($k) ?>" <?= ($f['tipo'] ?? '') === $k ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></label>
    <?php $urlArticulo = url('/listados/buscar-articulo'); $urlCliente = url('/listados/buscar-cliente'); include __DIR__ . '/_buscadores.php'; ?>
    <?php include __DIR__ . '/_rubros.php'; ?>
    <label class="field"><span>Proveedor (código)</span><input name="proveedor" value="<?= e($f['proveedor'] ?? '') ?>" inputmode="numeric" size="6"></label>
    <label class="field"><span>Vendedor</span><select name="vendedor"><option value="">Todos</option>
        <?php foreach ($opciones['vendedores'] as $o): ?><option value="<?= e($o['codigo']) ?>" <?= ($f['vendedor'] ?? '') === (string) $o['codigo'] ? 'selected' : '' ?>><?= e($o['descripcion']) ?></option><?php endforeach; ?></select></label>
    <?php if (count($opciones['sucursales']) > 1): ?>
        <label class="field"><span>Sucursal</span><select name="sucursal"><option value="">Todas</option>
            <?php foreach ($opciones['sucursales'] as $o): ?><option value="<?= (int) $o['codigo'] ?>" <?= ($f['sucursal'] ?? '') === (string) $o['codigo'] ? 'selected' : '' ?>><?= e($o['descripcion']) ?></option><?php endforeach; ?></select></label>
    <?php endif; ?>
    <label class="field"><span>Ranking: los primeros</span><input name="primeros" value="<?= e($f['primeros'] ?? '') ?>" inputmode="numeric" size="4" placeholder="todos"></label>
    <label class="field"><span>Ordenar por</span><select name="por">
        <option value="">Venta neta</option>
        <option value="cantidad" <?= ($f['por'] ?? '') === 'cantidad' ? 'selected' : '' ?>>Cantidad</option>
        <?php if ($costos): ?><option value="margen" <?= ($f['por'] ?? '') === 'margen' ? 'selected' : '' ?>>Margen</option><?php endif; ?>
    </select></label>
    <label class="check"><input type="checkbox" name="fiscales" value="1" <?= ($f['fiscales'] ?? '') === '1' ? 'checked' : '' ?>> Solo fiscales (sin presupuestos ni cambios)</label>
    <label class="check"><input type="checkbox" name="detalle" value="1" <?= $detalle ? 'checked' : '' ?>> Ver también el detalle (cada renglón vendido)</label>
    <button class="btn" type="submit">Ver</button>
</form>
<p class="solo-imprimir small">Período: <?= e(($f['desde'] ?? '') . ' al ' . ($f['hasta'] ?? '')) ?> · Agrupado por <?= e(mb_strtolower($tituloGrupo)) ?></p>

<?php if ($r['filas'] === []): ?>
    <p class="muted vacio">No hay ventas para esos filtros.</p>
<?php else: ?>
    <section class="card imputador-resumen" aria-label="Totales">
        <div><span class="muted small">Venta neta</span><b><?= e('$ ' . $m($t['neto'])) ?></b></div>
        <div><span class="muted small">Con IVA</span><b><?= e('$ ' . $m($t['total'])) ?></b></div>
        <div><span class="muted small">Unidades</span><b><?= e($n($t['unidades'])) ?></b></div>
        <div><span class="muted small">Comprobantes</span><b><?= (int) $t['comprobantes'] ?></b></div>
        <?php if ($costos): ?>
            <div><span class="muted small">Costo</span><b><?= e('$ ' . $m($t['costo'])) ?></b></div>
            <div><span class="muted small">Margen</span><b><?= e('$ ' . $m($t['margen']) . ' (' . $pct($t['margen_pct']) . ')') ?></b></div>
        <?php endif; ?>
    </section>
    <?php if ($costos && ($t['estimados'] > 0 || $t['sin_costo'] > 0)): ?>
        <p class="small muted"><?php if ($t['estimados'] > 0): ?><b><?= (int) $t['estimados'] ?></b> renglones sin costo grabado se calcularon con el costo de hoy (estimado). <?php endif; ?>
            <?php if ($t['sin_costo'] > 0): ?><b><?= (int) $t['sin_costo'] ?></b> renglones no tienen costo (sin artículo o con costo 0 en la ficha): su margen es toda la venta.<?php endif; ?></p>
    <?php endif; ?>
    <?php if ((int) ($f['primeros'] ?? 0) > 0): ?><p class="small">Ranking: los <b><?= (int) $f['primeros'] ?></b> primeros. Los totales de arriba son de todo el período.</p><?php endif; ?>
    <div class="table-wrap"><table class="table table-cards pie-solo-escritorio">
        <thead><tr><th><?= e($tituloGrupo) ?></th><th>Descripción</th>
            <?php if (!$rentabilidad): ?><th class="num">Unidades</th><th class="num">Venta neta</th><th class="num">Con IVA</th><th class="num">% venta</th><th class="num">Comprobantes</th><th class="num">Precio promedio</th><?php endif; ?>
            <?php if ($rentabilidad): ?><th class="num">Unidades</th><th class="num">Venta neta</th><?php endif; ?>
            <?php if ($costos): ?><th class="num">Costo</th><th class="num">Margen</th><th class="num">Margen %</th><?php endif; ?>
            <?php if ($rentabilidad): ?><th class="num">% del margen</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($r['filas'] as $x): ?>
            <tr><td data-label="<?= e($tituloGrupo) ?>" class="nw"><?= e($r['agrupar'] === 'mes' ? (string) $x['nombre'] : (string) $x['grupo']) ?></td>
                <td data-label="Descripción"><?= e($r['agrupar'] === 'mes' ? '' : (string) $x['nombre']) ?></td>
                <td data-label="Unidades" class="num"><?= e($n($x['unidades'])) ?></td>
                <td data-label="Venta neta" class="num"><b><?= e($m($x['neto'])) ?></b></td>
                <?php if (!$rentabilidad): ?>
                    <td data-label="Con IVA" class="num"><?= e($m($x['total'])) ?></td>
                    <td data-label="% venta" class="num"><?= e($pct($x['pct_venta'])) ?></td>
                    <td data-label="Comprobantes" class="num"><?= (int) $x['comprobantes'] ?></td>
                    <td data-label="Precio promedio" class="num"><?= $x['precio_promedio'] === null ? '—' : e($m($x['precio_promedio'])) ?></td>
                <?php endif; ?>
                <?php if ($costos): ?>
                    <td data-label="Costo" class="num"><?= e($m($x['costo'])) ?><?= (int) $x['estimados'] > 0 ? ' <span class="chip" title="Hay renglones sin costo grabado: se usó el costo de hoy">estimado</span>' : '' ?></td>
                    <td data-label="Margen" class="num"><b class="<?= $x['margen'] < 0 ? 'texto-error' : '' ?>"><?= e($m($x['margen'])) ?></b></td>
                    <td data-label="Margen %" class="num"><?= e($pct($x['margen_pct'])) ?></td>
                <?php endif; ?>
                <?php if ($rentabilidad): ?><td data-label="% del margen" class="num"><?= e($pct($x['pct_margen'])) ?></td><?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th colspan="2">Total del período</th><th class="num"><?= e($n($t['unidades'])) ?></th><th class="num"><?= e($m($t['neto'])) ?></th>
            <?php if (!$rentabilidad): ?><th class="num"><?= e($m($t['total'])) ?></th><th class="num">100 %</th><th class="num"><?= (int) $t['comprobantes'] ?></th><th></th><?php endif; ?>
            <?php if ($costos): ?><th class="num"><?= e($m($t['costo'])) ?></th><th class="num"><?= e($m($t['margen'])) ?></th><th class="num"><?= e($pct($t['margen_pct'])) ?></th><?php endif; ?>
            <?php if ($rentabilidad): ?><th class="num">100 %</th><?php endif; ?>
        </tr></tfoot>
    </table></div>

    <?php if ($detalle && isset($d)): ?>
        <?php $link = static fn (int $p) => $u . '?' . http_build_query(['pagina' => $p] + $f); $paginas = (int) ceil($d['total'] / max(1, $porPagina)); ?>
        <h2 id="detalle">Detalle: cada renglón vendido</h2>
        <?= paginador($pagina, $paginas, $d['total'], $porPagina, $link, true) ?>
        <div class="table-wrap"><table class="table table-cards">
            <thead><tr><?= th_orden('Fecha', 'fecha', $f, $u, true, 'detalle') ?><?= th_orden('Comprobante', 'comprobante', $f, $u, false, 'detalle') ?><?= th_orden('Cliente', 'cliente', $f, $u, false, 'detalle') ?>
                <?= th_orden('Artículo', 'articulo', $f, $u, false, 'detalle') ?><th>Descripción</th><?= th_orden('Unidades', 'unidades', $f, $u, true, 'detalle') ?><th class="num">Precio</th>
                <?= th_orden('Neto', 'neto', $f, $u, true, 'detalle') ?><?= th_orden('Con IVA', 'total', $f, $u, true, 'detalle') ?>
                <?php if ($costos): ?><?= th_orden('Costo', 'costo', $f, $u, true, 'detalle') ?><?= th_orden('Margen', 'margen', $f, $u, true, 'detalle') ?><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($d['filas'] as $x): ?>
                <tr><td data-label="Fecha" class="nw"><?= e(date('d/m/Y', (int) strtotime($x['fecha']))) ?></td>
                    <td data-label="Comprobante" class="nw"><a href="<?= e(url('/comprobantes/' . (int) $x['fe_clave'])) ?>"><?= e(Tipos::titulo($x['tipo'], $x['letra']) . ' ' . sprintf('%04d-%08d', $x['prefijo'], $x['numero'])) ?></a></td>
                    <td data-label="Cliente"><?= e((string) $x['razon_social']) ?></td>
                    <td data-label="Artículo" class="nw"><?= e((string) $x['articulo']) ?></td>
                    <td data-label="Descripción"><?= e((string) $x['descripcion']) ?></td>
                    <td data-label="Unidades" class="num"><?= e($n($x['unidades'])) ?></td>
                    <td data-label="Precio" class="num"><?= e($m($x['precio'])) ?></td>
                    <td data-label="Neto" class="num"><?= e($m($x['neto'])) ?></td>
                    <td data-label="Con IVA" class="num"><?= e($m($x['total'])) ?></td>
                    <?php if ($costos): ?>
                        <td data-label="Costo" class="num"><?= e($m($x['costo'])) ?><?= (int) $x['estimado'] === 1 ? ' <span class="chip">estimado</span>' : '' ?></td>
                        <td data-label="Margen" class="num"><?= e($m($x['margen'])) ?></td>
                    <?php endif; ?></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
<?php endif; ?>
<script src="<?= e(asset('assets/ventana-lista.js')) ?>" defer></script>
<script src="<?= e(asset('assets/buscador.js')) ?>" defer></script>
<script src="<?= e(asset('assets/cliente-picker.js')) ?>" defer></script>
