<?php
/** @var array<int,array<string,mixed>> $instancias */
/** @var string[] $principal */
/** @var string $hostActual */
/** @var array<string,string>|null $resultado */
/** @var array{version:string,commit:?string,rama:?string} $master */
/** @var array{version:string,commit:?string,rama:?string} $clientes */
/** @var bool $dosCarpetas */
/** @var string $carpetaClientes */
/** @var bool $hayToken */
/** @var string $rama */
/** @var string[] $enCarpetaMaster */
/** @var array<int,array<string,mixed>> $bajas */
$corto = static fn (?string $c): string => $c !== null ? substr($c, 0, 7) : '?';
$badgeSus = ['activa' => 'badge-ok', 'aviso' => 'badge-warn', 'sin_emision' => 'badge-off', 'bloqueada' => 'badge-off'];
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Sitio master de O&amp;S</p>
        <h1>Instalaciones de clientes</h1>
    </div>
    <div class="acciones">
        <a class="btn" href="<?= e(url('/instancias/nueva')) ?>">+ Nuevo cliente</a>
    </div>
</div>

<section class="card">
    <h2>Versiones</h2>
    <?php if ($dosCarpetas): ?>
        <p>Master: <b><?= e($master['version']) ?></b> <span class="muted small">(rama <?= e((string) $master['rama']) ?>, <?= e($corto($master['commit'])) ?>)</span>
            · Clientes: <b><?= e($clientes['version']) ?></b> <span class="muted small">(rama <?= e((string) $clientes['rama']) ?>, <?= e($corto($clientes['commit'])) ?>)</span></p>
        <?php if ($master['commit'] !== null && $master['commit'] === $clientes['commit']): ?>
            <p class="small"><span class="badge badge-ok">Los clientes tienen lo mismo que el master</span></p>
        <?php else: ?>
            <p class="small">El master tiene una versión que los clientes todavía no tienen. Cuando la hayas probado acá:</p>
            <ol class="small">
                <li><?php if ($hayToken): ?>
                    <form method="post" action="<?= e(url('/instancias/pasar-a-clientes')) ?>" style="display:inline" data-confirmar="¿Pasás a los clientes la versión <?= e($master['version']) ?> (<?= e($corto($master['commit'])) ?>) que está corriendo el master?">
                        <?= csrf_field() ?><button class="btn btn-sm" type="submit">Pasar a clientes</button></form>
                    <?php else: ?>Pasar el commit <code><?= e($corto($master['commit'])) ?></code> a la rama <b><?= e($rama) ?></b> (pedíselo a Code, o cargá el token de GitHub para hacerlo con un botón).<?php endif; ?></li>
                <li>En el hosting: GIT → la carpeta de clientes → actualizar.</li>
                <li>Los cambios de base se aplican solos con el primer ingreso de cada cliente (o acá: <b>Aplicar cambios en todas</b>, para no esperar).</li>
            </ol>
        <?php endif; ?>
    <?php else: ?>
        <p>Versión <b><?= e($master['version']) ?></b>. <span class="small muted">Hoy el master y los clientes usan la <b>misma carpeta</b>: cada actualización llega a todos juntos.
            Para probar primero en el master, armá la carpeta de clientes (ver <code>docs/alta-de-cliente.md</code>, «Dos carpetas»).</span></p>
    <?php endif; ?>
</section>

<?php if ($enCarpetaMaster !== []): ?>
<section class="card regenerar hay">
    <div><h2>Clientes en la carpeta del master</h2>
        <p class="small"><?= e(implode(', ', $enCarpetaMaster)) ?> se crearon antes de separar las carpetas. Pasalos a la carpeta de clientes y después apuntá su subdominio a <code><?= e($carpetaClientes) ?>/public</code>.</p></div>
    <form method="post" action="<?= e(url('/instancias/mover')) ?>"><?= csrf_field() ?><button class="btn" type="submit">Pasar a la carpeta de clientes</button></form>
</section>
<?php endif; ?>

<?php if ($resultado !== null): ?>
<section class="card">
    <h2>Resultado de la actualización</h2>
    <ul class="small"><?php foreach ($resultado as $h => $txt): ?>
        <li><b><?= e($h) ?></b>: <?= str_starts_with($txt, 'ERROR') ? '<span class="badge badge-off">' . e($txt) . '</span>' : e($txt) ?></li>
    <?php endforeach; ?></ul>
</section>
<?php endif; ?>

