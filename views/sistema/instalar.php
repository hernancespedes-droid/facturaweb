<?php
/** @var string|null $error */
/** @var array<string,string> $datos */
?>
<section class="auth">
    <div class="card auth-card">
        <h1>Instalación</h1>
        <p class="small muted">Crea las tablas y el usuario Master (O&amp;S). Se usa una sola vez por instalación.</p>
        <?php if ($error !== null): ?><div class="alerta alerta-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="<?= e(url('/instalar')) ?>" class="form">
            <?= csrf_field() ?>
            <label class="field"><span>Clave de instalación (instalar_token de config/local.php)</span>
                <input name="token" type="password" required autocomplete="off"></label>
            <label class="field"><span>Usuario Master</span>
                <input name="usuario" value="<?= e($datos['usuario'] ?? '') ?>" required autocapitalize="none"></label>
            <label class="field"><span>Nombre</span>
                <input name="nombre" value="<?= e($datos['nombre'] ?? '') ?>" required></label>
            <label class="field"><span>Contraseña</span>
                <input name="clave" type="password" required autocomplete="new-password" minlength="8"></label>
            <label class="field"><span>Repetila</span>
                <input name="confirmacion" type="password" required autocomplete="new-password"></label>
            <button class="btn btn-block" type="submit">Instalar</button>
        </form>
    </div>
</section>
