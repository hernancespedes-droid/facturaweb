<?php
use App\Modules\Compras\ComprasService as S;

/** Una compra. @var array $c @var ?string $error @var bool $puedeCargar @var bool $puedePagar */
$m = static fn ($v) => number_format((float) $v, 2, ',', '.');
$sg = S::signo($c['tipo']);
$vigente = $c['estado'] === 'V';
$pct = static fn ($a) => rtrim(rtrim(number_format((float) $a, 2, ',', ''), '0'), ',') . ' %';
?>
<div class="page-head">
    <div><p class="eyebrow">Compras</p><h1><?= e(S::titulo($c)) ?></h1>
        <p class="muted"><a href="<?= e(url('/compras/proveedores/ficha?id=' . (int) $c['proveedor_id'])) ?>"><?= e($c['razon_social']) ?></a> · CUIT <?= e(cuit_texto($c['cuit'])) ?> · <?= e(fecha($c['fecha'])) ?>
            <?= !$vigente ? ' <span class="pill pill-mal">anulada</span>' : '' ?><?= $vigente && (int) $c['a_revisar'] === 1 ? ' <span class="pill pill-warn">para revisar</span>' : '' ?></p></div>
    <div class="acciones"><a class="btn btn-ghost" href="<?= e(url('/compras/lista')) ?>">Volver a compras</a></div>
</div>
<?php if ($error): ?><div class="alerta alerta-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if (!$vigente): ?><div class="alerta alerta-warn">Anulada el <?= e(date('d/m/Y H:i', strtotime((string) $c['anulada_en']))) ?><?= $c['anulo'] ? ' por ' . e($c['anulo']) : '' ?>: <?= e($c['motivo_anulacion']) ?></div><?php endif; ?>

<section class="card resumen-fila" aria-label="Totales">
    <div><span class="muted small">Total</span><b><?= e('$ ' . $m($c['total'] * $sg)) ?></b></div>
    <div><span class="muted small">Costo para los centros</span><b><?= e('$ ' . $m($c['costo'] * $sg)) ?></b></div>
    <div><span class="muted small">Pago</span><b><?= e(S::FORMAS[$c['forma']] ?? '') ?></b></div>
    <?php if ($c['forma'] === 'C'): ?>
        <div><span class="muted small">Vence</span><b><?= $c['vence'] ? e(fecha($c['vence'])) : '—' ?></b></div>
        <div><span class="muted small">Saldo</span><b><?= e('$ ' . $m($c['saldo'] * $sg)) ?></b></div>
    <?php endif; ?>
    <div><span class="muted small">Período de IVA</span><b><?= e(substr($c['periodo_iva'], 5) . '/' . substr($c['periodo_iva'], 0, 4)) ?></b></div>
</section>

<div class="graficos">
    <section class="card">
        <h2>Importes</h2>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Concepto</th><th class="num">Neto</th><th class="num">IVA</th></tr></thead>
            <tbody>
            <?php foreach ($c['alicuotas'] as $a): ?><tr><td>Gravado <?= e($pct($a['alicuota'])) ?></td><td class="num"><?= e($m($a['neto'])) ?></td><td class="num"><?= e($m($a['iva'])) ?></td></tr><?php endforeach; ?>
            <?php if ((float) $c['no_gravado'] > 0): ?><tr><td><?= S::discrimina($c['tipo'], $c['letra']) ? 'No gravado' : 'Importe' ?></td><td class="num"><?= e($m($c['no_gravado'])) ?></td><td></td></tr><?php endif; ?>
            <?php if ((float) $c['exento'] > 0): ?><tr><td>Exento</td><td class="num"><?= e($m($c['exento'])) ?></td><td></td></tr><?php endif; ?>
            <?php foreach ($c['tributos'] as $t): ?><tr><td><?= e((S::TRIBUTOS[$t['tipo']] ?? $t['tipo']) . ($t['detalle'] !== '' ? ' · ' . $t['detalle'] : '')) ?></td><td></td><td class="num"><?= e($m($t['importe'])) ?></td></tr><?php endforeach; ?>
            </tbody>
            <tfoot><tr><th>Total</th><th></th><th class="num"><?= e($m($c['total'])) ?></th></tr></tfoot>
        </table></div>
        <p class="small muted"><?= (int) $c['computa_iva'] === 1 ? 'El IVA se computa como crédito fiscal.' : 'Sin crédito fiscal de IVA.' ?><?= $c['cae'] !== '' ? ' CAE ' . e($c['cae']) . '.' : '' ?></p>
    </section>
    <section class="card">
        <h2>Centros de costos</h2>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Centro</th><th>Concepto</th><th class="num">%</th><th class="num">Importe</th></tr></thead>
            <tbody><?php foreach ($c['reparto'] as $r): ?>
                <tr><td><?= e(($r['centro_padre'] ? $r['centro_padre'] . ' › ' : '') . $r['centro']) ?></td><td><?= e($r['concepto']) ?></td><td class="num"><?= e($pct($r['porcentaje'])) ?></td><td class="num"><?= e($m($r['importe'] * $sg)) ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php if ($c['observaciones'] !== ''): ?><p><b>Observaciones:</b> <?= e($c['observaciones']) ?></p><?php endif; ?>
        <p class="small muted">Cargada por <?= e($c['usuario'] ?? '—') ?> el <?= e(date('d/m/Y H:i', strtotime((string) $c['creado_en']))) ?>.</p>
    </section>
