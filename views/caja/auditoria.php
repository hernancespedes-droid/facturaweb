<?php
/** @var array<string,string> $f */
/** @var array<int,array<string,mixed>> $filas */
/** @var int $total */
/** @var int $pagina */
/** @var int $porPagina */
/** @var array<int,string> $usuarios */
/** @var array<int,array<string,mixed>> $registro */
/** @var array{ok:bool,total:int,primera_mala:?int} $cadena */
$u = url('/caja/auditoria');
$link = static fn (int $p) => $u . '?' . http_build_query(['pagina' => $p] + $f);
$paginas = (int) ceil($total / max(1, $porPagina));
$m = static fn ($v) => number_format((float) $v, 2, ',', '.');
$hora = static fn (?string $t) => $t === null ? '—' : date('d/m/Y H:i:s', strtotime($t));
?>
<div class="page-head"><div><p class="eyebrow">Caja</p><h1>Auditoría de caja</h1></div></div>

<?php if ($cadena['ok']): ?>
    <div class="alerta alerta-ok" role="status">Registro de acciones íntegro: <?= (int) $cadena['total'] ?> acciones encadenadas, ninguna fue alterada.</div>
<?php else: ?>
    <div class="alerta alerta-error" role="alert">⚠ El registro de acciones fue alterado: la acción n.º <?= (int) $cadena['primera_mala'] ?> no coincide con su control. Avisá a Dev Senior.</div>
<?php endif; ?>

<form method="get" action="<?= e($u) ?>" class="toolbar filtros">
    <label class="field"><span>Desde</span><input name="desde" value="<?= e($f['desde'] ?? '') ?>" placeholder="dd/mm/aaaa" data-fecha inputmode="numeric"></label>
    <label class="field"><span>Hasta</span><input name="hasta" value="<?= e($f['hasta'] ?? '') ?>" placeholder="dd/mm/aaaa" data-fecha inputmode="numeric"></label>
    <label class="field"><span>Usuario</span><select name="usuario"><option value="">Todos</option>
        <?php foreach ($usuarios as $id => $n): ?><option value="<?= (int) $id ?>" <?= ($f['usuario'] ?? '') === (string) $id ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Origen</span><select name="origen"><option value="">Todos</option>
        <?php foreach (['venta' => 'Ventas', 'recibo' => 'Cobranzas (recibos)', 'manual' => 'Ingresos y egresos de dinero'] as $k => $t): ?><option value="<?= $k ?>" <?= ($f['origen'] ?? '') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
    <?php if (($f['turno'] ?? '') !== ''): ?><input type="hidden" name="turno" value="<?= e($f['turno']) ?>"><?php endif; ?>
    <button class="btn btn-ghost" type="submit">Ver</button>
</form>

<h2>Movimientos <?= ($f['turno'] ?? '') !== '' ? '<span class="muted small">(turno ' . e($f['turno']) . ')</span>' : '' ?></h2>
<?php if ($filas === []): ?>
    <p class="muted vacio">No hay movimientos con esos filtros.</p>
<?php else: ?>
    <?= paginador($pagina, $paginas, $total, $porPagina, $link, true) ?>
    <?= \App\Core\View::render('caja/_movimientos', ['filas' => $filas, 'f' => $f, 'u' => $u, 'anular' => false, 'segundos' => true], null) ?>
<?php endif; ?>

<h2>Registro de acciones <span class="muted small">(últimas <?= count($registro) ?>)</span></h2>
<?php if ($registro === []): ?>
    <p class="muted vacio">Todavía no hay acciones registradas.</p>
<?php else: ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th>Fecha y hora</th><th>Usuario</th><th>Acción</th><th>Detalle</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($registro as $a): ?>
            <tr><td data-label="Fecha y hora"><?= e($hora($a['creado_en'])) ?></td><td data-label="Usuario"><?= e((string) $a['usuario']) ?></td><td data-label="Acción"><?= e($a['accion']) ?> <span class="muted small"><?= e($a['entidad']) ?> <?= $a['entidad_id'] !== null ? '#' . (int) $a['entidad_id'] : '' ?></span></td><td data-label="Detalle"><?= e($a['detalle']) ?></td><td data-label="IP"><?= e($a['ip']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
