<?php /** Filtros de centro y concepto. @var array<string,string> $f @var array $centros @var array $conceptos */ ?>
<label class="field"><span>Centro</span><select name="centro"><option value="">Todos</option>
    <?php foreach ($centros as $id => $c): ?><option value="<?= (int) $id ?>" <?= ($f['centro'] ?? '') === (string) $id ? 'selected' : '' ?>><?= $c['nivel'] ? '— ' : '' ?><?= e($c['nombre']) ?></option><?php endforeach; ?></select></label>
<label class="field"><span>Concepto</span><select name="concepto"><option value="">Todos</option>
    <?php foreach ($conceptos as $id => $c): ?><option value="<?= (int) $id ?>" <?= ($f['concepto'] ?? '') === (string) $id ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?></select></label>
