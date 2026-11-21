<?php
/** Ticket visto por el cliente. @var array<string,mixed> $t */
use App\Modules\Soporte\SoporteService;
$id = (int) $t['id'];
$cerrado = in_array($t['estado'], ['resuelto', 'cerrado'], true);
?>
<div class="page-head"><div><p class="eyebrow"><a href="<?= e(url('/soporte')) ?>">Soporte</a></p><h1>#<?= $id ?> · <?= e($t['asunto']) ?></h1></div>
    <div class="actions"><?php if ($cerrado): ?>
        <form method="post" action="<?= e(url('/soporte/' . $id . '/estado')) ?>"><?= csrf_field() ?><input type="hidden" name="accion" value="reabrir"><button class="btn btn-ghost" type="submit">Reabrir</button></form>
    <?php endif; ?>
    <?php if ($t['estado'] !== 'cerrado'): ?>
        <form method="post" action="<?= e(url('/soporte/' . $id . '/estado')) ?>"><?= csrf_field() ?><input type="hidden" name="accion" value="cerrar"><button class="btn btn-ghost" type="submit">Cerrar ticket</button></form>
    <?php endif; ?></div></div>
<section class="card small soporte-datos">
    <p><?php $estado = (string) $t['estado']; $master = false; require __DIR__ . '/_estado.php'; ?>
        <?= $t['prioridad'] === 'urgente' ? '<span class="badge badge-off">Urgente</span>' : '' ?>
        · <?= e(SoporteService::TIPOS[$t['tipo']] ?? $t['tipo']) ?> · abierto por <?= e($t['usuario']) ?> el <?= e(date('d/m/Y H:i', (int) strtotime((string) $t['creado_en']))) ?></p>
    <?php if ($t['estado'] === 'resuelto'): ?><p class="muted">O&amp;S lo marcó resuelto. Si no contestás, se cierra solo a los <?= SoporteService::DIAS_CIERRE ?> días; si sigue el problema, respondé abajo.</p><?php endif; ?>
</section>
<?php $urlAdjunto = '/soporte/adjunto/'; $master = false; require __DIR__ . '/_conversacion.php'; ?>
<form method="post" action="<?= e(url('/soporte/' . $id . '/responder')) ?>" enctype="multipart/form-data" class="card form-grid"><?= csrf_field() ?>
    <label class="field field-ancho"><span><?= $t['estado'] === 'cerrado' ? 'Escribir de nuevo (reabre el ticket)' : 'Responder' ?></span><textarea name="texto" rows="4" maxlength="20000"></textarea></label>
    <label class="field"><span>Captura o archivo (opcional)</span><input type="file" name="adjunto" accept="image/png,image/jpeg,image/webp,image/gif,application/pdf"></label>
    <div class="acciones"><button class="btn" type="submit">Enviar</button></div>
</form>
