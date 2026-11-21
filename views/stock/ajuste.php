<?php /** @var array<string,string> $datos @var ?string $error @var array $depositos @var int $principal @var bool $activo @var bool $puedeCosto @var ?array $pregunta */
$m = static fn ($x) => number_format((float) $x, 2, ',', '.');
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
    <?php if ($puedeCosto): ?>
    <label class="field" data-solo-ingreso><span>Costo por unidad (opcional)</span><input name="costo" value="<?= $v('costo') ?>" inputmode="decimal" data-decimales="4" placeholder="El precio al que se compró"
        <?= $pregunta !== null ? 'readonly' : '' ?>><small class="muted">Si cambia el costo del artículo, antes de grabar te pregunto si actualizo los precios.</small></label>
    <?php endif; ?>
    <label class="field"><span>Motivo</span><input name="motivo" value="<?= $v('motivo') ?>" maxlength="255" placeholder="Stock inicial, rotura, conteo…"></label>
    <?php if ($pregunta !== null): $c = $pregunta['calc']; $f = $pregunta['ficha']; ?>
    <div class="card pregunta-precios" role="alert" style="grid-column: 1 / -1">
        <h2>El costo de <?= e($f['codigo'] . ' · ' . $f['descripcion']) ?> cambia de $ <?= e($m($f['costo'])) ?> a $ <?= e($m($c['costo'])) ?></h2>
        <?php if ($c['cambia']): ?>
            <p>¿Actualizo también los precios con los márgenes?</p>
            <div class="table-wrap"><table class="table" data-grilla="no"><thead><tr><th>Lista</th><th class="num">Margen</th><th class="num">Precio actual</th><th class="num">Precio nuevo</th></tr></thead><tbody>
            <?php foreach ($c['listas'] as $l): ?>
                <tr><td><?= e($l['nombre']) ?></td><td class="num"><?= $l['margen'] === null ? '<span class="muted">sin margen</span>' : e(number_format((float) $l['margen'], 2, ',', '.') . ' % ' . $l['origen']) ?></td>
                    <td class="num"><?= e($m($l['actual'])) ?></td><td class="num"><b><?= $l['nuevo'] === null ? e($m($l['actual'])) : e($m($l['nuevo'])) ?></b></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php else: ?>
            <p class="muted">Ninguna lista tiene margen cargado (artículo, rubro o Parámetros), así que los precios no cambian.</p>
        <?php endif; ?>
        <div class="acciones">
            <?php if ($c['cambia']): ?><button class="btn" type="submit" name="precios" value="precios">Sí, costo y precios</button><?php endif; ?>
            <button class="btn <?= $c['cambia'] ? 'btn-ghost' : '' ?>" type="submit" name="precios" value="costo">Solo el costo</button>
            <button class="btn btn-ghost" type="submit" name="precios" value="nada">No cambiar el costo</button>
        </div>
    </div>
    <?php endif; ?>
    <div class="acciones" <?= $pregunta !== null ? 'hidden' : '' ?>><button class="btn" type="submit">Registrar</button><a class="btn btn-ghost" href="<?= e(url('/stock')) ?>">Cancelar</a></div>
</form>
<script>
(function () { // el costo es solo para los ingresos
  var modo = document.querySelector('select[name="modo"]'), c = document.querySelector('[data-solo-ingreso]');
  if (!modo || !c) return;
  function ver() { c.hidden = modo.value !== 'INGRESO'; }
  modo.addEventListener('change', ver); ver();
})();
</script>
<script src="<?= e(asset('assets/stock-articulo.js')) ?>" defer></script>
<script src="<?= e(asset('assets/ventana-lista.js')) ?>" defer></script>
<script src="<?= e(asset('assets/buscador.js')) ?>" defer></script>
