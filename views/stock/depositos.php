<?php /** @var array<int,array<string,mixed>> $depositos @var ?string $error */ ?>
<div class="page-head"><div><p class="eyebrow">Stock</p><h1>Depósitos</h1></div></div>
<?php if ($error): ?><div class="alerta alerta-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<p class="small muted">Las facturas descuentan del <b>depósito principal</b> y las notas de crédito lo reponen.</p>
<div class="table-wrap"><table class="table table-cards">
    <thead><tr><th>Código</th><th>Nombre</th><th>Principal</th><th>Activo</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($depositos as $d): ?>
        <tr><form method="post" action="<?= e(url('/stock/depositos')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
            <td data-label="Código"><input name="codigo" value="<?= e($d['codigo']) ?>" maxlength="10" size="8"></td>
            <td data-label="Nombre"><input name="nombre" value="<?= e($d['nombre']) ?>" maxlength="60"></td>
            <td data-label="Principal"><label class="check"><input type="checkbox" name="principal" value="1" <?= (int) $d['principal'] === 1 ? 'checked' : '' ?>> principal</label></td>
            <td data-label="Activo"><select name="activo"><option value="1" <?= (int) $d['activo'] === 1 ? 'selected' : '' ?>>Sí</option><option value="0" <?= (int) $d['activo'] === 0 ? 'selected' : '' ?>>No</option></select></td>
            <td><button class="btn btn-ghost btn-sm" type="submit">Guardar</button></td></form></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<form method="post" action="<?= e(url('/stock/depositos')) ?>" class="card form-grid"><?= csrf_field() ?>
    <h2>Nuevo depósito</h2>
    <label class="field"><span>Código *</span><input name="codigo" maxlength="10" required></label>
    <label class="field"><span>Nombre *</span><input name="nombre" maxlength="60" required></label>
    <label class="check"><input type="checkbox" name="principal" value="1"> Es el depósito principal</label>
    <div class="acciones"><button class="btn" type="submit">Agregar</button></div>
</form>
