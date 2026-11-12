<?php /** @var array<string,string> $datos @var ?string $error @var array $depositos @var int $principal */
$v = static fn (string $k, string $d = '') => e($datos[$k] ?? $d);
$opc = static fn (string $sel) => implode('', array_map(static fn ($d) => '<option value="' . (int) $d['id'] . '"' . ($sel === (string) $d['id'] ? ' selected' : '') . '>' . e($d['nombre']) . '</option>', $depositos));
?>
<div class="page-head"><div><p class="eyebrow">Stock</p><h1>Transferencia entre depósitos</h1></div></div>
<?php if ($error): ?><div class="alerta alerta-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('/stock/transferir')) ?>" class="card form-grid"><?= csrf_field() ?>
    <label class="field"><span>Artículo * (código, código de barras o SKU)</span><input name="articulo" value="<?= $v('articulo') ?>" required autocomplete="off" autofocus></label>
    <label class="field"><span>Desde *</span><select name="origen"><?= $opc((string) ($datos['origen'] ?? $principal)) ?></select></label>
    <label class="field"><span>Hasta *</span><select name="destino"><?= $opc((string) ($datos['destino'] ?? '')) ?></select></label>
    <label class="field"><span>Cantidad *</span><input name="cantidad" value="<?= $v('cantidad') ?>" inputmode="decimal" required></label>
    <label class="field"><span>En</span><select name="unidad"><option value="unidades">Unidades</option><option value="bultos" <?= ($datos['unidad'] ?? '') === 'bultos' ? 'selected' : '' ?>>Bultos</option></select></label>
    <label class="field"><span>Motivo</span><input name="motivo" value="<?= $v('motivo') ?>" maxlength="255"></label>
    <div class="acciones"><button class="btn" type="submit">Transferir</button><a class="btn btn-ghost" href="<?= e(url('/stock')) ?>">Cancelar</a></div>
</form>
