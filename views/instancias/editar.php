<?php
/** @var string $host */
/** @var array<string,mixed> $datos */
/** @var array<string,string> $errores */
/** @var bool $tieneClaveMail */
/** @var string $rutaAfipSugerida */
/** @var array<string,mixed> $info */
use App\Modules\Instancias\Suscripcion;

$err = static fn (string $k): string => isset($errores[$k]) ? '<small class="field-error">' . e($errores[$k]) . '</small>' : '';
$v = static fn (string $k, string $def = ''): string => e((string) ($datos[$k] ?? $def));
$cliente = (int) ($info['cliente'] ?? 0);
?>
<p><a href="<?= e(url('/instancias')) ?>">← Instalaciones de clientes</a></p>
<div class="page-head">
    <div>
        <p class="eyebrow"><?= e((string) ($info['nombre'] ?? '')) ?> · CUIT <?= e((string) ($info['cuit'] ?? '')) ?></p>
        <h1><?= e($host) ?></h1>
    </div>
    <div class="acciones">
        <a class="btn btn-ghost" href="<?= e(url('/instancias/usuarios?host=' . rawurlencode($host))) ?>">Usuarios y contraseñas</a>
        <?php if ($cliente > 0): ?>
            <a class="btn btn-ghost" href="<?= e(url('/clientes/' . $cliente)) ?>">Cliente <?= $cliente ?></a>
            <a class="btn" href="<?= e(url('/comprobantes/nuevo?tipo=FC&cliente=' . $cliente)) ?>">Facturarle</a>
        <?php else: ?>
            <form method="post" action="<?= e(url('/instancias/cliente')) ?>"><?= csrf_field() ?><input type="hidden" name="host" value="<?= e($host) ?>">
                <button class="btn" type="submit">Crear como cliente del master</button></form>
        <?php endif; ?>
    </div>
</div>

<?php if ($errores !== []): ?><div class="alerta alerta-error" role="alert">Revisá los campos marcados.</div><?php endif; ?>

<form method="post" action="<?= e(url('/instancias/editar')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="host" value="<?= e($host) ?>">
    <section class="card">
        <h2>Suscripción</h2>
        <?php if (isset($datos['efectivo'])): ?><p class="small">Hoy está: <b><?= e(Suscripcion::ESTADOS[$datos['efectivo']] ?? '') ?></b></p><?php endif; ?>
        <div class="grid-2">
            <label class="field"><span>Estado</span>
                <select name="suscripcion"><?php foreach (Suscripcion::ESTADOS as $k => $t): ?>
                    <option value="<?= $k ?>" <?= ($datos['suscripcion'] ?? 'activa') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select><?= $err('suscripcion') ?>
                <small class="muted">Aviso: cartel en todas las pantallas. Sin emitir: consultan e imprimen pero no facturan. Bloqueada: solo entra el Master de O&amp;S.</small></label>
            <label class="field"><span>Mensaje para el cliente (opcional)</span>
                <input name="suscripcion_mensaje" value="<?= $v('suscripcion_mensaje') ?>" maxlength="300" placeholder="Tu suscripción tiene un pago pendiente…"></label>
            <label class="field"><span>Pasa solo a «sin emitir» el</span><input type="date" name="sin_emision_desde" value="<?= $v('sin_emision_desde') ?>"><?= $err('sin_emision_desde') ?></label>
            <label class="field"><span>Pasa solo a «bloqueada» el</span><input type="date" name="bloqueo_desde" value="<?= $v('bloqueo_desde') ?>"><?= $err('bloqueo_desde') ?></label>
        </div>
        <p class="small muted">Para reactivar: estado «Activa» y fechas vacías.</p>
    </section>

    <section class="card">
        <h2>Módulos</h2>
        <input type="hidden" name="modulos_enviados" value="1">
        <div class="grid-2">
        <?php foreach (\App\Core\Modulos::LISTA as $k => $m): ?>
            <label><input type="checkbox" name="modulos[<?= e($k) ?>]" value="1" <?= !empty($datos['modulos'][$k]) ? 'checked' : '' ?>> <?= e($m['nombre']) ?></label>
        <?php endforeach; ?>
        </div>
    </section>

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

<section class="card">
    <h2>Copia de los datos</h2>
    <p class="small">Toda la base del cliente en un archivo <code>.sql.gz</code> (para entregársela o guardarla antes de la baja).</p>
    <a class="btn btn-ghost" href="<?= e(url('/instancias/copia?host=' . rawurlencode($host))) ?>">Descargar copia</a>
</section>

<section class="card">
    <h2>Dar de baja</h2>
    <p class="small"><b>Borra todos los datos del cliente</b> (las tablas de su base y su carpeta: configuración, licencia, logs). No se puede deshacer.
        Antes bajá la copia: los comprobantes fiscales se tienen que conservar y conviene entregársela al cliente.</p>
    <form method="post" action="<?= e(url('/instancias/baja')) ?>" class="form" data-confirmar="Se borran TODOS los datos de <?= e($host) ?>. ¿Seguro?">
        <?= csrf_field() ?>
        <input type="hidden" name="host" value="<?= e($host) ?>">
        <label class="field"><span>Para confirmar, escribí <b><?= e($host) ?></b></span><input name="confirmacion" autocomplete="off" required></label>
        <button class="btn btn-danger" type="submit">Dar de baja y borrar los datos</button>
    </form>
</section>
