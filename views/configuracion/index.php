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

<?php $logo = \App\Modules\Configuracion\Logo::obtener(); ?>
<section class="card" id="logo">
    <h2>Logo de los comprobantes</h2>
    <p class="small muted">Sale arriba a la izquierda en las facturas, notas de crédito y débito, recibos y en el PDF que se manda por mail.
        PNG, JPG, GIF o WEBP de hasta 2 MB; conviene horizontal y con fondo blanco o transparente (lo transparente se imprime en blanco).</p>
    <?php if ($logo !== null): ?>
        <div class="logo-actual" style="border:1px solid var(--line-2); padding:8px; display:inline-block; background:#fff; margin-bottom:10px">
            <img src="<?= e((string) \App\Modules\Configuracion\Logo::dataUri()) ?>" alt="Logo actual" style="display:block; max-width:300px; max-height:80px">
        </div>
    <?php else: ?>
        <p class="small">Todavía no hay logo cargado.</p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/configuracion/logo')) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="field">
            <label for="logo-archivo"><?= $logo !== null ? 'Reemplazar por otra imagen' : 'Imagen del logo' ?></label>
            <input id="logo-archivo" type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp" required>
        </div>
        <button class="btn" type="submit">Subir logo</button>
    </form>
    <?php if ($logo !== null): ?>
        <form method="post" action="<?= e(url('/configuracion/logo/quitar')) ?>" style="margin-top:10px" onsubmit="return confirm('¿Quitar el logo de los comprobantes?')">
            <?= csrf_field() ?>
            <button class="btn btn-ghost" type="submit">Quitar logo</button>
        </form>
    <?php endif; ?>
</section>
