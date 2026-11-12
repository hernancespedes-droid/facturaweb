<?php /** @var array<string,string> $datos @var ?string $error @var array $depositos @var int $principal @var bool $activo */
$v = static fn (string $k, string $d = '') => e($datos[$k] ?? $d);
$dep = (int) ($datos['deposito'] ?? $principal);
?>
<div class="page-head"><div><p class="eyebrow">Stock</p><h1>Ingreso, egreso o ajuste</h1></div></div>
<?php if ($error): ?><div class="alerta alerta-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('/stock/ajuste')) ?>" class="card form-grid"><?= csrf_field() ?>
    <label class="field"><span>Artículo * (código, código de barras o SKU)</span><input name="articulo" value="<?= $v('articulo') ?>" required autocomplete="off" autofocus placeholder="Escribí código, código de barras, SKU o parte de la descripción" data-buscador-url="<?= e(url('/stock/buscar-articulo')) ?>"></label>
    <label class="field"><span>Qué querés hacer *</span><select name="modo">
        <?php foreach (['INGRESO' => 'Ingreso (suma)', 'EGRESO' => 'Egreso (resta)', 'AJUSTE' => 'Ajuste: dejar el stock en lo contado'] as $k => $t): ?><option value="<?= $k ?>" <?= ($datos['modo'] ?? '') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Depósito *</span><select name="deposito"><?php foreach ($depositos as $d): ?><option value="<?= (int) $d['id'] ?>" <?= $dep === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['nombre']) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Cantidad *</span><input name="cantidad" value="<?= $v('cantidad') ?>" inputmode="decimal" required></label>
    <label class="field"><span>En</span><select name="unidad"><option value="unidades">Unidades</option><option value="bultos" <?= ($datos['unidad'] ?? '') === 'bultos' ? 'selected' : '' ?>>Bultos (se convierte con las unidades por bulto del artículo)</option></select></label>
    <label class="field"><span>Motivo</span><input name="motivo" value="<?= $v('motivo') ?>" maxlength="255" placeholder="Stock inicial, rotura, conteo…"></label>
    <div class="acciones"><button class="btn" type="submit">Registrar</button><a class="btn btn-ghost" href="<?= e(url('/stock')) ?>">Cancelar</a></div>
</form>
<script src="<?= e(asset('assets/ventana-articulos.js')) ?>" defer></script>
<script src="<?= e(asset('assets/buscador.js')) ?>" defer></script>
