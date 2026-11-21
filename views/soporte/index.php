<?php
/** @var array<int,array<string,mixed>> $tickets @var ?string $error @var array<string,string> $f */
use App\Modules\Soporte\SoporteService;
$u = url('/soporte');
?>
<div class="page-head"><div><p class="eyebrow">Ayuda</p><h1>Soporte de O&amp;S</h1></div>
    <div class="actions"><a class="btn" href="<?= e(url('/soporte/nuevo')) ?>">+ Nuevo ticket</a></div></div>
<p class="small muted">¿Algo no funciona, tenés una consulta o querés pedir una mejora? Abrí un ticket: le llega a O&amp;S y seguís la respuesta acá.</p>
<?php if ($error !== null): ?><div class="alerta alerta-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="get" action="<?= e($u) ?>" class="toolbar filtros">
    <label class="field"><span>Ver</span><select name="estado" onchange="this.form.submit()">
        <?php foreach (['abiertos' => 'Abiertos', 'cerrados' => 'Cerrados', 'todos' => 'Todos'] as $k => $t): ?><option value="<?= e($k) ?>" <?= $f['estado'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
    <noscript><button class="btn btn-ghost" type="submit">Ver</button></noscript>
</form>
<?php if ($tickets === [] && $error === null): ?>
    <p class="muted vacio"><?= $f['estado'] === 'abiertos' ? 'No hay tickets abiertos.' : 'No hay tickets.' ?></p>
<?php elseif ($tickets !== []): ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th>N.º</th><th>Asunto</th><th>Tipo</th><th>Estado</th><th>Abrió</th><th>Abierto</th><th>Último movimiento</th></tr></thead>
        <tbody>
        <?php foreach ($tickets as $x): ?>
            <tr>
                <td data-label="N.º" class="nw"><a href="<?= e(url('/soporte/' . (int) $x['id'])) ?>">#<?= (int) $x['id'] ?></a></td>
                <td data-label="Asunto"><a href="<?= e(url('/soporte/' . (int) $x['id'])) ?>"><?= e($x['asunto']) ?></a><?= $x['prioridad'] === 'urgente' ? ' <span class="badge badge-off">Urgente</span>' : '' ?></td>
                <td data-label="Tipo"><?= e(SoporteService::TIPOS[$x['tipo']] ?? $x['tipo']) ?></td>
                <td data-label="Estado"><?php $estado = (string) $x['estado']; $master = false; require __DIR__ . '/_estado.php'; ?></td>
                <td data-label="Abrió"><?= e($x['usuario']) ?></td>
                <td data-label="Abierto" class="nw"><?= e(date('d/m/Y H:i', (int) strtotime((string) $x['creado_en']))) ?></td>
                <td data-label="Último movimiento" class="nw"><?= e(date('d/m/Y H:i', (int) strtotime((string) $x['actualizado_en']))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
