<?php
/** @var array<int,array<string,mixed>> $lista */
/** @var array<string,string> $errores */
?>
<div class="page-head">
    <div>
        <h1>Configuración</h1>
        <p class="muted small">Ajustes del sistema web. Los datos de la empresa están en Tablas → Parámetros.</p>
    </div>
</div>
<form method="post" action="<?= e(url('/configuracion')) ?>" class="card">
    <?= csrf_field() ?>
    <?php foreach ($lista as $p): $id = 'p-' . preg_replace('/[^a-z0-9]/', '-', (string) $p['clave']); ?>
        <div class="field">
            <?php if ($p['tipo'] === 'si_no'): ?>
                <input type="hidden" name="valor[<?= e($p['clave']) ?>]" value="0">
                <label class="check"><input type="checkbox" id="<?= e($id) ?>" name="valor[<?= e($p['clave']) ?>]" value="1" <?= $p['valor'] === '1' ? 'checked' : '' ?>> <?= e($p['descripcion']) ?></label>
            <?php else: ?>
            <label for="<?= e($id) ?>"><?= e($p['descripcion']) ?></label>
            <input id="<?= e($id) ?>" name="valor[<?= e($p['clave']) ?>]" value="<?= e($p['valor']) ?>" <?= $p['tipo'] === 'entero' ? 'inputmode="numeric" class="corto"' : '' ?>>
            <?php endif; ?>
            <?php if (isset($errores[$p['clave']])): ?><p class="field-error"><?= e($errores[$p['clave']]) ?></p><?php endif; ?>
            <p class="small muted"><code><?= e($p['clave']) ?></code>
                <?php if ($p['actualizado_por'] !== null): ?> · cambiado por <?= e($p['actualizado_por']) ?> el <?= e(date('d/m/Y H:i', (int) strtotime((string) $p['actualizado_en']))) ?><?php endif; ?></p>
        </div>
    <?php endforeach; ?>
    <button class="btn" type="submit">Guardar</button>
</form>
