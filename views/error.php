<?php
/** @var int $code */
/** @var string $title */
/** @var string|null $detail */
?><section class="card">
    <h1><?= e($title) ?></h1>
    <p>Código <?= e($code) ?>.</p>
    <?php if (!empty($detail)): ?>
        <p><?= e($detail) ?></p>
    <?php endif; ?>
    <p><a href="<?= e(url('/')) ?>">Volver al inicio</a></p>
</section>
