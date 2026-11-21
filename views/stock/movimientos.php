<?php
use App\Modules\Comprobantes\Tipos;
use App\Modules\Stock\MovimientosStock;
use App\Modules\Stock\StockService;

/**
 * Movimientos de stock (trazabilidad), en detalle o resumidos por artículo y depósito.
 * @var array<string,string> $f @var array $r @var bool $resumido @var array $depositos @var array $usuarios @var array $opciones @var bool $costos @var int $pagina @var int $porPagina
 */
$u = url('/stock/movimientos');
$link = static fn (int $p) => $u . '?' . http_build_query(['pagina' => $p] + $f);
$paginas = (int) ceil($r['total'] / max(1, $porPagina));
$n = static fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',');
$m = static fn ($v) => number_format((float) $v, 2, ',', '.');
?>
<div class="page-head">
    <div><p class="eyebrow">Stock</p><h1>Movimientos de stock</h1></div>
    <div class="acciones"><button type="button" class="btn btn-ghost" onclick="window.print()">Imprimir</button></div>
</div>
<p class="small muted no-imprimir">Cada entrada y salida de stock con su comprobante o motivo, quién la hizo y, si se vendió sin stock, quién lo autorizó. Elegí un artículo para ver el saldo renglón por renglón.</p>
<form method="get" action="<?= e($u) ?>" class="toolbar filtros no-imprimir">
    <label class="field"><span>Desde</span><input name="desde" value="<?= e($f['desde'] ?? '') ?>" placeholder="dd/mm/aaaa" inputmode="numeric"></label>
    <label class="field"><span>Hasta</span><input name="hasta" value="<?= e($f['hasta'] ?? '') ?>" placeholder="dd/mm/aaaa" inputmode="numeric"></label>
    <?php $urlArticulo = url('/stock/buscar-articulo'); $urlCliente = $resumido ? null : url('/stock/buscar-cliente'); include __DIR__ . '/../listados/_buscadores.php'; ?>
    <label class="field"><span>Depósito</span><select name="deposito"><option value="">Todos</option>
        <?php foreach ($depositos as $d): ?><option value="<?= (int) $d['id'] ?>" <?= ($f['deposito'] ?? '') === (string) $d['id'] ? 'selected' : '' ?>><?= e($d['nombre']) ?></option><?php endforeach; ?></select></label>
    <?php if (!$resumido): ?>
        <label class="field"><span>Origen</span><select name="origen"><option value="">Todos</option>
            <?php foreach (StockService::ORIGENES as $k => $x): ?><option value="<?= e($k) ?>" <?= ($f['origen'] ?? '') === $k ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Usuario</span><select name="usuario"><option value="">Todos</option>
            <?php foreach ($usuarios as $x): ?><option value="<?= (int) $x['id'] ?>" <?= ($f['usuario'] ?? '') === (string) $x['id'] ? 'selected' : '' ?>><?= e($x['usuario']) ?></option><?php endforeach; ?></select></label>
    <?php endif; ?>
    <?php include __DIR__ . '/../listados/_rubros.php'; ?>
    <label class="check"><input type="checkbox" name="resumido" value="1" <?= $resumido ? 'checked' : '' ?>> Resumido por artículo</label>
    <button class="btn" type="submit">Ver</button>
</form>
<p class="solo-imprimir small">Período: <?= e($f['desde'] . ' al ' . $f['hasta']) ?><?= ($f['articulo'] ?? '') !== '' ? ' · Artículo ' . e($f['articulo']) : '' ?></p>

<?php if ($resumido): $t = $r['totales']; ?>
    <?php if ($r['filas'] === []): ?>
        <p class="muted vacio">No hay movimientos ni stock para esos filtros.</p>
    <?php else: ?>
        <section class="card imputador-resumen" aria-label="Totales">
            <div><span class="muted small">Saldo inicial</span><b><?= e($n($t['inicial'])) ?></b></div>
            <div><span class="muted small">Entradas</span><b><?= e($n($t['entradas'])) ?></b></div>
            <div><span class="muted small">Salidas</span><b><?= e($n($t['salidas'])) ?></b></div>
            <div><span class="muted small">Saldo final</span><b><?= e($n($t['final'])) ?></b></div>
            <?php if ($costos): ?><div><span class="muted small">Valorizado</span><b><?= e('$ ' . $m($t['valorizado'])) ?></b></div><?php endif; ?>
        </section>
        <?= paginador($pagina, $paginas, $r['total'], $porPagina, $link, true) ?>
        <div class="table-wrap"><table class="table table-cards pie-solo-escritorio">
            <thead><tr><?= th_orden('Artículo', 'articulo', $f, $u) ?><?= th_orden('Descripción', 'descripcion', $f, $u) ?><?= th_orden('Depósito', 'deposito', $f, $u) ?>
                <?= th_orden('Saldo inicial', 'inicial', $f, $u, true) ?><?= th_orden('Entradas', 'entradas', $f, $u, true) ?><?= th_orden('Salidas', 'salidas', $f, $u, true) ?><?= th_orden('Saldo final', 'final', $f, $u, true) ?>
                <?php if ($costos): ?><?= th_orden('Valorizado (costo de hoy)', 'valorizado', $f, $u, true) ?><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($r['filas'] as $x): ?>
                <tr><td data-label="Artículo" class="nw"><a href="<?= e($u . '?' . http_build_query(['articulo' => $x['articulo'], 'deposito' => $x['deposito_id'], 'desde' => $f['desde'], 'hasta' => $f['hasta']])) ?>"><?= e($x['articulo']) ?></a></td>
                    <td data-label="Descripción"><?= e((string) $x['descripcion']) ?></td>
                    <td data-label="Depósito"><?= e((string) $x['deposito']) ?></td>
                    <td data-label="Saldo inicial" class="num"><?= e($n($x['inicial'])) ?></td>
                    <td data-label="Entradas" class="num"><?= e($n($x['entradas'])) ?></td>
                    <td data-label="Salidas" class="num"><?= e($n($x['salidas'])) ?></td>
                    <td data-label="Saldo final" class="num"><b class="<?= (float) $x['final'] < 0 ? 'texto-error' : '' ?>"><?= e($n($x['final'])) ?></b></td>
                    <?php if ($costos): ?><td data-label="Valorizado" class="num"><?= e($m($x['valorizado'])) ?></td><?php endif; ?></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><th colspan="3">Total (<?= (int) $r['total'] ?>)</th><th class="num"><?= e($n($t['inicial'])) ?></th><th class="num"><?= e($n($t['entradas'])) ?></th>
                <th class="num"><?= e($n($t['salidas'])) ?></th><th class="num"><?= e($n($t['final'])) ?></th><?php if ($costos): ?><th class="num"><?= e($m($t['valorizado'])) ?></th><?php endif; ?></tr></tfoot>
        </table></div>
    <?php endif; ?>
