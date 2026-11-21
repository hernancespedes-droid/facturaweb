<?php
/** @var array<string,string> $d @var array<string,string> $errores @var bool $configurado */
use App\Modules\Soporte\SoporteService;
$err = static fn (string $c) => isset($errores[$c]) ? '<span class="field-error">' . e($errores[$c]) . '</span>' : '';
?>
<div class="page-head"><div><p class="eyebrow"><a href="<?= e(url('/soporte')) ?>">Soporte</a></p><h1>Nuevo ticket</h1></div></div>
<?php if (!$configurado): ?><div class="alerta alerta-error" role="alert">Esta instalación todavía no está conectada con O&amp;S para soporte. Avisanos por teléfono o mail.</div><?php endif; ?>
<?php if (isset($errores['general'])): ?><div class="alerta alerta-error" role="alert"><?= e($errores['general']) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('/soporte/nuevo')) ?>" enctype="multipart/form-data" class="card form-grid"><?= csrf_field() ?>
    <input type="hidden" name="operacion" value="<?= e($d['operacion'] ?? '') ?>">
    <input type="hidden" name="pantalla" value="<?= e($d['pantalla'] ?? '') ?>">
    <label class="field field-ancho"><span>Asunto *</span><input name="asunto" maxlength="150" required value="<?= e($d['asunto'] ?? '') ?>" placeholder="Ej.: no me deja emitir una factura B"><?= $err('asunto') ?></label>
    <label class="field"><span>Tipo *</span><select name="tipo" required>
        <?php foreach (SoporteService::TIPOS as $k => $t): ?><option value="<?= e($k) ?>" <?= ($d['tipo'] ?? '') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select><?= $err('tipo') ?></label>
    <label class="field"><span>Prioridad</span><select name="prioridad">
        <?php foreach (SoporteService::PRIORIDADES as $k => $t): ?><option value="<?= e($k) ?>" <?= ($d['prioridad'] ?? '') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
        <span class="small muted">Urgente: no podés facturar o trabajar.</span><?= $err('prioridad') ?></label>
    <label class="field field-ancho"><span>¿Qué pasó o qué necesitás? *</span><textarea name="descripcion" rows="7" required maxlength="20000"
        placeholder="Contanos qué estabas haciendo, qué esperabas que pasara y qué pasó (si apareció un mensaje, copialo)."><?= e($d['descripcion'] ?? '') ?></textarea><?= $err('descripcion') ?></label>
    <label class="field"><span>Captura o archivo (opcional)</span><input type="file" name="adjunto" accept="image/png,image/jpeg,image/webp,image/gif,application/pdf">
        <span class="small muted">Imagen o PDF, hasta 3 MB.</span><?= $err('adjunto') ?></label>
    <label class="field"><span>Mail para avisarte cuando respondan</span><input type="email" name="usuario_mail" maxlength="190" value="<?= e($d['usuario_mail'] ?? '') ?>"><?= $err('usuario_mail') ?></label>
    <?php if (($d['pantalla'] ?? '') !== ''): ?><p class="small muted field-ancho">Se manda también la pantalla donde estabas (<code><?= e($d['pantalla']) ?></code>), tu usuario y la versión del sistema.</p><?php endif; ?>
    <div class="acciones"><button class="btn" type="submit" <?= $configurado ? '' : 'disabled' ?>>Enviar a O&amp;S</button> <a class="btn btn-ghost" href="<?= e(url('/soporte')) ?>">Cancelar</a></div>
</form>
