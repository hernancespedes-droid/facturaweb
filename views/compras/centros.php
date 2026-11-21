<?php
/**
 * Centros de costos, presupuesto mensual, conceptos de gasto y plantillas de reparto.
 * @var string $periodo @var array $centros @var array $pr @var array $conceptos @var array $plantillas @var ?string $error
 */
$m = static fn ($v) => number_format((float) $v, 2, ',', '.');
$principales = array_filter($centros, static fn ($c) => $c['nivel'] === 0 && (int) $c['general'] === 0);
$ant = date('Y-m', strtotime($periodo . '-01 -1 month'));
$sig = date('Y-m', strtotime($periodo . '-01 +1 month'));
$u = url('/compras/centros');
$prPorId = [];
foreach ($pr['filas'] as $x) {
    $prPorId[$x['id']] = $x;
}
?>
<div class="page-head"><div><p class="eyebrow">Compras</p><h1>Centros de costos y conceptos</h1></div></div>
<?php if ($error): ?><div class="alerta alerta-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<p class="small muted">El <b>centro</b> dice para qué parte del negocio es un gasto (local, depósito, una obra, una sucursal) y el <b>concepto</b> qué se compró (alquiler, fletes, mercadería).
    Hay dos niveles como mucho. Un centro o concepto usado no se borra: se desactiva.</p>

<h2 id="centros">Centros de costos</h2>
<div class="table-wrap"><table class="table table-cards">
    <thead><tr><th>Nombre</th><th>Dentro de</th><th>Estado</th><th></th></tr></thead>
    <tbody><?php foreach ($centros as $id => $c): ?>
        <tr><form method="post" action="<?= e(url('/compras/centros/centro')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $id ?>"><input type="hidden" name="periodo" value="<?= e($periodo) ?>">
            <td data-label="Nombre" class="<?= $c['nivel'] ? 'centro-hijo' : '' ?>"><input name="nombre" value="<?= e($c['nombre']) ?>" maxlength="60" aria-label="Nombre"><?= (int) $c['general'] === 1 ? ' <span class="pill">por omisión</span>' : '' ?></td>
            <td data-label="Dentro de"><?php if ((int) $c['general'] === 1): ?>—<?php else: ?><select name="padre_id" aria-label="Dentro de"><option value="">(es principal)</option>
                <?php foreach ($principales as $pid => $pc): if ($pid === $id) continue; ?><option value="<?= (int) $pid ?>" <?= (int) ($c['padre_id'] ?? 0) === $pid ? 'selected' : '' ?>><?= e($pc['nombre']) ?></option><?php endforeach; ?></select><?php endif; ?></td>
            <td data-label="Estado"><?php if ((int) $c['general'] === 1): ?>Activo<?php else: ?><select name="activo" aria-label="Estado"><option value="1" <?= (int) $c['activo'] === 1 ? 'selected' : '' ?>>Activo</option><option value="0" <?= (int) $c['activo'] === 0 ? 'selected' : '' ?>>Inactivo</option></select><?php endif; ?></td>
            <td><button class="btn btn-ghost btn-sm" type="submit">Guardar</button></td></form></tr>
    <?php endforeach; ?></tbody>
</table></div>
<form method="post" action="<?= e(url('/compras/centros/centro')) ?>" class="card toolbar"><?= csrf_field() ?><input type="hidden" name="periodo" value="<?= e($periodo) ?>">
    <label class="field"><span>Nuevo centro</span><input name="nombre" maxlength="60" required placeholder="Ej.: Sucursal Centro"></label>
    <label class="field"><span>Dentro de</span><select name="padre_id"><option value="">(es principal)</option>
        <?php foreach ($principales as $pid => $pc): ?><option value="<?= (int) $pid ?>"><?= e($pc['nombre']) ?></option><?php endforeach; ?></select></label>
    <button class="btn" type="submit">Agregar</button>
</form>

<h2 id="presupuesto">Presupuesto de <?= e(substr($periodo, 5) . '/' . substr($periodo, 0, 4)) ?></h2>
<div class="toolbar">
    <a class="btn btn-ghost btn-sm" href="<?= e($u . '?periodo=' . $ant . '#presupuesto') ?>">‹ Mes anterior</a>
    <a class="btn btn-ghost btn-sm" href="<?= e($u . '?periodo=' . $sig . '#presupuesto') ?>">Mes siguiente ›</a>
    <form method="post" action="<?= e(url('/compras/centros/copiar')) ?>" style="margin:0"><?= csrf_field() ?><input type="hidden" name="periodo" value="<?= e($periodo) ?>">
        <button class="btn btn-ghost btn-sm" type="submit">Copiar el presupuesto del mes anterior</button></form>
