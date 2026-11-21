<?php
/** Conversación de un ticket. @var array<string,mixed> $t @var string $urlAdjunto (prefijo, + id) @var bool $master */
?>
<ol class="soporte-conversacion">
<?php foreach ($t['mensajes'] as $m): $deOys = $m['autor_tipo'] === 'O'; ?>
    <li class="soporte-msg <?= $deOys ? 'de-oys' : 'de-cliente' ?> <?= (int) $m['interno'] === 1 ? 'interno' : '' ?>">
        <div class="soporte-msg-cab"><b><?= e($m['autor'] !== '' ? $m['autor'] : ($deOys ? 'O&S' : 'Cliente')) ?></b>
            <span class="muted small"><?= e(date('d/m/Y H:i', (int) strtotime((string) $m['creado_en']))) ?></span>
            <?php if ((int) $m['interno'] === 1): ?><span class="tag">Nota interna (el cliente no la ve)</span><?php endif; ?></div>
        <div class="soporte-msg-texto"><?= nl2br(e($m['texto'])) ?></div>
        <?php foreach ($m['adjuntos'] as $a): ?>
            <p class="small"><a href="<?= e(url($urlAdjunto . (int) $a['id'])) ?>" target="_blank" rel="noopener">📎 <?= e($a['nombre']) ?></a>
                <span class="muted">(<?= e(number_format($a['tamano'] / 1024, 0, ',', '.')) ?> KB)</span></p>
            <?php if (str_starts_with((string) $a['tipo'], 'image/')): ?>
                <a href="<?= e(url($urlAdjunto . (int) $a['id'])) ?>" target="_blank" rel="noopener"><img class="soporte-captura" src="<?= e(url($urlAdjunto . (int) $a['id'])) ?>" alt="<?= e($a['nombre']) ?>" loading="lazy"></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </li>
<?php endforeach; ?>
</ol>
