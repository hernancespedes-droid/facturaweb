<?php
use App\Modules\Compras\ComprasService as S;
use App\Modules\Compras\ProveedoresService as P;

/**
 * Ficha del proveedor (alta si $p es null). @var ?array $p @var array $datos @var array<string,string> $errores @var array $centros @var array $conceptos
 * @var float $saldo @var ?array $compras @var bool $puedeCargar @var string $volver
 */
$d = $datos;
$v = static fn (string $k) => e((string) ($d[$k] ?? ''));
$err = static fn (string $k) => isset($errores[$k]) ? '<span class="field-error">' . e($errores[$k]) . '</span>' : '';
$m = static fn ($x) => number_format((float) $x, 2, ',', '.');
$ro = $puedeCargar ? '' : ' disabled';
?>
<div class="page-head">
    <div><p class="eyebrow">Compras · Proveedores</p><h1><?= e($p['razon_social'] ?? 'Nuevo proveedor') ?></h1></div>
    <div class="acciones">
        <?php if ($p !== null && $puedeCargar): ?><a class="btn" href="<?= e(url('/compras/nueva?proveedor=' . (int) $p['id'])) ?>">Cargarle una compra</a><?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('/compras/proveedores')) ?>">Volver</a>
    </div>
</div>
<?php if (isset($errores['general'])): ?><div class="alerta alerta-error"><?= e($errores['general']) ?></div><?php endif; ?>
<?php if ($p !== null): ?>
    <section class="card resumen-fila"><div><span class="muted small">Le debemos</span><b><?= e('$ ' . $m($saldo)) ?></b></div>
        <div><span class="muted small">Código</span><b><?= (int) $p['codigo'] ?></b></div></section>
<?php endif; ?>

<form method="post" action="<?= e(url('/compras/proveedores/guardar')) ?>" class="card form-grid grid-campos"><?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) ($p['id'] ?? 0) ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>">
    <?php if ($p === null): ?><label class="field"><span>Código</span><input name="codigo" value="<?= $v('codigo') ?>" inputmode="numeric" maxlength="5"><?= $err('codigo') ?></label><?php endif; ?>
    <label class="field"><span>Razón social *</span><input name="razon_social" value="<?= $v('razon_social') ?>" maxlength="100" required<?= $ro ?><?= $p === null ? ' autofocus' : '' ?>><?= $err('razon_social') ?></label>
    <label class="field"><span>CUIT</span><input name="cuit" value="<?= e(($d['cuit'] ?? '') !== '' && ctype_digit((string) ($d['cuit'] ?? '')) ? cuit_texto($d['cuit']) : (string) ($d['cuit'] ?? '')) ?>" inputmode="numeric" maxlength="13"<?= $ro ?>><?= $err('cuit') ?></label>
    <?php if (isset($errores['cuit']) && str_contains($errores['cuit'], 'ya es de')): ?><label class="check"><input type="checkbox" name="confirmar_cuit" value="1"> El CUIT repetido está bien</label><?php endif; ?>
    <label class="field"><span>Condición de IVA *</span><select name="iva"<?= $ro ?>><?php foreach (P::CONDICIONES as $k => $t): ?><option value="<?= $k ?>" <?= (int) ($d['iva'] ?? 1) === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select><?= $err('iva') ?></label>
    <label class="field"><span>Domicilio</span><input name="domicilio" value="<?= $v('domicilio') ?>" maxlength="100"<?= $ro ?>></label>
    <label class="field"><span>Localidad</span><input name="localidad" value="<?= $v('localidad') ?>" maxlength="60"<?= $ro ?>></label>
    <label class="field"><span>Teléfono</span><input name="telefono" value="<?= $v('telefono') ?>" maxlength="40"<?= $ro ?>></label>
    <label class="field"><span>Mail</span><input name="email" type="email" value="<?= $v('email') ?>" maxlength="120"<?= $ro ?>><?= $err('email') ?></label>
    <label class="field"><span>CBU o alias</span><input name="cbu_alias" value="<?= $v('cbu_alias') ?>" maxlength="40"<?= $ro ?>></label>
    <label class="field"><span>Plazo de pago (días)</span><input name="plazo_dias" value="<?= $v('plazo_dias') ?>" inputmode="numeric" maxlength="3"<?= $ro ?>><?= $err('plazo_dias') ?></label>
    <label class="field"><span>Centro habitual</span><select name="centro_id"<?= $ro ?>><option value="">General</option>
        <?php foreach ($centros as $id => $c): ?><option value="<?= (int) $id ?>" <?= (int) ($d['centro_id'] ?? 0) === $id ? 'selected' : '' ?>><?= $c['nivel'] ? '— ' : '' ?><?= e($c['nombre']) ?></option><?php endforeach; ?></select><?= $err('centro_id') ?></label>
    <label class="field"><span>Concepto habitual</span><select name="concepto_id"<?= $ro ?>><option value="">Otros gastos</option>
        <?php foreach ($conceptos as $id => $c): ?><option value="<?= (int) $id ?>" <?= (int) ($d['concepto_id'] ?? 0) === $id ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?></select><?= $err('concepto_id') ?></label>
    <label class="field"><span>Observaciones</span><input name="observaciones" value="<?= $v('observaciones') ?>" maxlength="255"<?= $ro ?>></label>
    <?php if ($p !== null): ?><label class="field"><span>Estado</span><select name="activo"<?= $ro ?>><option value="1" <?= (int) ($d['activo'] ?? 1) === 1 ? 'selected' : '' ?>>Activo</option><option value="0" <?= (int) ($d['activo'] ?? 1) === 0 ? 'selected' : '' ?>>Inactivo</option></select></label><?php endif; ?>
    <p class="small muted" style="grid-column:1/-1">El centro y el concepto habitual se proponen solos al cargarle una compra; el plazo calcula el vencimiento.</p>
    <?php if ($puedeCargar): ?><div class="acciones"><button class="btn" type="submit"><?= $p === null ? 'Dar de alta' : 'Guardar' ?></button></div><?php endif; ?>
</form>

<?php if ($compras !== null && $compras['filas'] !== []): ?>
    <h2>Últimas compras</h2>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th>Fecha</th><th>Comprobante</th><th class="num">Total</th><th>Vence</th><th class="num">Saldo</th></tr></thead>
        <tbody><?php foreach ($compras['filas'] as $x): $sg = S::signo($x['tipo']); ?>
            <tr<?= $x['estado'] === 'A' ? ' class="muted"' : '' ?>><td data-label="Fecha"><?= e(fecha($x['fecha'])) ?></td>
                <td data-label="Comprobante"><a href="<?= e(url('/compras/ver/' . (int) $x['id'])) ?>"><?= e(S::titulo($x)) ?></a><?= $x['estado'] === 'A' ? ' <span class="pill pill-mal">anulada</span>' : '' ?></td>
                <td data-label="Total" class="num"><?= e($m($x['total'] * $sg)) ?></td><td data-label="Vence"><?= $x['vence'] ? e(fecha($x['vence'])) : '' ?></td>
                <td data-label="Saldo" class="num"><?= (float) $x['saldo'] > 0.005 ? e($m($x['saldo'] * $sg)) : '' ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <p><a href="<?= e(url('/compras/lista?' . http_build_query(['proveedor' => (int) $p['id'], 'estado' => '']))) ?>">Ver todas sus compras</a></p>
<?php endif; ?>
