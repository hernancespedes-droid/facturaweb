<?php
/** @var array<string,mixed> $estado @var ?array<string,mixed> $licencia @var bool $puedeCargar @var ?string $error */
$clase = ['ok' => 'ok', 'sin_control' => 'info', 'por_vencer' => 'info'][$estado['estado']] ?? 'error';
?>
<div class="page-head"><div><p class="eyebrow">Sistema</p><h1>Licencia de la suscripción</h1></div></div>
<?php if ($error): ?><div class="alerta alerta-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="card">
    <div class="alerta alerta-<?= e($clase) ?>"><?= e($estado['mensaje']) ?></div>
    <?php if ($estado['exigida'] && $licencia !== null): ?>
        <dl class="ficha">
            <dt>Cliente</dt><dd><?= e((string) ($licencia['cliente'] ?? '')) ?></dd>
            <dt>CUIT</dt><dd><?= e((string) ($licencia['cuit'] ?? '')) ?></dd>
            <dt>Vence</dt><dd><?= e(fecha((string) ($licencia['vence'] ?? ''))) ?></dd>
            <dt>Emitida</dt><dd><?= e(fecha((string) ($licencia['emitida'] ?? ''))) ?></dd>
        </dl>
    <?php endif; ?>
    <p class="muted">Mientras la suscripción esté vencida se puede consultar e imprimir, pero no emitir comprobantes. Hay <?= \App\Modules\Licencia\Licencia::GRACIA_DIAS ?> días de gracia después del vencimiento.</p>
</section>
<?php if ($estado['exigida'] && $puedeCargar): ?>
<section class="card">
    <h2>Cargar la licencia renovada</h2>
    <p class="muted">Pegá el contenido completo del archivo <code>licencia.json</code> que te mandó O&amp;S.</p>
    <form method="post" action="<?= e(url('/sistema/licencia')) ?>"><?= csrf_field() ?>
        <label class="field"><span>Licencia</span><textarea name="licencia" rows="8" required spellcheck="false"></textarea></label>
        <div class="acciones"><button class="btn" type="submit">Cargar licencia</button></div>
    </form>
</section>
<?php endif; ?>
