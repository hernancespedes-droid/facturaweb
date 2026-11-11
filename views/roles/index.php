<?php
/** @var array<int,array<string,mixed>> $lista */
?>
<p><a href="<?= e(url('/usuarios')) ?>">← Usuarios</a></p>
<div class="page-head">
    <div>
        <h1>Roles</h1>
        <p class="muted small">Qué opciones del menú y qué acciones tiene cada rol. El rol de cada usuario se elige en Usuarios.</p>
    </div>
    <a class="btn" href="<?= e(url('/roles/nuevo')) ?>">+ Nuevo rol</a>
</div>
<div class="table-wrap">
    <table class="table table-cards" data-ordenable>
        <thead><tr><th>Rol</th><th>Tipo</th><th>Permisos</th><th>Usuarios</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($lista as $r): ?>
            <tr>
                <td data-label="Rol"><a href="<?= e(url('/roles/' . (int) $r['id'])) ?>"><b><?= e($r['nombre']) ?></b></a>
                    <?= (int) $r['es_sistema'] === 1 ? '<span class="small muted">del sistema</span>' : '' ?></td>
                <td data-label="Tipo"><?php if ((int) $r['es_master'] === 1): ?><span class="badge badge-warn">Master</span>
                    <?php elseif ((int) $r['es_admin'] === 1): ?><span class="badge badge-ok">Administrador</span>
                    <?php else: ?><span class="muted">—</span><?php endif; ?></td>
                <td data-label="Permisos"><?= (int) $r['permisos'] ?></td>
                <td data-label="Usuarios"><?= (int) $r['usuarios'] ?></td>
                <td data-label="" class="nw">
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('/roles/' . (int) $r['id'])) ?>">Editar</a>
                    <?php if ((int) $r['es_sistema'] === 0 && (int) $r['usuarios'] === 0): ?>
                        <form method="post" action="<?= e(url('/roles/' . (int) $r['id'] . '/borrar')) ?>" class="inline" data-confirmar="¿Borrar el rol <?= e($r['nombre']) ?>?"><?= csrf_field() ?>
                            <button class="btn btn-danger btn-sm" type="submit">Borrar</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
