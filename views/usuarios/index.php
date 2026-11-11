<?php
/** @var array<int,array<string,mixed>> $usuarios */
/** @var string $q */
?>
<div class="page-head">
    <h1>Usuarios</h1>
    <div class="acciones">
        <a class="btn btn-ghost" href="<?= e(url('/roles')) ?>">Roles</a>
        <a class="btn" href="<?= e(url('/usuarios/nuevo')) ?>">+ Nuevo usuario</a>
    </div>
</div>

<form method="get" action="<?= e(url('/usuarios')) ?>" class="toolbar">
    <label class="sr-only" for="q">Buscar</label>
    <input id="q" name="q" value="<?= e($q) ?>" placeholder="Buscar por usuario, nombre o email" type="search">
    <button class="btn btn-ghost" type="submit">Buscar</button>
</form>

<?php if ($usuarios === []): ?>
    <p class="muted">No hay usuarios para mostrar.</p>
<?php else: ?>
    <div class="table-wrap">
        <table class="table table-cards" data-ordenable>
            <thead>
            <tr>
                <th>Usuario</th>
                <th>Nombre</th>
                <th>Rol</th>
                <th>Estado</th>
                <th>Último ingreso</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($usuarios as $u): ?>
                <tr>
                    <td data-label="Usuario"><a href="<?= e(url('/usuarios/' . $u['id'])) ?>"><b><?= e($u['usuario']) ?></b></a></td>
                    <td data-label="Nombre"><?= e($u['nombre']) ?><?= $u['usuario_vfp'] ? ' <span class="badge" title="Usuario traído del sistema VFP">VFP</span>' : '' ?><?php if ($u['email']): ?><br><small class="muted"><?= e($u['email']) ?></small><?php endif; ?></td>
                    <td data-label="Rol"><?= e($u['rol_nombre']) ?></td>
                    <td data-label="Estado">
                        <?php if ((int) $u['activo'] !== 1): ?><span class="badge badge-off">Inactivo</span>
                        <?php elseif ((int) $u['bloqueado'] === 1): ?><span class="badge badge-warn">Bloqueado</span>
                        <?php else: ?><span class="badge badge-ok">Activo</span><?php endif; ?>
                    </td>
                    <td data-label="Último ingreso"><?= $u['ultimo_login'] ? e(date('d/m/Y H:i', strtotime((string) $u['ultimo_login']))) : '<span class="muted">Nunca</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
