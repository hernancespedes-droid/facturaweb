<?php
use App\Modules\Compras\MisComprobantes as MC;

/** «Mis Comprobantes Recibidos» de ARCA contra lo cargado. @var ?string $error @var string $desde @var string $hasta @var string $estado @var array $r @var bool $puedeCargar */
$u = url('/compras/mis-comprobantes');
$m = static fn ($v) => number_format((float) $v, 2, ',', '.');
$q = ['desde' => fecha($desde), 'hasta' => fecha($hasta)];
$pill = ['falta' => 'pill-mal', 'diferencia' => 'pill-warn', 'ok' => 'pill-ok'];
?>
<div class="page-head">
    <div><p class="eyebrow">Compras</p><h1>Mis Comprobantes de ARCA</h1></div>
</div>
<?php if ($error): ?><div class="alerta alerta-error" role="alert"><?= e($error) ?></div><?php endif; ?>

<?php if ($puedeCargar): ?>
<details class="card" <?= $r['importados'] === 0 ? 'open' : '' ?>>
    <summary><b>Importar el archivo de ARCA</b></summary>
    <ol class="small">
        <li>Entrá a ARCA con clave fiscal → <b>Mis Comprobantes</b> → <b>Recibidos</b>.</li>
        <li>Elegí las fechas (por ejemplo, el mes) y buscá.</li>
        <li>Bajá el resultado en <b>Excel</b> o <b>CSV</b> y subilo acá. Se puede subir las veces que haga falta: lo repetido se actualiza.</li>
    </ol>
    <form method="post" action="<?= e($u) ?>" enctype="multipart/form-data" class="toolbar">
        <?= csrf_field() ?>
        <label class="field"><span>Archivo (.xlsx o .csv)</span><input type="file" name="archivo" accept=".xlsx,.csv,.txt" required></label>
        <button class="btn" type="submit">Importar y controlar</button>
    </form>
</details>
<?php endif; ?>

<form method="get" action="<?= e($u) ?>" class="toolbar filtros">
    <label class="field"><span>Desde</span><input name="desde" value="<?= e(fecha($desde)) ?>" placeholder="dd/mm/aaaa" inputmode="numeric"></label>
    <label class="field"><span>Hasta</span><input name="hasta" value="<?= e(fecha($hasta)) ?>" placeholder="dd/mm/aaaa" inputmode="numeric"></label>
    <label class="field"><span>Mostrar</span><select name="estado"><option value="">Todos</option>
        <?php foreach (MC::ESTADOS as $k => $t): ?><option value="<?= e($k) ?>" <?= $estado === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
    <button class="btn" type="submit">Ver</button>
</form>

<section class="card resumen-fila" aria-label="Resumen">
    <div><span class="muted small">En ARCA</span><b><?= (int) $r['importados'] ?></b></div>
    <div><span class="muted small">Cargados</span><b><?= (int) $r['cuenta']['ok'] ?></b></div>
    <div><span class="muted small">Con diferencia</span><b><?= (int) $r['cuenta']['diferencia'] ?></b></div>
    <div><span class="muted small">Falta cargar</span><b><?= (int) $r['cuenta']['falta'] ?></b></div>
    <div><span class="muted small">Cargados y no están en ARCA</span><b><?= count($r['sobrantes']) ?></b></div>
</section>

<?php if ($r['importados'] === 0): ?>
    <p class="muted vacio">No hay comprobantes de ARCA importados entre esas fechas.</p>
<?php else: ?>
    <h2>Según ARCA</h2>
    <?php if ($r['filas'] === []): ?><p class="muted vacio">Nada para mostrar con ese filtro.</p><?php else: ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th>Fecha</th><th>Comprobante</th><th>Emisor</th><th>CUIT</th><th class="num">Total ARCA</th><th class="num">Cargado</th><th>Estado</th><th></th></tr></thead>
        <tbody><?php foreach ($r['filas'] as $x): ?>
            <tr><td data-label="Fecha" class="nw"><?= e(fecha($x['fecha'])) ?></td><td data-label="Comprobante"><?= e($x['titulo']) ?></td>
                <td data-label="Emisor"><?= e($x['razon_social'] ?: $x['denominacion']) ?></td><td data-label="CUIT" class="nw"><?= e(cuit_texto((string) $x['cuit'])) ?></td>
                <td data-label="Total ARCA" class="num"><?= e($m($x['total'])) ?></td>
                <td data-label="Cargado" class="num"><?= $x['compra_total'] !== null ? e($m($x['compra_total'])) : '' ?></td>
                <td data-label="Estado"><span class="pill <?= $pill[$x['estado']] ?>"><?= e(MC::ESTADOS[$x['estado']]) ?></span></td>
                <td><?php if ($x['compra_id']): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('/compras/ver/' . (int) $x['compra_id'])) ?>">Ver</a>
                    <?php elseif ($puedeCargar): ?><form method="post" action="<?= e(url('/compras/desde-arca')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $x['id'] ?>">
                        <button class="btn btn-sm" type="submit">Cargar</button></form><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
    <?php if ($r['sobrantes'] !== []): ?>
        <h2>Cargados que no están en ARCA</h2>
        <p class="small muted">Puede ser un error de tipeo (proveedor, punto de venta o número), un comprobante de otra fecha o uno que el proveedor no informó: revisalos.</p>
        <div class="table-wrap"><table class="table table-cards">
            <thead><tr><th>Fecha</th><th>Comprobante</th><th>Proveedor</th><th>CUIT</th><th class="num">Total</th></tr></thead>
            <tbody><?php foreach ($r['sobrantes'] as $c): ?>
                <tr><td data-label="Fecha" class="nw"><?= e(fecha($c['fecha'])) ?></td>
                    <td data-label="Comprobante"><a href="<?= e(url('/compras/ver/' . (int) $c['id'])) ?>"><?= e($c['titulo']) ?></a></td>
                    <td data-label="Proveedor"><?= e($c['razon_social']) ?></td><td data-label="CUIT" class="nw"><?= e(cuit_texto((string) $c['cuit'])) ?></td>
                    <td data-label="Total" class="num"><?= e($m($c['total'])) ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
<?php endif; ?>
