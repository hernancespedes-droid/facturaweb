<?php
/** @var string $usuario */
/** @var string|null $error */
?>
<section class="auth">
    <div class="card auth-card">
        <div class="auth-brand">
            <p class="auth-sub">Factura<b>Web</b></p>
            <p class="eyebrow">Organización &amp; Sistemas</p>
        </div>
        <h1>Ingresar</h1>
        <?php if ($error !== null): ?>
            <div class="alerta alerta-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/login')) ?>" class="form">
            <?= csrf_field() ?>
            <label class="field">
                <span>Usuario</span>
                <input name="usuario" value="<?= e($usuario) ?>" autocomplete="username" autocapitalize="none" required autofocus>
            </label>
            <label class="field">
                <span>Contraseña</span>
                <input name="clave" type="password" autocomplete="current-password" required>
            </label>
            <label class="check recordar"><input type="checkbox" name="recordar" value="1"> Recordarme en este dispositivo (30 días)</label>
            <button class="btn btn-block" type="submit">Ingresar</button>
        </form>
    </div>
</section>