</div>
<form method="post" action="<?= e(url('/compras/centros/presupuesto')) ?>"><?= csrf_field() ?><input type="hidden" name="periodo" value="<?= e($periodo) ?>">
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th>Centro</th><th class="num">Presupuesto del mes ($)</th><th class="num">Gastado</th><th>Uso</th></tr></thead>
        <tbody><?php foreach ($centros as $id => $c): if ((int) $c['activo'] === 0) continue; $x = $prPorId[$id] ?? null; ?>
            <tr><td data-label="Centro" class="<?= $c['nivel'] ? 'centro-hijo' : '' ?>"><?= $c['nivel'] ? '— ' : '' ?><?= e($c['nombre']) ?></td>
                <td data-label="Presupuesto" class="num"><input name="importe[<?= (int) $id ?>]" value="<?= $x !== null && $x['presupuesto_propio'] > 0 ? e($m($x['presupuesto_propio'])) : '' ?>" inputmode="decimal" data-decimales="2" style="max-width:10rem;text-align:right" aria-label="Presupuesto de <?= e($c['nombre']) ?>"></td>
                <td data-label="Gastado" class="num"><?= e($m($x['gastado'] ?? 0)) ?></td>
                <td data-label="Uso"><?php if ($x !== null && $x['uso'] !== null): ?><div class="medidor"><i class="<?= $x['uso'] > 100 ? 'pasado' : ($x['uso'] >= 90 ? 'alto' : '') ?>" style="width:<?= min(100, max(1, (int) round($x['uso']))) ?>%"></i></div><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <p class="small muted">Vacío o 0 = sin presupuesto. Un centro principal suma el presupuesto propio y el de sus centros de segundo nivel.</p>
    <div class="acciones"><button class="btn" type="submit">Guardar presupuesto</button></div>
</form>

<h2 id="conceptos">Conceptos de gasto</h2>
<div class="table-wrap"><table class="table table-cards">
    <thead><tr><th>Concepto</th><th>Estado</th><th></th></tr></thead>
    <tbody><?php foreach ($conceptos as $id => $c): ?>
        <tr><form method="post" action="<?= e(url('/compras/centros/concepto')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $id ?>"><input type="hidden" name="periodo" value="<?= e($periodo) ?>">
            <td data-label="Concepto"><input name="nombre" value="<?= e($c['nombre']) ?>" maxlength="60" aria-label="Nombre"></td>
            <td data-label="Estado"><select name="activo" aria-label="Estado"><option value="1" <?= (int) $c['activo'] === 1 ? 'selected' : '' ?>>Activo</option><option value="0" <?= (int) $c['activo'] === 0 ? 'selected' : '' ?>>Inactivo</option></select></td>
            <td><button class="btn btn-ghost btn-sm" type="submit">Guardar</button></td></form></tr>
    <?php endforeach; ?></tbody>
</table></div>
<form method="post" action="<?= e(url('/compras/centros/concepto')) ?>" class="card toolbar"><?= csrf_field() ?><input type="hidden" name="periodo" value="<?= e($periodo) ?>">
    <label class="field"><span>Nuevo concepto</span><input name="nombre" maxlength="60" required placeholder="Ej.: Seguros"></label>
    <button class="btn" type="submit">Agregar</button>
</form>

<h2 id="plantillas">Plantillas de reparto</h2>
<p class="small muted">Para gastos que se comparten siempre igual (la luz, el alquiler): al cargar la compra se elige la plantilla y el reparto se completa solo.</p>
<?php if ($plantillas !== []): ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th>Plantilla</th><th>Reparto</th><th></th></tr></thead>
        <tbody><?php foreach ($plantillas as $pl): ?>
            <tr><td data-label="Plantilla"><?= e($pl['nombre']) ?></td>
                <td data-label="Reparto"><?= e(implode(' · ', array_map(static fn ($d) => ($centros[(int) $d['centro']]['completo'] ?? '¿?') . ' ' . rtrim(rtrim(number_format((float) $d['porcentaje'], 2, ',', ''), '0'), ',') . ' %', $pl['detalle']))) ?></td>
                <td><form method="post" action="<?= e(url('/compras/centros/plantilla-borrar')) ?>" style="margin:0"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $pl['id'] ?>"><input type="hidden" name="periodo" value="<?= e($periodo) ?>">
                    <button class="btn btn-ghost btn-sm" type="submit">Borrar</button></form></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
<?php endif; ?>
<form method="post" action="<?= e(url('/compras/centros/plantilla')) ?>" class="card form-grid grid-campos"><?= csrf_field() ?><input type="hidden" name="periodo" value="<?= e($periodo) ?>">
    <h3 style="grid-column:1/-1;margin:0">Nueva plantilla</h3>
    <label class="field" style="grid-column:1/-1"><span>Nombre</span><input name="nombre" maxlength="60" required placeholder="Ej.: Luz y gas"></label>
    <?php for ($i = 0; $i < 4; $i++): ?>
        <label class="field"><span>Centro <?= $i + 1 ?></span><select name="rep[<?= $i ?>][centro]"><option value="">—</option>
            <?php foreach ($centros as $id => $c): if ((int) $c['activo'] === 0) continue; ?><option value="<?= (int) $id ?>"><?= e($c['completo']) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>%</span><input name="rep[<?= $i ?>][porcentaje]" inputmode="decimal" placeholder="<?= $i === 0 ? '60' : ($i === 1 ? '40' : '') ?>"></label>
    <?php endfor; ?>
    <div class="acciones"><button class="btn" type="submit">Guardar plantilla</button></div>
</form>
