<?php
/** @var string|null $error */
use App\Modules\Auth\Auth;
use App\Modules\Auth\LoginService;

$temporal = (int) (Auth::usuario()['debe_cambiar_clave'] ?? 0) === 1;
?>
<section class="auth">
    <div class="card auth-card">
        <h1>Cambiar contraseña</h1>
        <?php if ($temporal): ?>
            <div class="alerta alerta-info">Estás usando una contraseña temporal. Elegí una nueva para seguir.</div>
        <?php endif; ?>
        <?php if ($error !== null): ?>
            <div class="alerta alerta-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/mi-clave')) ?>" class="form">
            <?= csrf_field() ?>
            <label class="field">
                <span><?= $temporal ? 'Contraseña temporal' : 'Contraseña actual' ?></span>
                <input name="actual" type="password" autocomplete="current-password" required>
            </label>
            <label class="field">
                <span>Contraseña nueva</span>
                <input name="nueva" type="password" autocomplete="new-password" minlength="<?= LoginService::MIN_LARGO_CLAVE ?>" required>
                <small class="muted">Al menos <?= LoginService::MIN_LARGO_CLAVE ?> caracteres.</small>
            </label>
            <label class="field">
                <span>Repetí la contraseña nueva</span>
                <input name="confirmacion" type="password" autocomplete="new-password" required>
            </label>
            <button class="btn btn-block" type="submit">Guardar</button>
        </form>
    </div>
</section>
