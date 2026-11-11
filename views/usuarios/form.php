<?php
/** @var array<string,mixed>|null $u */
/** @var array<string,mixed> $datos */
/** @var array<string,string> $errores */
/** @var array<int,array<string,mixed>> $roles */
/** @var bool $propio */
$err = static fn (string $k): string => isset($errores[$k]) ? '<small class="field-error">' . e($errores[$k]) . '</small>' : '';
?>
<p><a href="<?= e(url('/usuarios')) ?>">← Usuarios</a></p>
<div class="page-head">
    <h1><?= $u === null ? 'Nuevo usuario' : e($u['usuario']) ?></h1>
    <?php if ($u !== null): ?>
        <div>
            <?php if ((int) $u['activo'] !== 1): ?><span class="badge badge-off">Inactivo</span>
            <?php elseif ((int) $u['bloqueado'] === 1): ?><span class="badge badge-warn">Bloqueado</span>
            <?php else: ?><span class="badge badge-ok">Activo</span><?php endif; ?>
            <?php if ((int) $u['debe_cambiar_clave'] === 1): ?><span class="badge">Contraseña temporal</span><?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php if (isset($errores['general'])): ?><div class="alerta alerta-error"><?= e($errores['general']) ?></div><?php endif; ?>

<form method="post" action="<?= e(url($u === null ? '/usuarios' : '/usuarios/' . $u['id'])) ?>" class="card form">
    <?= csrf_field() ?>
    <div class="grid-2">
        <label class="field">
            <span>Usuario (para ingresar)</span>
            <?php if ($u === null): ?>
                <input name="usuario" value="<?= e($datos['usuario'] ?? '') ?>" required maxlength="40" autocapitalize="none" pattern="[A-Za-z0-9._\-]{3,40}">
                <small class="muted">Sin espacios ni acentos. No se puede cambiar después.</small>
            <?php else: ?>
                <input value="<?= e($u['usuario']) ?>" disabled>
            <?php endif; ?>
            <?= $err('usuario') ?>
        </label>
        <label class="field">
            <span>Nombre y apellido</span>
            <input name="nombre" value="<?= e($datos['nombre'] ?? '') ?>" required maxlength="80">
            <?= $err('nombre') ?>
        </label>
        <label class="field">
            <span>Email (opcional)</span>
            <input name="email" type="email" value="<?= e($datos['email'] ?? '') ?>" maxlength="120">
            <?= $err('email') ?>
        </label>
    </div>

    <h2>Rol</h2>
    <?php if ($propio): ?>
        <p class="muted">Tu propio rol lo cambia otro administrador.</p>
    <?php endif; ?>
    <label class="field">
        <span>Rol</span>
        <select name="rol_id" <?= $propio ? 'disabled' : '' ?> required>
            <option value="">Elegí el rol</option>
            <?php foreach ($roles as $rid => $r): ?>
                <option value="<?= e($rid) ?>" <?= (int) ($datos['rol_id'] ?? 0) === $rid ? 'selected' : '' ?>><?= e($r['nombre']) ?></option>
            <?php endforeach; ?>
            <?php if ($propio && $u !== null && !isset($roles[(int) $u['rol_id']])): ?><option selected><?= e($u['rol_nombre']) ?></option><?php endif; ?>
        </select>
        <?= $err('rol_id') ?>
    </label>
    <?php if ($u === null): ?>
        <p class="muted">Al crear el usuario se genera una contraseña temporal que vas a ver una sola vez.</p>
    <?php endif; ?>
    <div class="actions">
        <button class="btn" type="submit"><?= $u === null ? 'Crear usuario' : 'Guardar cambios' ?></button>
    </div>
</form>

<?php if ($u !== null): ?>
    <section class="card">
        <h2>Acceso</h2>
        <?php if (!empty($u['usuario_vfp'])): ?><p class="muted">Usuario traído del sistema VFP (<?= e($u['usuario_vfp']) ?>). La contraseña del VFP no se migra: generale una temporal con "Resetear contraseña".</p><?php endif; ?>
        <p class="muted">
            Último ingreso: <?= $u['ultimo_login'] ? e(date('d/m/Y H:i', strtotime((string) $u['ultimo_login']))) : 'nunca' ?>.
            <?php if ((int) $u['intentos_fallidos'] > 0): ?>Intentos fallidos seguidos: <?= e($u['intentos_fallidos']) ?>.<?php endif; ?>
        </p>
        <div class="actions">
            <?php if (!$propio): ?>
                <form method="post" action="<?= e(url('/usuarios/' . $u['id'] . '/clave')) ?>" data-confirmar="Se va a generar una contraseña temporal nueva. ¿Seguimos?">
                    <?= csrf_field() ?>
                    <button class="btn btn-ghost" type="submit">Resetear contraseña</button>
                </form>
            <?php endif; ?>
            <?php if ((int) $u['bloqueado'] === 1): ?>
                <form method="post" action="<?= e(url('/usuarios/' . $u['id'] . '/desbloquear')) ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn-ghost" type="submit">Desbloquear</button>
                </form>
            <?php endif; ?>
            <?php if (!$propio): ?>
                <form method="post" action="<?= e(url('/usuarios/' . $u['id'] . '/activo')) ?>"
                      <?= (int) $u['activo'] === 1 ? 'data-confirmar="El usuario no va a poder ingresar más. ¿Lo desactivamos?"' : '' ?>>
                    <?= csrf_field() ?>
                    <input type="hidden" name="activo" value="<?= (int) $u['activo'] === 1 ? '0' : '1' ?>">
                    <button class="btn <?= (int) $u['activo'] === 1 ? 'btn-danger' : '' ?>" type="submit"><?= (int) $u['activo'] === 1 ? 'Desactivar' : 'Activar' ?></button>
                </form>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