<?php else: $conSaldo = $r['inicial'] !== null; ?>
    <section class="card imputador-resumen" aria-label="Totales">
        <?php if ($conSaldo): ?><div><span class="muted small">Saldo inicial</span><b><?= e($n($r['inicial'])) ?></b></div><?php endif; ?>
        <div><span class="muted small">Entradas</span><b><?= e($n($r['entradas'])) ?></b></div>
        <div><span class="muted small">Salidas</span><b><?= e($n($r['salidas'])) ?></b></div>
        <?php if ($conSaldo): ?><div><span class="muted small">Saldo final</span><b><?= e($n($r['final'])) ?></b></div><?php endif; ?>
        <div><span class="muted small">Movimientos</span><b><?= (int) $r['total'] ?></b></div>
    </section>
    <?php if (!$conSaldo && ($f['articulo'] ?? '') !== ''): ?><p class="small muted">El saldo renglón por renglón se muestra sin filtrar por origen, usuario ni cliente.</p><?php endif; ?>
    <?php if ($r['filas'] === []): ?>
        <p class="muted vacio">No hay movimientos para esos filtros.</p>
    <?php else: ?>
        <?= paginador($pagina, $paginas, $r['total'], $porPagina, $link, true) ?>
        <div class="table-wrap"><table class="table table-cards">
            <thead><tr><?= th_orden('Fecha y hora', 'fecha', $f, $u, true) ?><?= th_orden('Artículo', 'articulo', $f, $u) ?><th>Descripción</th><?= th_orden('Depósito', 'deposito', $f, $u) ?>
                <?= th_orden('Origen', 'origen', $f, $u) ?><th>Comprobante o motivo</th><th>Cliente</th><th class="num">Entrada</th><th class="num">Salida</th>
                <?php if ($conSaldo): ?><th class="num">Saldo</th><?php endif; ?><?= th_orden('Usuario', 'usuario', $f, $u) ?><th>Sin stock</th></tr></thead>
            <tbody>
            <?php foreach ($r['filas'] as $x): $q = (float) $x['cantidad']; ?>
                <tr><td data-label="Fecha y hora" class="nw"><?= e(date('d/m/Y H:i', (int) strtotime($x['fecha']))) ?></td>
                    <td data-label="Artículo" class="nw"><a href="<?= e(url('/stock/articulo/' . rawurlencode((string) $x['articulo']))) ?>"><?= e($x['articulo']) ?></a></td>
                    <td data-label="Descripción"><?= e((string) $x['descripcion']) ?></td>
                    <td data-label="Depósito"><?= e((string) $x['deposito']) ?></td>
                    <td data-label="Origen"><?= e(StockService::ORIGENES[$x['origen']] ?? $x['origen']) ?></td>
                    <td data-label="Comprobante o motivo"><?php if ($x['subdiario_id'] !== null && $x['tipo'] !== null): ?><a href="<?= e(url('/comprobantes/' . (int) $x['subdiario_id'])) ?>"><?= e(Tipos::titulo($x['tipo'], $x['letra']) . ' ' . sprintf('%04d-%08d', $x['prefijo'], $x['numero'])) ?></a><?php endif; ?>
                        <?= $x['otro_deposito'] !== null ? e(($q < 0 ? 'hacia ' : 'desde ') . $x['otro_deposito']) : '' ?>
                        <?= $x['motivo'] !== null && $x['motivo'] !== '' ? '<span class="muted">' . e($x['motivo']) . '</span>' : '' ?></td>
                    <td data-label="Cliente"><?= e((string) $x['cliente']) ?></td>
                    <td data-label="Entrada" class="num"><?= $q > 0 ? e($n($q)) : '' ?></td>
                    <td data-label="Salida" class="num"><?= $q < 0 ? e($n(-$q)) : '' ?></td>
                    <?php if ($conSaldo): ?><td data-label="Saldo" class="num"><b class="<?= (float) $x['saldo'] < 0 ? 'texto-error' : '' ?>"><?= e($n($x['saldo'])) ?></b></td><?php endif; ?>
                    <td data-label="Usuario"><?= e((string) $x['usuario']) ?></td>
                    <td data-label="Sin stock"><?= $x['sin_stock_autorizo'] !== null ? '<span class="chip">sin stock</span> ' . e((string) $x['sin_stock_autorizo']) : '' ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
<?php endif; ?>
<script src="<?= e(asset('assets/ventana-lista.js')) ?>" defer></script>
<script src="<?= e(asset('assets/buscador.js')) ?>" defer></script>
<script src="<?= e(asset('assets/cliente-picker.js')) ?>" defer></script>
