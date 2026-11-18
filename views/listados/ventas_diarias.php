<?php
use App\Modules\Comprobantes\Tipos;

/** @var array<string,string> $f */
/** @var array<int,array<string,mixed>> $filas */
/** @var int $total */
/** @var array<string,float> $totales */
/** @var int $pagina */
/** @var int $porPagina */
$u = url('/listados/ventas-diarias');
$link = static fn (int $p) => $u . '?' . http_build_query(['pagina' => $p] + $f);
$paginas = (int) ceil($total / max(1, $porPagina));
$m = static fn ($v) => number_format((float) $v, 2, ',', '.');
?>
<div class="page-head">
    <div><p class="eyebrow">Listados</p><h1>Ventas diarias</h1></div>
</div>
<p class="small muted">Presupuestos y facturas que se cobraron o fueron a cuenta corriente. La factura que sale de un presupuesto no se repite acá (va solo al subdiario). Las notas de crédito restan.</p>

<form method="get" action="<?= e($u) ?>" class="toolbar filtros">
    <label class="field"><span>Desde</span><input name="desde" value="<?= e($f['desde'] ?? '') ?>" placeholder="dd/mm/aaaa" inputmode="numeric"></label>
    <label class="field"><span>Hasta</span><input name="hasta" value="<?= e($f['hasta'] ?? '') ?>" placeholder="dd/mm/aaaa" inputmode="numeric"></label>
    <select name="tipo" aria-label="Tipo"><option value="">Presupuestos y facturas</option>
        <?php foreach (['PR' => 'Solo presupuestos', 'FC' => 'Solo facturas', 'CM' => 'Cambios', 'NX' => 'Notas de crédito X', 'NC' => 'Notas de crédito'] as $k => $t): ?><option value="<?= $k ?>" <?= ($f['tipo'] ?? '') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
    <select name="forma" aria-label="Forma de pago"><option value="">Contado y cuenta corriente</option>
        <option value="contado" <?= ($f['forma'] ?? '') === 'contado' ? 'selected' : '' ?>>Solo contado</option>
        <option value="cuenta" <?= ($f['forma'] ?? '') === 'cuenta' ? 'selected' : '' ?>>Con cuenta corriente (total o parte)</option></select>
    <input type="search" name="cliente" value="<?= e($f['cliente'] ?? '') ?>" placeholder="Cliente, código o CUIT" aria-label="Cliente">
    <button class="btn btn-ghost" type="submit">Ver</button>
</form>

<?php if ($filas === []): ?>
    <p class="muted vacio">No hay ventas para esos filtros.</p>
<?php else: ?>
    <section class="card imputador-resumen" aria-label="Totales">
        <div><span class="muted small">Total vendido</span><b><?= e('$ ' . $m($totales['total'])) ?></b></div>
        <div><span class="muted small">Contado</span><b><?= e('$ ' . $m($totales['contado'])) ?></b></div>
        <div><span class="muted small">Cuenta corriente</span><b><?= e('$ ' . $m($totales['cuenta'])) ?></b></div>
        <div><span class="muted small">Presupuestos</span><b><?= e('$ ' . $m($totales['presupuestos'])) ?></b></div>
        <div><span class="muted small">Facturas</span><b><?= e('$ ' . $m($totales['facturas'])) ?></b></div>
    </section>
    <?= paginador($pagina, $paginas, $total, $porPagina, $link, true) ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><?= th_orden('Fecha', 'fecha', $f, $u) ?><?= th_orden('Comprobante', 'numero', $f, $u) ?><?= th_orden('Cliente', 'cliente', $f, $u) ?><?= th_orden('Forma de pago', 'pago', $f, $u) ?><?= th_orden('Total', 'total', $f, $u, true) ?></tr></thead>
        <tbody>
        <?php foreach ($filas as $r): ?>
            <tr><td data-label="Fecha" class="nw"><?= e(date('d/m/Y', (int) strtotime($r['fecha']))) ?></td>
                <td data-label="Comprobante" class="nw"><a href="<?= e(url('/comprobantes/' . (int) $r['fe_clave'])) ?>"><?= e(Tipos::titulo($r['tipo'], $r['letra']) . ' ' . sprintf('%04d-%08d', $r['prefijo'], $r['numero'])) ?></a>
                    <?= (int) $r['es_prueba'] === 1 ? '<span class="chip">prueba</span>' : '' ?></td>
                <td data-label="Cliente"><?= e((string) $r['razon_social']) ?></td>
                <?php $cc = (float) $r['cuenta']; $neto = abs((float) $r['total']); $medio = (string) ($r['medio'] ?? ''); ?>
                <td data-label="Forma de pago"><?= e($cc < 0.004 ? 'Contado' . ($medio !== '' ? ' · ' . $medio : '') : ($cc >= $neto - 0.004 ? 'Cuenta corriente' : 'Mixto: ' . ($medio !== '' ? $medio . ' + ' : '') . 'cuenta corriente')) ?></td>
                <td data-label="Total" class="num"><b><?= e($m($r['total'])) ?></b></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th colspan="4">Total (<?= (int) $total ?> comprobantes)</th><th class="num"><?= e($m($totales['total'])) ?></th></tr></tfoot>
    </table></div>
<?php endif; ?>
