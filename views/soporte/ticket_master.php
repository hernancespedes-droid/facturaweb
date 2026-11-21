<?php
/** Ticket visto por O&S. @var array<string,mixed> $t */
use App\Modules\Soporte\SoporteService;
$id = (int) $t['id'];
$cliente = $t['instancia_nombre'] !== '' ? $t['instancia_nombre'] : ($t['instancia'] !== '' ? $t['instancia'] : 'Instalación principal');
?>
<div class="page-head"><div><p class="eyebrow"><a href="<?= e(url('/soporte/bandeja')) ?>">Tickets de soporte</a></p><h1>#<?= $id ?> · <?= e($t['asunto']) ?></h1></div></div>
<section class="card small soporte-datos">
    <p><?php $estado = (string) $t['estado']; $master = true; require __DIR__ . '/_estado.php'; ?>
        <?= $t['prioridad'] === 'urgente' ? '<span class="badge badge-off">Urgente</span>' : '' ?> · <?= e(SoporteService::TIPOS[$t['tipo']] ?? $t['tipo']) ?></p>
    <p><b>Cliente:</b> <?= e($cliente) ?><?= $t['instancia'] !== '' ? ' (<a href="https://' . e($t['instancia']) . '" target="_blank" rel="noopener">' . e($t['instancia']) . '</a>)' : '' ?>
        · <b>Usuario:</b> <?= e($t['usuario']) ?><?= $t['usuario_mail'] !== '' ? ' &lt;' . e($t['usuario_mail']) . '&gt;' : ' <span class="muted">(sin mail: no se le avisa)</span>' ?>
        · <b>Versión:</b> <?= e($t['version']) ?></p>
    <?php if ($t['pantalla'] !== ''): ?><p><b>Pantalla:</b> <code><?= e($t['pantalla']) ?></code></p><?php endif; ?>
    <?php if (($t['aviso_error'] ?? null) !== null): ?><p class="texto-error">Último mail de aviso que no salió: <?= e($t['aviso_error']) ?></p><?php endif; ?>
</section>
<?php $urlAdjunto = '/soporte/bandeja/adjunto/'; $master = true; require __DIR__ . '/_conversacion.php'; ?>
<form method="post" action="<?= e(url('/soporte/bandeja/' . $id . '/responder')) ?>" enctype="multipart/form-data" class="card form-grid"><?= csrf_field() ?>
    <label class="field field-ancho"><span>Respuesta o nota</span><textarea name="texto" rows="5" maxlength="20000"></textarea></label>
    <label class="field"><span>Adjunto (opcional)</span><input type="file" name="adjunto" accept="image/png,image/jpeg,image/webp,image/gif,application/pdf"></label>
    <label class="field"><span>Estado después de guardar</span><select name="estado">
        <option value="">Automático (respuesta → esperando al cliente)</option>
        <?php foreach (SoporteService::ESTADOS_MASTER as $k => $x): ?><option value="<?= e($k) ?>"><?= e($x) ?><?= $k === $t['estado'] ? ' (actual)' : '' ?></option><?php endforeach; ?></select></label>
    <div class="field"><label class="check"><input type="checkbox" name="interno" value="1"> Nota interna (el cliente no la ve y no se le avisa)</label></div>
    <div class="acciones"><button class="btn" type="submit">Guardar</button></div>
</form>