</div>

<?php if ($c['pagos'] !== []): ?>
    <h2>Pagos</h2>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Fecha</th><th>Medio</th><th>Detalle</th><th>Usuario</th><th class="num">Importe</th></tr></thead>
        <tbody><?php foreach ($c['pagos'] as $p): ?>
            <tr<?= (int) $p['anulado'] === 1 ? ' class="muted"' : '' ?>><td><?= e(fecha($p['fecha'])) ?></td><td><?= e(S::MEDIOS[$p['medio']] ?? '') ?><?= (int) $p['anulado'] === 1 ? ' <span class="pill pill-mal">anulado</span>' : '' ?></td>
                <td><?= e($p['detalle']) ?></td><td><?= e($p['usuario'] ?? '') ?></td><td class="num"><?= e($m($p['importe'])) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
<?php endif; ?>

<?php if ($c['adjuntos'] !== []): ?>
    <h2>Adjuntos</h2>
    <div class="toolbar">
        <?php foreach ($c['adjuntos'] as $a): $src = url('/compras/adjunto?compra=' . (int) $c['id'] . '&id=' . (int) $a['id']); ?>
            <?php if (str_starts_with($a['mime'], 'image/')): ?>
                <button type="button" class="btn-icono" style="width:auto;height:auto" data-ampliar-src="<?= e($src) ?>" data-titulo="<?= e($a['nombre']) ?>"><img src="<?= e($src) ?>" alt="<?= e($a['nombre']) ?>" style="max-height:140px;border-radius:6px"></button>
            <?php else: ?>
                <a class="btn btn-ghost" href="<?= e($src) ?>" target="_blank" rel="noopener">📄 <?= e($a['nombre']) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($vigente && $puedePagar && $c['forma'] === 'C' && $c['tipo'] !== 'NC' && (float) $c['saldo'] > 0.005): ?>
    <form method="post" action="<?= e(url('/compras/pagar')) ?>" class="card form-grid grid-campos"><?= csrf_field() ?>
        <h2>Pagar</h2>
        <input type="hidden" name="compra" value="<?= (int) $c['id'] ?>">
        <label class="field"><span>Medio</span><select name="medio"><?php foreach (S::MEDIOS as $k => $t): ?><option value="<?= e($k) ?>"><?= e($t) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Importe</span><input name="importe" value="<?= e($m($c['saldo'])) ?>" inputmode="decimal" data-decimales="2"></label>
        <label class="field"><span>Fecha</span><input name="fecha" value="<?= e(date('d/m/Y')) ?>" inputmode="numeric"></label>
        <label class="field"><span>Detalle</span><input name="detalle" maxlength="120" placeholder="Banco, número de operación…"></label>
        <div class="acciones"><button class="btn" type="submit">Registrar pago</button></div>
    </form>
<?php endif; ?>

<?php if ($vigente && $puedeCargar): ?>
    <div class="toolbar">
        <?php if ((int) $c['a_revisar'] === 1): ?>
            <form method="post" action="<?= e(url('/compras/revisada')) ?>"><?= csrf_field() ?><input type="hidden" name="compra" value="<?= (int) $c['id'] ?>"><button class="btn btn-ghost" type="submit">Marcar como revisada</button></form>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/compras/anular')) ?>" class="toolbar" data-anular-compra><?= csrf_field() ?>
            <input type="hidden" name="compra" value="<?= (int) $c['id'] ?>">
            <input name="motivo" maxlength="160" required placeholder="Motivo de la anulación" aria-label="Motivo de la anulación">
            <button class="btn btn-ghost" type="submit">Anular compra</button>
        </form>
    </div>
    <p class="small muted">Anular deja la compra en los listados como anulada; si se pagó con efectivo de la caja, el dinero vuelve con un contra-asiento en tu turno.</p>
<?php endif; ?>
