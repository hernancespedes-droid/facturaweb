<?php
/** @var array<string,string> $f */
/** @var array<int,array<string,mixed>> $filas */
/** @var bool $audita */
/** @var array<int,string> $usuarios */
$u = url('/caja/turnos');
$m = static fn ($v) => $v === null ? '—' : number_format((float) $v, 2, ',', '.');
$hora = static fn (?string $t) => $t === null ? '—' : date('d/m/Y H:i', strtotime($t));
?>
<div class="page-head"><div><p class="eyebrow">Caja</p><h1>Turnos y arqueos</h1></div></div>
<form method="get" action="<?= e($u) ?>" class="toolbar filtros">
    <label class="field"><span>Desde</span><input name="desde" value="<?= e($f['desde'] ?? '') ?>" placeholder="dd/mm/aaaa (por omisión, 30 días)" data-fecha inputmode="numeric"></label>
    <label class="field"><span>Hasta</span><input name="hasta" value="<?= e($f['hasta'] ?? '') ?>" placeholder="dd/mm/aaaa" data-fecha inputmode="numeric"></label>
    <?php if ($audita): ?>
        <label class="field"><span>Cajero</span><select name="usuario"><option value="">Todos</option>
            <?php foreach ($usuarios as $id => $n): ?><option value="<?= (int) $id ?>" <?= ($f['usuario'] ?? '') === (string) $id ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></label>
    <?php endif; ?>
    <button class="btn btn-ghost" type="submit">Ver</button>
</form>
<?php if ($filas === []): ?>
    <p class="muted vacio">No hay turnos en ese período.</p>
<?php else: ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><?= th_orden('Cajero', 'cajero', $f, $u) ?><?= th_orden('Abierto', 'abierto', $f, $u, true) ?><?= th_orden('Cerrado', 'cerrado', $f, $u) ?><?= th_orden('Fondo', 'fondo', $f, $u, true) ?><?= th_orden('Esperado', 'esperado', $f, $u, true) ?><?= th_orden('Contado', 'contado', $f, $u, true) ?><?= th_orden('Diferencia', 'diferencia', $f, $u, true) ?><th>Observaciones</th><?php if ($audita): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($filas as $t): $dif = $t['diferencia']; ?>
            <tr>
                <td data-label="Cajero"><?= e($t['cajero']) ?></td>
                <td data-label="Abierto"><?= e($hora($t['abierto_en'])) ?></td>
                <td data-label="Cerrado"><?= $t['estado'] === 'A' ? '<span class="badge">Abierto</span>' : e($hora($t['cerrado_en'])) ?></td>
                <td data-label="Fondo" class="num"><?= e($m($t['fondo_inicial'])) ?></td>
                <td data-label="Esperado" class="num"><?= e($m($t['esperado'])) ?></td>
                <td data-label="Contado" class="num"><?= e($m($t['contado'])) ?></td>
                <td data-label="Diferencia" class="num <?= $dif !== null && abs((float) $dif) > 0.004 ? 'texto-error' : '' ?>"><?= e($m($dif)) ?></td>
                <td data-label="Observaciones"><?= e($t['observaciones']) ?></td>
                <?php if ($audita): ?><td><a href="<?= e(url('/caja/auditoria') . '?' . http_build_query(['turno' => $t['id'], 'desde' => '01/01/2000', 'hasta' => '31/12/2100'])) ?>">Movimientos</a></td><?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
