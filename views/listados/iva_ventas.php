<?php
use App\Modules\Comprobantes\Tipos;

/** @var array<string,string> $f */
/** @var array<int,array<string,mixed>> $filas */
/** @var int $total */
/** @var array<string,float> $totales */
/** @var int $pagina */
/** @var int $porPagina */
/** @var int[] $pvs */
/** @var float[] $alicuotas */
$link = static fn (int $p) => url('/listados/iva-ventas') . '?' . http_build_query(['pagina' => $p] + $f);
$paginas = (int) ceil($total / max(1, $porPagina));
$m = static fn ($v) => number_format((float) $v, 2, ",", ".");
$nombreAlic = static fn (float $a) => rtrim(rtrim(number_format($a, 2, ',', ''), '0'), ',');
?>
<div class="page-head">
    <div><p class="eyebrow">Listados</p><h1>Subdiario de IVA ventas</h1></div>
    <a class="btn btn-ghost" href="<?= e(url('/listados/iva-ventas') . '?' . http_build_query(['excel' => 1] + $f)) ?>">Enviar a Excel</a>
</div>

<form method="get" action="<?= e(url('/listados/iva-ventas')) ?>" class="toolbar filtros">
    <label class="field"><span>Desde</span><input name="desde" value="<?= e($f['desde'] ?? '') ?>" placeholder="dd/mm/aaaa" inputmode="numeric"></label>
    <label class="field"><span>Hasta</span><input name="hasta" value="<?= e($f['hasta'] ?? '') ?>" placeholder="dd/mm/aaaa" inputmode="numeric"></label>
    <select name="pv" aria-label="Punto de venta"><option value="">Todos los puntos de venta</option>
        <?php foreach ($pvs as $pv): ?><option value="<?= $pv ?>" <?= (string) ($f['pv'] ?? '') === (string) $pv ? 'selected' : '' ?>><?= e(sprintf('%04d', $pv)) ?></option><?php endforeach; ?></select>
    <select name="tipo" aria-label="Tipo"><option value="">Todos los tipos</option>
        <?php foreach (Tipos::NOMBRES as $k => $t): ?><option value="<?= $k ?>" <?= ($f['tipo'] ?? '') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
    <label class="check"><input type="checkbox" name="pruebas" value="1" <?= ($f['pruebas'] ?? '') === '1' ? 'checked' : '' ?>> Incluir comprobantes de prueba</label>
    <button class="btn btn-ghost" type="submit">Ver</button>
</form>

<?php if ($filas === []): ?>
    <p class="muted vacio">No hay comprobantes para esos filtros.</p>
<?php else: ?>
    <?= paginador($pagina, $paginas, $total, $porPagina, $link, true) ?>
    <div class="table-wrap"><table class="table table-cards tabla-iva">
        <thead><tr><th>Fecha</th><th>Comprobante</th><th>Cliente</th><th>CUIT</th><th class="num">Neto gravado</th><th class="num">Exento</th>
            <?php foreach ($alicuotas as $a): ?><th class="num">IVA <?= e($nombreAlic($a)) ?> %</th><?php endforeach; ?>
            <th class="num">Otras</th><th class="num">Total</th></tr></thead>
        <tbody>
        <?php foreach ($filas as $r): ?>
            <tr><td data-label="Fecha" class="nw"><?= e(date('d/m/Y', (int) strtotime($r['fecha']))) ?></td>
                <td data-label="Comprobante" class="nw"><a href="<?= e(url('/comprobantes/' . (int) $r['fe_clave'])) ?>"><?= e(($r['tipo'] === 'FC' ? 'FC' : $r['tipo']) . ' ' . $r['letra'] . ' ' . sprintf('%04d-%08d', $r['prefijo'], $r['numero'])) ?></a>
                    <?= (int) $r['es_prueba'] === 1 ? '<span class="chip">prueba</span>' : '' ?></td>
                <td data-label="Cliente"><?= e((string) $r['razon_social']) ?></td>
                <td data-label="CUIT" class="nw"><?= e(cuit_texto($r['cuit_cliente'])) ?></td>
                <td data-label="Neto gravado" class="num"><?= e($m($r['gravado'])) ?></td>
                <td data-label="Exento" class="num"><?= e($m($r['exento'])) ?></td>
                <?php foreach (array_keys($alicuotas) as $i): ?><td data-label="IVA <?= e($nombreAlic($alicuotas[$i])) ?> %" class="num"><?= e($m($r["iva_{$i}"])) ?></td><?php endforeach; ?>
                <td data-label="Otras" class="num"><?= e($m($r['iva_otras'])) ?></td>
                <td data-label="Total" class="num"><b><?= e($m($r['total'])) ?></b></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th colspan="4">Totales del período (<?= (int) $total ?> comprobantes; las notas de crédito restan)</th>
            <th class="num"><?= e($m($totales['gravado'])) ?></th><th class="num"><?= e($m($totales['exento'])) ?></th>
            <?php foreach (array_keys($alicuotas) as $i): ?><th class="num"><?= e($m($totales["iva_{$i}"])) ?></th><?php endforeach; ?>
            <th class="num"><?= e($m($totales['iva_otras'])) ?></th><th class="num"><?= e($m($totales['total'])) ?></th></tr></tfoot>
    </table></div>
<?php endif; ?>
