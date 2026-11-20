<?php
/** @var array<int,array<string,mixed>> $instancias */
/** @var string[] $principal */
/** @var string $hostActual */
/** @var array<string,string>|null $resultado */
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Master · instalación principal</p>
        <h1>Instalaciones de clientes</h1>
    </div>
    <div class="acciones">
        <a class="btn" href="<?= e(url('/instancias/nueva')) ?>">+ Nuevo cliente</a>
    </div>
</div>

<p class="muted small">Cada cliente tiene su dirección, su base y sus datos, pero todos usan este mismo código: al actualizar con GIT se actualizan todos.
    Después de actualizar, tocá <b>Aplicar cambios en todas</b>.</p>

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
            <thead><tr><th>Cliente</th><th>Dirección</th><th>CUIT</th><th>Alta</th><th>Base</th><th>Licencia vence</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($instancias as $i): ?>
                <tr>
                    <td data-label="Cliente"><b><?= e($i['nombre']) ?></b></td>
                    <td data-label="Dirección"><a href="https://<?= e($i['host']) ?>" target="_blank" rel="noopener"><?= e($i['host']) ?></a></td>
                    <td data-label="CUIT"><?= e($i['cuit']) ?></td>
                    <td data-label="Alta"><?= e($i['alta']) ?></td>
                    <td data-label="Base">
                        <?php if ($i['conecta'] === false): ?><span class="badge badge-off" title="<?= e((string) $i['error']) ?>">No conecta</span>
                        <?php elseif ((int) $i['pendientes'] > 0): ?><span class="badge badge-warn"><?= (int) $i['pendientes'] ?> cambio(s) pendiente(s)</span>
                        <?php else: ?><span class="badge badge-ok">Al día</span><?php endif; ?>
                    </td>
                    <td data-label="Licencia vence"><?= $i['licencia_vence'] !== '' ? e(date('d/m/Y', strtotime($i['licencia_vence']))) : '<span class="muted">—</span>' ?></td>
                    <td><a class="btn btn-ghost btn-sm" href="<?= e(url('/instancias/editar?host=' . rawurlencode($i['host']))) ?>">Mail, ARCA y licencia</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<section class="card">
    <h2>Actualizar todas</h2>
    <p class="small">1) Hacé un backup de las bases. 2) Actualizá el código con GIT (una sola vez, es el mismo para todos). 3) Tocá el botón: aplica los cambios de base en la principal y en cada cliente.</p>
    <form method="post" action="<?= e(url('/instancias/actualizar')) ?>" data-confirmar="¿Ya hiciste el backup de las bases? Se aplican los cambios de base en todas las instalaciones.">
        <?= csrf_field() ?>
        <button class="btn" type="submit">Aplicar cambios en todas</button>
    </form>
</section>

<section class="card">
    <h2>Direcciones de esta instalación (la principal)</h2>
    <p class="small muted">Una vez que hay clientes, esta instalación solo responde en estas direcciones; cualquier otro subdominio sin cliente muestra «no habilitada» (así nunca se ven estos datos desde otra dirección).
        Si entrás a la principal por otra dirección más, agregala acá.</p>
    <form method="post" action="<?= e(url('/instancias/principal')) ?>" class="form">
        <?= csrf_field() ?>
        <label class="field"><span>Una por línea</span>
            <textarea name="hosts" rows="3"><?= e(implode("\n", $principal !== [] ? $principal : [$hostActual])) ?></textarea></label>
        <button class="btn btn-ghost" type="submit">Guardar direcciones</button>
    </form>
</section>
