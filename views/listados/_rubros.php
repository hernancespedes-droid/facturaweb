<?php
/** Filtros de rubro y subrubro. @var array<string,string> $f @var array<string,array<int,array<string,mixed>>> $opciones */
?>
<label class="field"><span>Rubro</span><select name="rubro"><option value="">Todos</option>
    <?php foreach ($opciones['rubros'] as $o): ?><option value="<?= e($o['codigo']) ?>" <?= ($f['rubro'] ?? '') === (string) $o['codigo'] ? 'selected' : '' ?>><?= e($o['descripcion'] . ' (' . $o['codigo'] . ')') ?></option><?php endforeach; ?></select></label>
<label class="field"><span>Subrubro</span><select name="subrubro"><option value="">Todos</option>
    <?php foreach ($opciones['subrubros'] as $o): ?><option value="<?= e($o['codigo']) ?>" <?= ($f['subrubro'] ?? '') === (string) $o['codigo'] ? 'selected' : '' ?>><?= e($o['descripcion'] . ' (' . $o['rubro'] . '/' . $o['codigo'] . ')') ?></option><?php endforeach; ?></select></label>
