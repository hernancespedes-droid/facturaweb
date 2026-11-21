<?php
/** @var string $usuario */
/** @var string|null $error */
?>
<section class="auth">
    <div class="card auth-card">
        <?php $logoUrl = \App\Modules\Configuracion\Apariencia::urlLogo(); $nombreCliente = \App\Modules\Configuracion\Apariencia::nombre(); ?>
        <div class="auth-brand">
            <?php if ($logoUrl !== null): ?><img src="<?= e($logoUrl) ?>" alt="<?= e(\App\Modules\Configuracion\Apariencia::nombreSistema()) ?>" class="<?= \App\Modules\Configuracion\Apariencia::logoPlaca() ? 'brand-logo-placa' : '' ?>"><?php endif; ?>
            <?php if ($nombreCliente !== ''): ?><p class="auth-sub auth-cliente"><?= e($nombreCliente) ?></p>
            <?php elseif ($logoUrl === null): ?><p class="auth-sub">Factura<b>Web</b></p><?php endif; ?>
            <?php if (!\App\Modules\Configuracion\Apariencia::personalizada()): ?><p class="eyebrow">Organización &amp; Sistemas</p><?php endif; /* con marca propia, O&S firma al pie */ ?>
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
