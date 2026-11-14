<?php
use App\Modules\CuentaCorriente\CuentaCorrienteService;

/** @var array<string,string> $datos @var ?string $error @var ?array<string,mixed> $cli @var array<int,array<string,mixed>> $sucursales */
$v = static fn (string $k, string $d = ''): string => e($datos[$k] ?? $d);
?>
<div class="page-head"><div><p class="eyebrow">Cuenta corriente</p><h1>Ajuste de cuenta corriente</h1></div></div>
<?php if ($cli === null): ?>
    <p class="muted">Un ajuste suma o resta saldo sin ser una factura (diferencias de cambio, bonificaciones, correcciones). Primero elegí el cliente.</p>
    <?php if ($error): ?><div class="alerta alerta-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php $accion = '/cuenta-corriente/ajuste'; $boton = 'Seguir'; require __DIR__ . '/_picker.php'; ?>
<?php else: ?>
    <p><a href="<?= e(url('/cuenta-corriente/ajuste')) ?>">← Elegir otro cliente</a> · <b><?= e($cli['razon_social']) ?></b> <span class="muted">(<?= (int) $cli['codigo'] ?>)</span></p>
    <?php if ($error): ?><div class="alerta alerta-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= e(url('/cuenta-corriente/ajuste')) ?>" class="card form-grid" data-confirmar="¿Grabás el ajuste? Cambia el saldo del cliente."><?= csrf_field() ?>
        <input type="hidden" name="cliente" value="<?= (int) $cli['codigo'] ?>">
        <label class="field"><span>Tipo *</span><select name="tipo"><?php foreach (CuentaCorrienteService::AJUSTES as $k => $a): ?><option value="<?= $k ?>" <?= ($datos['tipo'] ?? '') === $k ? 'selected' : '' ?>><?= e($a[0]) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Fecha *</span><input name="fecha" value="<?= $v('fecha', date('d/m/Y')) ?>" inputmode="numeric" required></label>
        <?php if (count($sucursales) > 1): ?>
            <label class="field"><span>Sucursal *</span><select name="sucursal"><?php foreach ($sucursales as $s): ?><option value="<?= (int) $s['codigo'] ?>" <?= ($datos['sucursal'] ?? '') === (string) $s['codigo'] ? 'selected' : '' ?>><?= e($s['descripcion']) ?></option><?php endforeach; ?></select></label>
        <?php elseif (count($sucursales) === 1): ?><input type="hidden" name="sucursal" value="<?= (int) array_key_first($sucursales) ?>"><?php endif; ?>
        <label class="field"><span>Importe *</span><input name="importe" value="<?= $v('importe') ?>" inputmode="decimal" data-decimales="2" required></label>
        <label class="field"><span>Motivo *</span><input name="observaciones" value="<?= $v('observaciones') ?>" maxlength="255" required placeholder="Por qué se hace el ajuste"></label>
        <div class="acciones"><button class="btn" type="submit">Grabar ajuste</button><a class="btn btn-ghost" href="<?= e(url('/cuenta-corriente/cliente/' . (int) $cli['codigo'])) ?>">Cancelar</a></div>
    </form>
<?php endif; ?>
