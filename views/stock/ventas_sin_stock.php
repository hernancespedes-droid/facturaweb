<?php
use App\Modules\Comprobantes\Tipos;
use App\Modules\Stock\VentasSinStock;

/** @var array<string,string> $f */
/** @var array<int,array<string,mixed>> $filas */
/** @var int $total */
/** @var float $unidades */
/** @var int $pagina */
/** @var int $porPagina */
$u = url('/stock/ventas-sin-stock');
$link = static fn (int $p) => $u . '?' . http_build_query(['pagina' => $p] + $f);
$paginas = (int) ceil($total / max(1, $porPagina));
$n = static fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',');
?>
<div class="page-head"><div><p class="eyebrow">Stock</p><h1>Ventas sin stock</h1></div></div>
<p class="muted small">Lo que se vendió cuando no alcanzaba el stock disponible: quién lo vendió y cómo se autorizó. Las ventas de roles que pueden vender sin stock figuran como «por permiso».</p>
<form method="get" action="<?= e($u) ?>" class="toolbar filtros">
    <label class="field"><span>Desde</span><input name="desde" value="<?= e($f['desde'] ?? '') ?>" placeholder="dd/mm/aaaa (por omisión, 30 días)" inputmode="numeric"></label>
    <label class="field"><span>Hasta</span><input name="hasta" value="<?= e($f['hasta'] ?? '') ?>" placeholder="dd/mm/aaaa" inputmode="numeric"></label>
    <label class="field"><span>Cómo se autorizó</span><select name="modo"><option value="">Todas</option>
        <?php foreach (VentasSinStock::MODOS as $k => $t): ?><option value="<?= e($k) ?>" <?= ($f['modo'] ?? '') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
    <button class="btn btn-ghost" type="submit">Ver</button>
</form>
<p class="small"><b><?= (int) $total ?></b> renglones vendidos sin stock, <b><?= e($n($unidades)) ?></b> unidades de más.</p>
<?php if ($filas === []): ?>
    <p class="muted vacio">No hubo ventas sin stock en ese período.</p>
<?php else: ?>
    <?= paginador($pagina, $paginas, $total, $porPagina, $link, true) ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><?= th_orden('Fecha y hora', 'fecha', $f, $u, true) ?><?= th_orden('Comprobante', 'comprobante', $f, $u) ?><?= th_orden('Artículo', 'articulo', $f, $u) ?><?= th_orden('Vendido de más', 'faltante', $f, $u, true) ?><?= th_orden('Había disponible', 'disponible', $f, $u, true) ?><?= th_orden('Vendió', 'vendio', $f, $u) ?><?= th_orden('Autorizó', 'autorizo', $f, $u) ?><th>Cómo</th></tr></thead>
        <tbody>
        <?php foreach ($filas as $x): ?>
            <tr>
                <td data-label="Fecha y hora" class="nw"><?= e(date('d/m/Y H:i', (int) strtotime($x['fecha']))) ?></td>
                <td data-label="Comprobante" class="nw"><?php if ($x['subdiario_id'] !== null && $x['tipo'] !== null): ?><a href="<?= e(url('/comprobantes/' . (int) $x['subdiario_id'])) ?>"><?= e(Tipos::titulo($x['tipo'], $x['letra']) . ' ' . sprintf('%04d-%08d', $x['prefijo'], $x['numero'])) ?></a><?php else: ?>—<?php endif; ?></td>
                <td data-label="Artículo"><?= e($x['articulo']) ?> <span class="muted"><?= e((string) $x['articulo_desc']) ?></span></td>
                <td data-label="Vendido de más" class="num"><?= e($n($x['cantidad'])) ?></td>
                <td data-label="Había disponible" class="num"><?= e($n($x['disponible'])) ?></td>
                <td data-label="Vendió"><?= e((string) $x['vendio']) ?></td>
                <td data-label="Autorizó"><?= e((string) $x['autorizo']) ?></td>
                <td data-label="Cómo"><?= e(VentasSinStock::MODOS[VentasSinStock::modo($x)]) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