<?php if ($instancias === []): ?>
    <section class="card">
        <h2>Todavía no hay clientes</h2>
        <p>Tocá <b>+ Nuevo cliente</b>. Antes, en el panel del hosting, creá una <b>base de datos vacía</b> con su usuario.</p>
    </section>
<?php else: ?>
    <div class="table-wrap">
        <table class="table table-cards" data-ordenable>
            <thead><tr><th>Cliente</th><th>Dirección</th><th>CUIT</th><th>Suscripción</th><th>Base</th><th>Licencia vence</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($instancias as $i): ?>
                <tr>
                    <td data-label="Cliente"><b><?= e($i['nombre']) ?></b><?php if ($i['cliente'] > 0): ?><br><small><a href="<?= e(url('/clientes/' . $i['cliente'])) ?>">Cliente <?= (int) $i['cliente'] ?></a>
                        · <a href="<?= e(url('/comprobantes/nuevo?tipo=FC&cliente=' . $i['cliente'])) ?>">Facturar</a></small><?php else: ?><br><small class="muted">Sin cliente en el master</small><?php endif; ?></td>
                    <td data-label="Dirección"><a href="https://<?= e($i['host']) ?>" target="_blank" rel="noopener"><?= e($i['host']) ?></a></td>
                    <td data-label="CUIT"><?= e($i['cuit']) ?></td>
                    <td data-label="Suscripción"><span class="badge <?= $badgeSus[$i['suscripcion']] ?? '' ?>"><?= e(\App\Modules\Instancias\Suscripcion::ESTADOS[$i['suscripcion']] ?? $i['suscripcion']) ?></span></td>
                    <td data-label="Base">
                        <?php if ($i['conecta'] === false): ?><span class="badge badge-off" title="<?= e((string) $i['error']) ?>">No conecta</span>
                        <?php elseif ((int) $i['pendientes'] > 0): ?><span class="badge badge-warn"><?= (int) $i['pendientes'] ?> cambio(s) pendiente(s)</span>
                        <?php else: ?><span class="badge badge-ok">Al día</span><?php endif; ?>
                    </td>
                    <td data-label="Licencia vence"><?= $i['licencia_vence'] !== '' ? e(date('d/m/Y', strtotime($i['licencia_vence']))) : '<span class="muted">—</span>' ?></td>
                    <td><a class="btn btn-ghost btn-sm" href="<?= e(url('/instancias/editar?host=' . rawurlencode($i['host']))) ?>">Administrar</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<section class="card">
    <h2>Actualizar todas</h2>
    <p class="small">1) Hacé un backup de las bases. 2) Actualizá el código con GIT. 3) Tocá el botón: aplica los cambios de base en el master y en cada cliente (cada uno con la versión de su carpeta).</p>
    <form method="post" action="<?= e(url('/instancias/actualizar')) ?>" data-confirmar="¿Ya hiciste el backup de las bases? Se aplican los cambios de base en todas las instalaciones.">
        <?= csrf_field() ?>
        <button class="btn" type="submit">Aplicar cambios en todas</button>
    </form>
</section>

<section class="card">
    <h2>Direcciones del sitio master</h2>
    <p class="small muted">Una vez que hay clientes, el master solo responde en estas direcciones; cualquier otro subdominio sin cliente muestra «no habilitada» (así nunca se ven estos datos desde otra dirección).
        Si entrás al master por otra dirección más, agregala acá.</p>
    <form method="post" action="<?= e(url('/instancias/principal')) ?>" class="form">
        <?= csrf_field() ?>
        <label class="field"><span>Una por línea</span>
            <textarea name="hosts" rows="3"><?= e(implode("\n", $principal !== [] ? $principal : [$hostActual])) ?></textarea></label>
        <button class="btn btn-ghost" type="submit">Guardar direcciones</button>
    </form>
</section>

<?php if ($bajas !== []): ?>
<details class="card">
    <summary><b>Clientes dados de baja (<?= count($bajas) ?>)</b></summary>
    <ul class="small"><?php foreach (array_reverse($bajas) as $b): ?>
        <li><?= e((string) $b['nombre']) ?> (<?= e((string) $b['host']) ?>, CUIT <?= e((string) $b['cuit']) ?>) — baja <?= e((string) $b['baja']) ?> por <?= e((string) $b['por']) ?></li>
    <?php endforeach; ?></ul>
</details>
<?php endif; ?>
