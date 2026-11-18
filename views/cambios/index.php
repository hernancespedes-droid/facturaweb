<?php
use App\Modules\Comprobantes\Tipos;

/** @var string $q */
/** @var array<int,array<string,mixed>> $filas */
?>
<div class="page-head"><div><p class="eyebrow">Facturación</p><h1>Cambio de mercadería</h1></div></div>
<p class="muted">Leé el ticket de cambio (escribí o pasá por la pistola el código impreso, por ejemplo <b>PR-X-0004-00000012</b>) o escribí el número del presupuesto o de la factura. Si hay más de uno parecido, elegí cuál es.</p>
<form method="get" action="<?= e(url('/cambios')) ?>" class="toolbar filtros">
    <label class="field"><span>Código del ticket o número del comprobante</span>
        <input type="search" name="q" value="<?= e($q) ?>" autofocus autocomplete="off" placeholder="PR-X-0004-00000012 · 0004-12 · 12" data-ticket></label>
    <button class="btn" type="submit">Buscar</button>
</form>
<?php if ($q !== '' && $filas === []): ?>
    <p class="muted vacio">No hay ningún presupuesto ni factura con ese código.</p>
<?php elseif ($filas !== []): ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th>Comprobante</th><th>Fecha</th><th>Cliente</th><th class="num">Total</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($filas as $f): ?>
            <tr><td data-label="Comprobante" class="nw"><a href="<?= e(url('/comprobantes/' . $f['fe_clave'])) ?>"><b><?= e(Tipos::titulo($f['tipo'], $f['letra'])) ?></b> <?= e(numero_visible($f)) ?></a></td>
                <td data-label="Fecha" class="nw"><?= e(fecha($f['fecha'])) ?></td>
                <td data-label="Cliente"><?= e((string) $f['razon_social']) ?></td>
                <td data-label="Total" class="num"><?= e(plata_dec($f['neto'])) ?></td>
                <td data-label="">
                    <?php if ((int) $f['solo_subdiario'] === 1): ?><span class="muted small">Salió de un presupuesto: el cambio se hace sobre el presupuesto</span>
                    <?php else: ?><a class="btn btn-sm" href="<?= e(url('/cambios/nuevo') . '?cbte=' . (int) $f['fe_clave']) ?>">Hacer el cambio</a><?php endif; ?>
                </td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
