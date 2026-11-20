<?php
/** @var array<string,mixed> $datos */
/** @var array<string,string> $errores */
/** @var string $dominio */
$err = static fn (string $k): string => isset($errores[$k]) ? '<small class="field-error">' . e($errores[$k]) . '</small>' : '';
$v = static fn (string $k, string $def = ''): string => e((string) ($datos[$k] ?? $def));
?>
<p><a href="<?= e(url('/instancias')) ?>">← Instalaciones de clientes</a></p>
<div class="page-head"><h1>Nuevo cliente</h1></div>

<?php if ($errores !== []): ?><div class="alerta alerta-error" role="alert">Revisá los campos marcados.</div><?php endif; ?>

<form method="post" action="<?= e(url('/instancias/nueva')) ?>" class="form" data-confirmar="¿Creás la instalación? Se crean las tablas en la base indicada.">
    <?= csrf_field() ?>
    <section class="card">
        <h2>1. La empresa</h2>
        <div class="grid-2">
            <label class="field"><span>Razón social</span><input name="nombre" value="<?= $v('nombre') ?>" required maxlength="120"><?= $err('nombre') ?></label>
            <label class="field"><span>CUIT</span><input name="cuit" value="<?= $v('cuit') ?>" required inputmode="numeric" placeholder="30-12345678-9"><?= $err('cuit') ?></label>
            <label class="field"><span>Condición de IVA</span>
                <select name="iva"><?php foreach (\App\Modules\Instancias\InstanciasService::IVA as $cod => $txt): ?>
                    <option value="<?= $cod ?>" <?= (string) ($datos['iva'] ?? '1') === (string) $cod ? 'selected' : '' ?>><?= e($txt) ?></option>
                <?php endforeach; ?></select><?= $err('iva') ?></label>
            <label class="field"><span>Licencia mensual</span>
                <span><input type="checkbox" name="licencia" value="1" <?= !empty($datos['licencia']) ? 'checked' : '' ?>> Exigir licencia (suscripción)</span></label>
            <label class="field"><span>Domicilio</span><input name="domicilio" value="<?= $v('domicilio') ?>" maxlength="80"></label>
            <label class="field"><span>Localidad</span><input name="localidad" value="<?= $v('localidad') ?>" maxlength="40"></label>
            <label class="field"><span>Teléfono</span><input name="telefono" value="<?= $v('telefono') ?>" maxlength="60"></label>
            <label class="field"><span>Mail para mandarle las facturas de O&amp;S</span><input name="email" type="email" value="<?= $v('email') ?>"><?= $err('email') ?></label>
        </div>
        <p class="small muted">Con estos datos también se crea como <b>cliente del master</b>, para facturarle la suscripción (si ya hay uno con ese CUIT, se usa ese).</p>
    </section>

    <section class="card">
        <h2>Módulos que contrata</h2>
        <input type="hidden" name="modulos_enviados" value="1">
        <div class="grid-2">
        <?php foreach (\App\Core\Modulos::LISTA as $k => $m): ?>
            <label><input type="checkbox" name="modulos[<?= e($k) ?>]" value="1" <?= !empty($datos['modulos'][$k]) ? 'checked' : '' ?>> <?= e($m['nombre']) ?></label>
        <?php endforeach; ?>
        </div>
        <p class="small muted">Facturación, clientes, artículos, tablas y ARCA van siempre. Se cambian después desde «Administrar».</p>
    </section>

    <section class="card">
        <h2>2. La dirección</h2>
        <div class="grid-2">
            <label class="field"><span>Subdominio</span><input name="subdominio" value="<?= $v('subdominio') ?>" required autocapitalize="none" placeholder="gogolinas" pattern="[A-Za-z0-9\-]{1,40}"><?= $err('subdominio') ?></label>
            <label class="field"><span>Dominio</span><input name="dominio" value="<?= $v('dominio', $dominio) ?>" required autocapitalize="none"><?= $err('dominio') ?></label>
        </div>
        <p class="small muted">Queda <b>subdominio.dominio</b> (ej. gogolinas.<?= e($dominio) ?>).</p>
    </section>

    <section class="card">
        <h2>3. La base de datos</h2>
        <p class="small muted">Creala antes en el panel del hosting (Bases de datos → nueva, con su usuario). Tiene que estar <b>vacía</b>.</p>
        <div class="grid-2">
            <label class="field"><span>Servidor</span><input name="db_host" value="<?= $v('db_host', 'localhost') ?>" required><?= $err('db_host') ?></label>
            <label class="field"><span>Nombre de la base</span><input name="db_name" value="<?= $v('db_name') ?>" required autocapitalize="none"><?= $err('db_name') ?></label>
            <label class="field"><span>Usuario de la base</span><input name="db_user" value="<?= $v('db_user') ?>" required autocapitalize="none" autocomplete="off"></label>
            <label class="field"><span>Contraseña de la base</span><input name="db_pass" type="password" autocomplete="new-password"></label>
        </div>
    </section>

    <section class="card">
        <h2>4. Usuarios</h2>
        <div class="grid-2">
            <label class="field"><span>Usuario Master (O&amp;S)</span><input name="master_usuario" value="<?= $v('master_usuario') ?>" required autocapitalize="none"><?= $err('master_usuario') ?></label>
            <label class="field"><span>Nombre del Master</span><input name="master_nombre" value="<?= $v('master_nombre') ?>"></label>
            <label class="field"><span>Contraseña del Master</span><input name="master_clave" type="password" required minlength="8" autocomplete="new-password"><?= $err('master_clave') ?></label>
            <label class="field"><span>Repetila</span><input name="master_confirmacion" type="password" required autocomplete="new-password"></label>
            <label class="field"><span>Usuario Administrador del cliente</span><input name="admin_usuario" value="<?= $v('admin_usuario') ?>" required autocapitalize="none"><?= $err('admin_usuario') ?>
                <small class="muted">Se crea con una contraseña provisoria que te muestro al terminar; la cambia en el primer ingreso.</small></label>
            <label class="field"><span>Nombre del Administrador</span><input name="admin_nombre" value="<?= $v('admin_nombre') ?>" placeholder="(la razón social)"></label>
        </div>
    </section>

    <details class="card">
        <summary><b>5. Mail para enviar facturas (opcional, se puede cargar después)</b></summary>
        <div class="grid-2">
            <label class="field"><span>Servidor SMTP</span><input name="mail_host" value="<?= $v('mail_host') ?>" placeholder="mail.cliente.com.ar"><?= $err('mail_host') ?></label>
            <label class="field"><span>Puerto y seguridad</span>
                <span><input name="mail_port" value="<?= $v('mail_port', '587') ?>" inputmode="numeric" style="max-width:6em">
                <select name="mail_secure"><?php foreach (['tls' => 'TLS (587)', 'ssl' => 'SSL (465)'] as $k => $t): ?><option value="<?= $k ?>" <?= ($datos['mail_secure'] ?? 'tls') === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></span></label>
            <label class="field"><span>Usuario (casilla)</span><input name="mail_user" value="<?= $v('mail_user') ?>" autocomplete="off"></label>
            <label class="field"><span>Contraseña de la casilla</span><input name="mail_pass" type="password" autocomplete="new-password"></label>
            <label class="field"><span>Remitente</span><input name="mail_from" value="<?= $v('mail_from') ?>" placeholder="facturas@cliente.com.ar"></label>
            <label class="field"><span>Nombre del remitente</span><input name="mail_from_name" value="<?= $v('mail_from_name') ?>"></label>
        </div>
    </details>

    <button class="btn" type="submit">Crear instalación</button>
</form>
