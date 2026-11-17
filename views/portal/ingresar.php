<?php /** @var bool $enviado @var bool $mailListo @var ?string $error @var string $empresa */ ?>
<section class="card portal-card">
    <h1>Mis comprobantes</h1>
    <?php if ($enviado): ?>
        <p class="portal-ok">📩 <b>Revisá tu correo.</b></p>
        <p>Si <b><?= e($mail ?? '') ?></b> está registrado como mail de un cliente de <?= e($empresa) ?>, te llegó un link para entrar. Vale <?= \App\Modules\Portal\PortalService::MINUTOS ?> minutos y una sola vez.</p>
        <p class="small muted">¿No llegó? Mirá en «Correo no deseado» o <a href="<?= e(url('/portal')) ?>">pedilo de nuevo</a>.</p>
    <?php else: ?>
        <p>Ingresá el mail donde recibís las facturas y te mandamos un link para entrar. <b>Sin contraseñas.</b></p>
        <?php if ($error): ?><div class="alerta alerta-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <?php if (!$mailListo): ?><div class="alerta alerta-error" role="alert">El portal todavía no está disponible. Consultá con <?= e($empresa) ?>.</div><?php endif; ?>
        <form method="post" action="<?= e(url('/portal')) ?>"><?= csrf_field() ?>
            <label class="field"><span>Tu mail</span><input type="email" name="email" required autocomplete="email" inputmode="email" autofocus placeholder="nombre@empresa.com"></label>
            <button class="btn btn-ancho" type="submit" <?= $mailListo ? '' : 'disabled' ?>>Enviarme el link</button>
        </form>
    <?php endif; ?>
</section>
