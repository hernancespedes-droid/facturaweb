<?php
/** @var array<string,array<string,mixed>> $tablas */
?>
<div class="page-head"><h1>Tablas</h1></div>
<div class="tiles">
    <?php foreach ($tablas as $t => $d): ?>
        <a class="tile" href="<?= e(url('/tablas/' . $t)) ?>">
            <span class="tile-title"><?= e($d['titulo']) ?></span>
            <span class="muted small"><?= e($d['ayuda'] ?? '') ?></span>
        </a>
    <?php endforeach; ?>
</div>
