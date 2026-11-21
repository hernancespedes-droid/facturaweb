<?php
use App\Modules\Compras\ComprasService as S;

/** Compras cargadas. @var array $f @var array $r @var int $pagina @var int $porPagina @var ?array $prov @var bool $puedeCargar @var array $centros @var array $conceptos */
$u = url('/compras/lista');
$m = static fn ($v) => number_format((float) $v, 2, ',', '.');
$link = static fn (int $p) => $u . '?' . http_build_query(['pagina' => $p] + $f);
$paginas = (int) ceil($r['total'] / max(1, $porPagina));
$estados = ['' => 'Todas', 'V' => 'Vigentes', 'A' => 'Anuladas', 'impagas' => 'A pagar (con saldo)', 'revisar' => 'Para revisar'];
?>
<div class="page-head">
    <div><p class="eyebrow">Compras</p><h1>Compras cargadas</h1></div>
    <div class="acciones">
        <a class="btn btn-ghost" href="<?= e($u . '?' . http_build_query(['excel' => 1] + $f)) ?>">Excel</a>
        <?php if ($puedeCargar): ?><a class="btn" href="<?= e(url('/compras/nueva')) ?>">Nueva compra o gasto</a><?php endif; ?>
    </div>
</div>
<form method="get" action="<?= e($u) ?>" class="toolbar filtros">
    <label class="field"><span>Desde</span><input name="desde" value="<?= e($f['desde'] ?? '') ?>" placeholder="dd/mm/aaaa" inputmode="numeric"></label>
    <label class="field"><span>Hasta</span><input name="hasta" value="<?= e($f['hasta'] ?? '') ?>" placeholder="dd/mm/aaaa" inputmode="numeric"></label>
    <?php $nombre = 'proveedor'; $titulo = 'Proveedor'; include __DIR__ . '/_proveedor.php'; ?>
    <?php include __DIR__ . '/_filtros_centro.php'; ?>
    <label class="field"><span>Tipo</span><select name="tipo"><option value="">Todos</option>
        <?php foreach (S::TIPOS as $k => $t): ?><option value="<?= e($k) ?>" <?= ($f['tipo'] ?? '') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Estado</span><select name="estado">
        <?php foreach ($estados as $k => $t): ?><option value="<?= e($k) ?>" <?= ($f['estado'] ?? '') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Buscar</span><input type="search" name="q" value="<?= e($f['q'] ?? '') ?>" placeholder="Número u observación"></label>
    <button class="btn" type="submit">Ver</button>
</form>

<section class="card resumen-fila" aria-label="Totales">
    <div><span class="muted small">Comprobantes</span><b><?= (int) $r['total'] ?></b></div>
    <div><span class="muted small">Total (vigentes)</span><b><?= e('$ ' . $m($r['totales']['total'])) ?></b></div>
    <div><span class="muted small">Costo para los centros</span><b><?= e('$ ' . $m($r['totales']['costo'])) ?></b></div>
    <div><span class="muted small">Saldo a pagar</span><b><?= e('$ ' . $m($r['totales']['saldo'])) ?></b></div>
</section>

<?php if ($r['filas'] === []): ?>
    <p class="muted vacio">No hay compras para esos filtros.</p>
<?php else: ?>
    <?= paginador($pagina, $paginas, $r['total'], $porPagina, $link, true) ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><?= th_orden('Fecha', 'fecha', $f, $u, true) ?><?= th_orden('Comprobante', 'comprobante', $f, $u) ?><?= th_orden('Proveedor', 'proveedor', $f, $u) ?>
            <th>Centros</th><?= th_orden('Total', 'total', $f, $u, true) ?><?= th_orden('Costo', 'costo', $f, $u, true) ?><th>Pago</th><?= th_orden('Vence', 'vence', $f, $u) ?><?= th_orden('Saldo', 'saldo', $f, $u, true) ?></tr></thead>
        <tbody>
        <?php foreach ($r['filas'] as $x): $sg = S::signo($x['tipo']); $anulada = $x['estado'] === 'A'; ?>
            <tr<?= $anulada ? ' class="muted"' : '' ?>>
                <td data-label="Fecha" class="nw"><?= e(fecha($x['fecha'])) ?></td>
                <td data-label="Comprobante"><a href="<?= e(url('/compras/ver/' . (int) $x['id'])) ?>"><?= e(S::titulo($x)) ?></a>
                    <?= $anulada ? ' <span class="pill pill-mal">anulada</span>' : '' ?><?= (int) $x['a_revisar'] === 1 && !$anulada ? ' <span class="pill pill-warn">revisar</span>' : '' ?><?= (int) $x['adjuntos'] > 0 ? ' <span title="Tiene adjuntos">📎</span>' : '' ?></td>
                <td data-label="Proveedor"><?= e($x['razon_social']) ?></td>
                <td data-label="Centros" class="small"><?= e((string) $x['centros']) ?></td>
                <td data-label="Total" class="num"><?= e($m($x['total'] * $sg)) ?></td>
                <td data-label="Costo" class="num"><?= e($m($x['costo'] * $sg)) ?></td>
                <td data-label="Pago" class="small"><?= e(['E' => 'Efectivo', 'O' => 'Otro medio', 'C' => 'Cta. cte.'][$x['forma']] ?? '') ?></td>
                <td data-label="Vence" class="nw"><?= $x['vence'] ? e(fecha($x['vence'])) : '' ?></td>
                <td data-label="Saldo" class="num"><?= (float) $x['saldo'] > 0.005 ? '<b>' . e($m($x['saldo'] * $sg)) . '</b>' : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?= paginador($pagina, $paginas, $r['total'], $porPagina, $link) ?>
<?php endif; ?>
<script src="<?= e(asset('assets/ventana-lista.js')) ?>" defer></script>
<script src="<?= e(asset('assets/cliente-picker.js')) ?>" defer></script>
