<?php
/** @var string $host */
/** @var array<string,mixed> $datos */
/** @var array<string,string> $errores */
/** @var bool $tieneClaveMail */
/** @var string $rutaAfipSugerida */
$err = static fn (string $k): string => isset($errores[$k]) ? '<small class="field-error">' . e($errores[$k]) . '</small>' : '';
$v = static fn (string $k, string $def = ''): string => e((string) ($datos[$k] ?? $def));
?>
<p><a href="<?= e(url('/instancias')) ?>">← Instalaciones de clientes</a></p>
<div class="page-head"><h1><?= e($host) ?></h1></div>

<form method="post" action="<?= e(url('/instancias/editar')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="host" value="<?= e($host) ?>">
    <section class="card">
        <h2>ARCA y licencia</h2>
        <div class="grid-2">
            <label class="field"><span>Modo de ARCA</span>
                <select name="afip_modo"><?php foreach (['interno' => 'Interno (sin CAE)', 'simulado' => 'Simulado (prueba sin ARCA)', 'homologacion' => 'Homologación', 'produccion' => 'Producción'] as $k => $t): ?>
                    <option value="<?= $k ?>" <?= ($datos['afip_modo'] ?? 'interno') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select><?= $err('afip_modo') ?></label>
            <label class="field"><span>Ruta del config_afip.php del cliente</span><input name="afip_config" value="<?= $v('afip_config') ?>" placeholder="<?= e($rutaAfipSugerida) ?>"><?= $err('afip_config') ?>
                <small class="muted">Fuera de public. Sugerida: <code><?= e($rutaAfipSugerida) ?></code></small></label>
            <label class="field"><span>Licencia mensual</span>
                <span><input type="checkbox" name="licencia" value="1" <?= !empty($datos['licencia']) ? 'checked' : '' ?>> Exigir licencia</span></label>
        </div>
    </section>
    <section class="card">
        <h2>Mail para enviar facturas</h2>
        <div class="grid-2">
            <label class="field"><span>Servidor SMTP</span><input name="mail_host" value="<?= $v('mail_host') ?>"></label>
            <label class="field"><span>Puerto y seguridad</span>
                <span><input name="mail_port" value="<?= $v('mail_port', '587') ?>" inputmode="numeric" style="max-width:6em">
                <select name="mail_secure"><?php foreach (['tls' => 'TLS (587)', 'ssl' => 'SSL (465)'] as $k => $t): ?><option value="<?= $k ?>" <?= ($datos['mail_secure'] ?? 'tls') === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></span></label>
            <label class="field"><span>Usuario (casilla)</span><input name="mail_user" value="<?= $v('mail_user') ?>" autocomplete="off"></label>
            <label class="field"><span>Contraseña</span><input name="mail_pass" type="password" autocomplete="new-password" placeholder="<?= $tieneClaveMail ? '(sin cambios)' : '' ?>"></label>
            <label class="field"><span>Remitente</span><input name="mail_from" value="<?= $v('mail_from') ?>"></label>
            <label class="field"><span>Nombre del remitente</span><input name="mail_from_name" value="<?= $v('mail_from_name') ?>"></label>
        </div>
    </section>
    <button class="btn" type="submit">Guardar</button>
</form>
