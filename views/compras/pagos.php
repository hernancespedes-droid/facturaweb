<?php
use App\Modules\Compras\OrdenesPagoController as OC;
use App\Modules\Compras\OrdenesPagoService as O;

/** Órdenes de pago emitidas. @var array $f @var array $r @var int $pagina @var int $porPagina @var ?array $prov @var bool $puedePagar */
$u = url('/compras/pagos');
$m = static fn ($v) => number_format((float) $v, 2, ',', '.');
$link = static fn (int $p) => $u . '?' . http_build_query(['pagina' => $p] + $f);
$paginas = (int) ceil($r['total'] / max(1, $porPagina));
?>
<div class="page-head">
    <div><p class="eyebrow">Compras</p><h1>Órdenes de pago</h1></div>
    <div class="acciones">
        <a class="btn btn-ghost" href="<?= e($u . '?' . http_build_query(['excel' => 1] + $f)) ?>">Excel</a>
        <?php if ($puedePagar): ?><a class="btn" href="<?= e(url('/compras/pagos/nueva')) ?>">Nueva orden de pago</a><?php endif; ?>
    </div>
</div>
<form method="get" action="<?= e($u) ?>" class="toolbar filtros">
    <label class="field"><span>Desde</span><input name="desde" value="<?= e($f['desde'] ?? '') ?>" placeholder="dd/mm/aaaa" inputmode="numeric"></label>
    <label class="field"><span>Hasta</span><input name="hasta" value="<?= e($f['hasta'] ?? '') ?>" placeholder="dd/mm/aaaa" inputmode="numeric"></label>
    <?php $nombre = 'proveedor'; $titulo = 'Proveedor'; include __DIR__ . '/_proveedor.php'; ?>
    <label class="field"><span>Medio</span><select name="medio"><option value="">Todos</option>
        <?php foreach (O::MEDIOS as $k => $t): ?><option value="<?= e($k) ?>" <?= ($f['medio'] ?? '') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Estado</span><select name="estado">
        <?php foreach (['' => 'Vigentes', 'A' => 'Anuladas', 'todas' => 'Todas'] as $k => $t): ?><option value="<?= e($k) ?>" <?= ($f['estado'] ?? '') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
    <button class="btn" type="submit">Ver</button>
</form>

<section class="card resumen-fila" aria-label="Totales">
    <div><span class="muted small">Órdenes</span><b><?= (int) $r['total'] ?></b></div>
    <div><span class="muted small">Pagado</span><b>$ <?= e($m($r['totales']['pagado'])) ?></b></div>
    <div><span class="muted small">Retenciones</span><b>$ <?= e($m($r['totales']['retenciones'])) ?></b></div>
    <div><span class="muted small">Anticipos</span><b>$ <?= e($m($r['totales']['anticipo'])) ?></b></div>
</section>

<?php if ($r['filas'] === []): ?>
    <p class="muted vacio">No hay órdenes de pago para esos filtros.</p>
<?php else: ?>
    <?= paginador($pagina, $paginas, $r['total'], $porPagina, $link, true) ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><?= th_orden('Número', 'numero', $f, $u) ?><?= th_orden('Fecha', 'fecha', $f, $u, true) ?><?= th_orden('Proveedor', 'proveedor', $f, $u) ?><th>Medios</th>
            <?= th_orden('Retenciones', 'retenciones', $f, $u, true) ?><?= th_orden('Pagado', 'pagado', $f, $u, true) ?><?= th_orden('Anticipo', 'anticipo', $f, $u, true) ?></tr></thead>
        <tbody>
        <?php foreach ($r['filas'] as $x): $anulada = $x['estado'] === 'A'; ?>
            <tr<?= $anulada ? ' class="muted"' : '' ?>>
                <td data-label="Número"><a href="<?= e(url('/compras/pagos/ver/' . (int) $x['id'])) ?>"><?= e(O::numeroVisible((int) $x['numero'])) ?></a><?= $anulada ? ' <span class="pill pill-mal">anulada</span>' : '' ?></td>
                <td data-label="Fecha" class="nw"><?= e(fecha($x['fecha'])) ?></td>
                <td data-label="Proveedor"><?= e($x['razon_social']) ?></td>
                <td data-label="Medios" class="small"><?= e(OC::medios((string) $x['medios'])) ?></td>
                <td data-label="Retenciones" class="num"><?= (float) $x['retenciones'] > 0.005 ? e($m($x['retenciones'])) : '' ?></td>
                <td data-label="Pagado" class="num"><b><?= e($m($x['pagado'])) ?></b></td>
                <td data-label="Anticipo" class="num"><?= (float) $x['anticipo'] > 0.005 ? e($m($x['anticipo'])) : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?= paginador($pagina, $paginas, $r['total'], $porPagina, $link) ?>
<?php endif; ?>
<script src="<?= e(asset('assets/ventana-lista.js')) ?>" defer></script>
<script src="<?= e(asset('assets/cliente-picker.js')) ?>" defer></script>
