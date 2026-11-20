<?php
/** @var array<int,array<string,mixed>> $filas */
/** @var string $propia versión de esta instalación */
/** @var string $nueva la versión más nueva que se ve */
/** @var ?string $error */
$u = url('/sistema/instalaciones');
?>
<div class="page-head">
    <div><p class="eyebrow">Master</p><h1>Instalaciones</h1></div>
    <span class="chip">Esta instalación: versión <?= e($propia) ?></span>
</div>
<p class="muted small">Una fila por cliente. «Revisar todas» le pregunta a cada instalación su <code>/salud</code> (versión, cambios de base pendientes y días de licencia). No se guarda ninguna clave.</p>
<?php if ($error): ?><div class="alerta alerta-error" role="alert"><?= e($error) ?></div><?php endif; ?>

<form method="post" action="<?= e($u . '/revisar') ?>" class="toolbar"><?= csrf_field() ?>
    <button class="btn" type="submit">Revisar todas</button>
</form>

<?php if ($filas === []): ?>
    <p class="muted vacio">Todavía no cargaste ninguna instalación. Agregá la primera abajo.</p>
<?php else: ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th>Cliente</th><th>Dirección</th><th>Versión</th><th>Base</th><th>Licencia</th><th>Revisada</th><th>Notas</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($filas as $f):
            $rev = $f['revisada_en'] !== null;
            $viejo = $rev && $f['version'] !== null && version_compare((string) $f['version'], $nueva, '<');
            $mal = $rev && (!(int) $f['ok'] || (int) ($f['pendientes'] ?? 0) > 0 || (int) ($f['migracion_error'] ?? 0) === 1);
            $lic = $f['licencia_dias']; ?>
            <tr>
                <td data-label="Cliente"><b><?= e($f['nombre']) ?></b></td>
                <td data-label="Dirección"><a href="<?= e($f['url']) ?>" target="_blank" rel="noopener"><?= e($f['url']) ?></a></td>
                <td data-label="Versión"><?php if (!$rev): ?>—<?php elseif ($f['version'] === null): ?><span class="texto-error">sin respuesta</span>
                    <?php else: ?><span class="<?= $viejo ? 'texto-error' : '' ?>"><?= e((string) $f['version']) ?></span> <?= $viejo ? '<span class="small texto-error">(desactualizada: la más nueva es ' . e($nueva) . ')</span>' : '<span class="small muted">al día</span>' ?><?php endif; ?></td>
                <td data-label="Base"><?php if (!$rev): ?>—<?php elseif ($mal): ?><span class="texto-error"><?= !(int) $f['ok'] ? e((string) ($f['error'] ?: 'No responde')) : ((int) ($f['migracion_error'] ?? 0) === 1 ? 'Falló la actualización automática' : (int) $f['pendientes'] . ' cambio(s) pendiente(s)') ?></span>
                    <?php else: ?>Al día<?php endif; ?></td>
                <td data-label="Licencia"><?php if (!$rev || $lic === null): ?>—<?php else: ?><span class="<?= (int) $lic <= 10 ? 'texto-error' : '' ?>"><?= (int) $lic >= 0 ? 'vence en ' . (int) $lic . ' día(s)' : 'vencida hace ' . abs((int) $lic) . ' día(s)' ?></span><?php endif; ?></td>
                <td data-label="Revisada" class="nw"><?= $rev ? e(date('d/m/Y H:i', (int) strtotime((string) $f['revisada_en']))) : 'nunca' ?></td>
                <td data-label="Notas"><?= e($f['notas']) ?></td>
                <td class="num"><form method="post" action="<?= e($u . '/' . (int) $f['id'] . '/borrar') ?>" onsubmit="return confirm('¿Quitar esta instalación de la lista? (No se borra nada del cliente.)');"><?= csrf_field() ?>
                    <button class="btn btn-ghost btn-sm" type="submit">Quitar</button></form></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>

<section class="card">
    <h2>Agregar una instalación</h2>
    <form method="post" action="<?= e($u) ?>" class="toolbar"><?= csrf_field() ?>
        <label class="field"><span>Cliente</span><input name="nombre" maxlength="80" required placeholder="Ej.: Gogolinas"></label>
        <label class="field" style="flex:1 1 20rem"><span>Dirección</span><input name="url" maxlength="200" required placeholder="https://facturador.cliente.com.ar"></label>
        <label class="field"><span>Notas</span><input name="notas" maxlength="200" placeholder="Opcional"></label>
        <button class="btn" type="submit">Agregar</button>
    </form>
</section>
