<?php
/** @var array<string,bool> $migraciones */
/** @var string[] $pendientes */
/** @var string $version */
/** @var array<string,string> $servidor */
/** @var array<int,array<string,mixed>> $migracionesVfp */
/** @var bool $puedeMigrar solo el Master aplica los cambios de base */
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Master y Administrador</p>
        <h1>Sistema</h1>
    </div>
    <span class="chip">Versión <?= e($version) ?></span>
</div>

<section class="card regenerar <?= $pendientes !== [] ? 'hay' : '' ?>">
    <div>
        <h2>Actualizar base de datos</h2>
        <?php if ($pendientes === []): ?>
            <p>La base está al día: no hay cambios pendientes.</p>
        <?php else: ?>
            <p>Hay <b><?= count($pendientes) ?></b> cambio(s) de base pendiente(s) de esta versión:</p>
            <ul class="small"><?php foreach ($pendientes as $p): ?><li><code><?= e($p) ?></code></li><?php endforeach; ?></ul>
            <p class="small muted">Antes de aplicar, hacé un backup: phpMyAdmin → Exportar (o las copias de seguridad del hosting). Los cambios de base no se deshacen solos.</p>
        <?php endif; ?>
    </div>
    <?php if ($puedeMigrar): ?>
        <form method="post" action="<?= e(url('/sistema/migrar')) ?>" data-confirmar="¿Ya hiciste el backup? Se van a aplicar los cambios de base pendientes.">
            <?= csrf_field() ?>
            <button class="btn" type="submit" <?= $pendientes === [] ? 'disabled' : '' ?>>Aplicar cambios</button>
        </form>
    <?php elseif ($pendientes !== []): ?>
        <p class="small muted">Los cambios de base los aplica el <b>Master</b> (O&amp;S).</p>
    <?php endif; ?>
</section>

<?php if (($errores ?? null) !== null): ?>
<section class="card" id="errores">
    <h2>Últimos errores</h2>
    <p class="small muted">Lo que el sistema registró en <code>storage/logs</code> (solo lo ve el Master). Sirve para saber qué pasó cuando alguien ve "Error 500".</p>
    <?php if ($errores === []): ?>
        <p>No hay errores registrados este mes ni el anterior.</p>
    <?php else: ?>
        <div class="table-wrap"><table class="table table-cards">
            <thead><tr><th>Fecha</th><th>Error</th><th>Dónde</th></tr></thead>
            <tbody>
            <?php foreach ($errores as $er): ?>
                <tr><td data-label="Fecha" class="nw"><?= e(date('d/m/Y H:i:s', (int) strtotime($er['fecha']))) ?></td>
                    <td data-label="Error"><b><?= e(basename(str_replace('\\', '/', $er['tipo']))) ?></b> <?= e($er['mensaje']) ?></td>
                    <td data-label="Dónde" class="small"><code><?= e($er['donde']) ?></code></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="card" id="migracion-vfp">
    <h2>Migración desde el sistema VFP</h2>
    <p class="small muted">Los datos del sistema de escritorio (DBF) se pasan con <code>php bin/migrar-dbf.php &lt;carpeta de los DBF&gt;</code>
        o, sin SSH, subiendo acá un .zip con los DBF (solo el Master). Ver <code>docs/migracion-dbf.md</code>.</p>
    <?php if ($puedeMigrar): ?>
        <form method="post" action="<?= e(url('/sistema/migrar-vfp')) ?>" enctype="multipart/form-data" class="toolbar"
              data-confirmar="¿Hiciste el backup de la base? La migración carga los datos del VFP en las tablas de esta instalación.">
            <?= csrf_field() ?>
            <label class="field"><span>.zip con los DBF y datos.dbc</span><input type="file" name="zip" accept=".zip" required></label>
            <label class="check"><input type="checkbox" name="simular" value="1" checked> Simular (no graba: muestra qué pasaría)</label>
            <label class="check"><input type="checkbox" name="reemplazar" value="1"> Reemplazar lo que ya esté cargado</label>
            <button class="btn" type="submit">Migrar</button>
        </form>
    <?php endif; ?>
    <?php if ($migracionesVfp === []): ?>
        <p>Todavía no se migró nada en esta instalación.</p>
    <?php else: ?>
        <div class="table-wrap"><table class="table table-cards">
            <thead><tr><th>Fecha</th><th>Tablas</th><th>Resultado</th></tr></thead>
            <tbody>
            <?php foreach ($migracionesVfp as $m): ?>
                <tr><td data-label="Fecha" class="nw"><?= e(date('d/m/Y H:i', (int) strtotime((string) $m['fecha']))) ?></td>
                    <td data-label="Tablas" class="small"><?= e($m['tablas']) ?></td>
                    <td data-label="Resultado" class="small">
                        <?php foreach ($m['resumen'] as $tabla => $r): ?>
                            <div><b><?= e($tabla) ?></b>: <?= (int) ($r['grabadas'] ?? 0) ?> de <?= (int) ($r['leidas'] ?? 0) ?>
                                <?php if (!empty($r['avisos'])): ?>
                                    <details><summary><span class="badge badge-warn"><?= count($r['avisos']) ?> aviso(s)</span></summary>
                                        <ul class="small"><?php foreach ($r['avisos'] as $a): ?><li><?= e($a) ?></li><?php endforeach; ?></ul>
                                    </details>
                                <?php endif; ?></div>
                        <?php endforeach; ?>
                    </td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<div class="grid-2">
    <section class="card">
        <h2>Servidor</h2>
        <dl class="datos datos-col">
            <?php foreach ($servidor as $k => $v): ?>
                <div><dt><?= e($k) ?></dt><dd><?= e($v) ?></dd></div>
            <?php endforeach; ?>
        </dl>
    </section>
    <section class="card">
        <h2>Cambios de base aplicados</h2>
        <ul class="list small">
            <?php foreach (array_reverse($migraciones, true) as $nombre => $aplicada): ?>
                <li><code><?= e($nombre) ?></code> <?= $aplicada ? '<span class="badge badge-ok">Aplicada</span>' : '<span class="badge badge-warn">Pendiente</span>' ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>
