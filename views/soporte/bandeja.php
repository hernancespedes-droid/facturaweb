<?php
/** Bandeja de O&S. @var array<string,string> $f @var array<int,array<string,mixed>> $filas @var int $total @var int $pagina @var int $porPagina
 *  @var array<string,string> $instancias @var int $pendientes @var string $mails @var bool $mailConfigurado */
use App\Modules\Soporte\SoporteService;
$u = url('/soporte/bandeja');
$link = static fn (int $p) => $u . '?' . http_build_query(['pagina' => $p] + $f);
$paginas = (int) ceil($total / max(1, $porPagina));
?>
<div class="page-head"><div><p class="eyebrow">Sistema</p><h1>Tickets de soporte</h1></div>
    <div class="actions"><a class="btn btn-ghost" href="<?= e($u . '?estado=pendientes') ?>">Para contestar: <b><?= $pendientes ?></b></a></div></div>
<details class="card small" <?= $mails === '' ? 'open' : '' ?>>
    <summary><b>Aviso por mail</b>: <?= $mails !== '' ? e($mails) : '<span class="texto-error">sin mails configurados (los tickets quedan solo en esta bandeja)</span>' ?></summary>
    <form method="post" action="<?= e(url('/soporte/bandeja/configuracion')) ?>" class="form-grid"><?= csrf_field() ?>
        <label class="field field-ancho"><span>Mails que reciben cada ticket nuevo y cada respuesta de un cliente (separados por coma)</span>
            <input name="mail_aviso" value="<?= e($mails) ?>" placeholder="soporte@orgysis.site, hernan@…"></label>
        <?php if (!$mailConfigurado): ?><p class="small texto-error field-ancho">El envío de mails (SMTP) no está configurado en config/local.php del master: los avisos no van a salir.</p><?php endif; ?>
        <div class="acciones"><button class="btn" type="submit">Guardar</button></div>
    </form>
</details>
<form method="get" action="<?= e($u) ?>" class="toolbar filtros">
    <label class="field"><span>Buscar</span><input name="q" value="<?= e($f['q'] ?? '') ?>" placeholder="N.º, asunto, usuario o cliente"></label>
    <label class="field"><span>Cliente</span><select name="instancia"><option value="">Todos</option>
        <?php foreach ($instancias as $k => $t): ?><option value="<?= e($k) ?>" <?= ($f['instancia'] ?? '') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Estado</span><select name="estado">
        <?php foreach (['abiertos' => 'Abiertos', 'pendientes' => 'Para contestar'] + SoporteService::ESTADOS_MASTER + ['todos' => 'Todos'] as $k => $t): ?><option value="<?= e($k) ?>" <?= ($f['estado'] ?? '') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Prioridad</span><select name="prioridad"><option value="">Todas</option>
        <?php foreach (SoporteService::PRIORIDADES as $k => $t): ?><option value="<?= e($k) ?>" <?= ($f['prioridad'] ?? '') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Tipo</span><select name="tipo"><option value="">Todos</option>
        <?php foreach (SoporteService::TIPOS as $k => $t): ?><option value="<?= e($k) ?>" <?= ($f['tipo'] ?? '') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
    <button class="btn btn-ghost" type="submit">Ver</button>
</form>
<?php if ($filas === []): ?>
    <p class="muted vacio">No hay tickets con ese filtro.</p>
<?php else: ?>
    <?= paginador($pagina, $paginas, $total, $porPagina, $link, true) ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><?= th_orden('N.º', 'numero', $f, $u, true) ?><?= th_orden('Cliente', 'cliente', $f, $u) ?><?= th_orden('Asunto', 'asunto', $f, $u) ?><?= th_orden('Tipo', 'tipo', $f, $u) ?>
            <?= th_orden('Prioridad', 'prioridad', $f, $u, true) ?><?= th_orden('Estado', 'estado', $f, $u) ?><th>Usuario</th><?= th_orden('Abierto', 'creado', $f, $u, true) ?><?= th_orden('Último movimiento', 'actualizado', $f, $u, true) ?></tr></thead>
        <tbody>
        <?php foreach ($filas as $x): $ver = url('/soporte/bandeja/' . (int) $x['id']); ?>
            <tr class="<?= (int) $x['pendiente'] === 1 && $x['estado'] !== 'cerrado' ? 'fila-destacada' : '' ?>">
                <td data-label="N.º" class="nw"><a href="<?= e($ver) ?>">#<?= (int) $x['id'] ?></a></td>
                <td data-label="Cliente"><?= e($x['instancia_nombre'] !== '' ? $x['instancia_nombre'] : ($x['instancia'] !== '' ? $x['instancia'] : 'Instalación principal')) ?><?= $x['instancia'] !== '' ? '<br><span class="small muted">' . e($x['instancia']) . '</span>' : '' ?></td>
                <td data-label="Asunto"><a href="<?= e($ver) ?>"><?= e($x['asunto']) ?></a><?= (int) $x['pendiente'] === 1 && $x['estado'] !== 'cerrado' ? ' <span class="tag tag-brand">para contestar</span>' : '' ?></td>
                <td data-label="Tipo"><?= e(SoporteService::TIPOS[$x['tipo']] ?? $x['tipo']) ?></td>
                <td data-label="Prioridad"><?= $x['prioridad'] === 'urgente' ? '<span class="badge badge-off">Urgente</span>' : 'Normal' ?></td>
                <td data-label="Estado"><?php $estado = (string) $x['estado']; $master = true; require __DIR__ . '/_estado.php'; ?></td>
                <td data-label="Usuario"><?= e($x['usuario']) ?><?= $x['version'] !== '' ? '<br><span class="small muted">v' . e($x['version']) . '</span>' : '' ?></td>
                <td data-label="Abierto" class="nw"><?= e(date('d/m/Y H:i', (int) strtotime((string) $x['creado_en']))) ?></td>
                <td data-label="Último movimiento" class="nw"><?= e(date('d/m/Y H:i', (int) strtotime((string) $x['actualizado_en']))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
