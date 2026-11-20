<?php
/** @var array<string,mixed> $r */
/** @var string $carpetaPublica */
?>
<div class="page-head"><h1>Listo: <?= e($r['host']) ?></h1></div>

<section class="card">
    <h2>Falta un solo paso, en el panel del hosting</h2>
    <ol>
        <li>Creá el subdominio <b><?= e($r['host']) ?></b> apuntando a <b>la misma carpeta que esta instalación</b>:<br>
            <code><?= e($carpetaPublica) ?></code><br>
            <small class="muted">(en Ferozo: Dominios → Subdominios → la carpeta del facturador, terminando en <code>/public</code>). Activá el SSL del subdominio.</small></li>
        <li>Entrá a <a href="https://<?= e($r['host']) ?>" target="_blank" rel="noopener">https://<?= e($r['host']) ?></a> con tu usuario Master.</li>
    </ol>
</section>

<section class="card regenerar hay">
    <div>
        <h2>Administrador del cliente</h2>
        <p>Usuario <b><?= e($r['admin_usuario']) ?></b> · contraseña provisoria <b><code><?= e($r['admin_clave']) ?></code></b></p>
        <p class="small muted">Anotala ahora: no se vuelve a mostrar (si se pierde, se resetea desde Usuarios en la instalación del cliente). La cambia en el primer ingreso.</p>
    </div>
</section>

<section class="card">
    <h2>Y después, dentro de la instalación del cliente</h2>
    <ul>
        <li><b>Tablas → Parámetros</b> y <b>Sucursales</b>: domicilio, inicio de actividades y el punto de venta con la numeración que sigue.</li>
        <li><b>Si viene del VFP</b>: Sistema → Migración desde el sistema VFP (subís un .zip con los DBF; primero «simular»).</li>
        <li><b>Licencia</b>: Sistema → Licencia (si la exigiste).</li>
        <li><b>ARCA</b>: certificado y punto de venta propios; el modo se cambia desde «Mail, ARCA y licencia» en el panel.</li>
    </ul>
    <p class="small muted">Base creada con <?= (int) $r['cambios'] ?> cambio(s) de estructura.</p>
</section>

<p><a class="btn btn-ghost" href="<?= e(url('/instancias')) ?>">Volver a instalaciones</a></p>
