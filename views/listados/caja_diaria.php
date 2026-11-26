<?php
use App\Modules\Comprobantes\Tipos;
use App\Modules\Listados\CajaDiaria;

/** @var array<string,string> $f */
/** @var array<string,mixed> $r resumen */
/** @var array<int,array<string,mixed>> $filas */
/** @var int $total */
/** @var int $pagina */
/** @var int $porPagina */
/** @var array<int,array<string,mixed>> $aperturas */
$u = url('/listados/caja-diaria');
$link = static fn (int $p) => $u . '?' . http_build_query(['pagina' => $p] + $f);
$paginas = (int) ceil($total / max(1, $porPagina));
$m = static fn ($v) => number_format((float) $v, 2, ',', '.');
$p = static fn ($v) => '$ ' . number_format((float) $v, 2, ',', '.');
$v = $r['ventas'];
$ef = $r['efectivo'];
$tipoMonto = static fn (int $t): float => (float) ($r['por_tipo'][$t] ?? 0.0);
$otros = $r['total_cobrado'] - $tipoMonto(1) - $tipoMonto(3) - $tipoMonto(10) - $tipoMonto(5);
?>
<div class="page-head">
    <div><p class="eyebrow">Listados</p><h1>Caja diaria</h1></div>
    <div class="acciones"><a class="btn" href="<?= e(url('/listados/caja-diaria/imprimir') . '?' . http_build_query(['desde' => $f['desde'] ?? '', 'hasta' => $f['hasta'] ?? ''])) ?>" target="_blank" rel="noopener" data-reporte-imprimir>Imprimir reporte detallado</a></div>
</div>

<form method="get" action="<?= e($u) ?>" class="toolbar filtros">
    <label class="field"><span>Desde</span><input name="desde" value="<?= e($f['desde'] ?? '') ?>" placeholder="dd/mm/aaaa" data-fecha inputmode="numeric"></label>
    <label class="field"><span>Hasta</span><input name="hasta" value="<?= e($f['hasta'] ?? '') ?>" placeholder="dd/mm/aaaa" data-fecha inputmode="numeric"></label>
    <button class="btn btn-ghost" type="submit">Ver</button>
</form>

<h2>Lo cobrado por ventas y cobranzas</h2>
<section class="card resumen-fila" aria-label="Cobrado">
    <div><span class="muted small">Total cobrado</span><b><?= e($p($r['total_cobrado'])) ?></b></div>
    <div><span class="muted small">Efectivo (neto en caja)</span><b><?= e($p($tipoMonto(1))) ?></b></div>
    <div><span class="muted small">Tarjeta</span><b><?= e($p($tipoMonto(3))) ?></b></div>
    <div><span class="muted small">Billeteras virtuales</span><b><?= e($p($tipoMonto(10))) ?></b></div>
    <div><span class="muted small">Cheques</span><b><?= e($p($tipoMonto(5))) ?></b></div>
    <?php if (abs($otros) > 0.004): ?><div><span class="muted small">Otros (tickets, dólares…)</span><b><?= e($p($otros)) ?></b></div><?php endif; ?>
</section>
<p class="small muted">Efectivo de ventas y cobranzas: se recibieron <b><?= e($p($ef['recibido'])) ?></b> y se dieron <b><?= e($p($ef['vuelto'])) ?></b> de vuelto: quedan <b><?= e($p($ef['neto'])) ?></b> en caja.</p>

<h2>Efectivo en caja</h2>
<section class="card resumen-fila" aria-label="Efectivo en caja">
    <div><span class="muted small">Fondo de apertura de caja</span><b><?= e($p($ef['fondo'])) ?></b></div>
    <div><span class="muted small">+ Cobrado en efectivo (ventas y cobranzas, neto de vuelto)</span><b><?= e($p($ef['neto'])) ?></b></div>
    <div><span class="muted small">+ Ingresos de dinero</span><b><?= e($p($ef['ingresos'])) ?></b></div>
    <div><span class="muted small">− Egresos de dinero</span><b><?= e($p($ef['egresos'])) ?></b></div>
    <div><span class="muted small">= Efectivo total en caja</span><b><?= e($p($ef['en_caja'])) ?></b></div>
</section>
<?php if (count($aperturas) > 1): ?><p class="small muted">El fondo de apertura suma el de cada turno abierto en el período (<?= count($aperturas) ?> aperturas).</p><?php endif; ?>
<p class="small muted">Los ingresos y egresos de dinero (cambio para el cajón, pagos a proveedores, retiros…) <b>no son ventas</b>: no suman a lo vendido ni a lo cobrado, solo al efectivo que hay en la caja.</p>

<h2>Lo vendido</h2>
<section class="card resumen-fila" aria-label="Vendido">
    <div><span class="muted small">Ventas netas</span><b><?= e($p($v['ventas_netas'])) ?></b></div>
    <div><span class="muted small">Facturado en cuenta corriente</span><b><?= e($p($v['cuenta_corriente'])) ?></b></div>
    <div><span class="muted small">Vendido de contado</span><b><?= e($p($v['contado'])) ?></b></div>
    <div><span class="muted small">Facturas</span><b><?= e($p($v['facturas'])) ?></b></div>
    <div><span class="muted small">Presupuestos</span><b><?= e($p($v['presupuestos'])) ?></b></div>
    <?php if ($v['cambios'] > 0): ?><div><span class="muted small">Cambios (diferencia cobrada)</span><b><?= e($p($v['cambios'])) ?></b></div><?php endif; ?>
    <?php if ($v['notas_debito'] > 0): ?><div><span class="muted small">Notas de débito</span><b><?= e($p($v['notas_debito'])) ?></b></div><?php endif; ?>
    <?php if ($v['notas_credito'] > 0): ?><div><span class="muted small">Notas de crédito (restan)</span><b>− <?= e($p($v['notas_credito'])) ?></b></div><?php endif; ?>
</section>

<h2>Cobrado por forma de pago</h2>
<?php if ($r['cobrado'] === []): ?>
    <p class="muted vacio">No hay cobros en ese período.</p>
<?php else: ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th>Forma de pago</th><th>Tipo</th><th class="num">Ventas de contado</th><th class="num">Recibos</th><th class="num">Recibido</th><th class="num">Vuelto</th><th class="num">Total en caja</th></tr></thead>
        <tbody>
        <?php foreach ($r['cobrado'] as $c): ?>
            <tr><td data-label="Forma de pago"><?= e($c['descripcion']) ?></td><td data-label="Tipo"><?= e(CajaDiaria::etiquetaTipo((int) $c['tipo'])) ?></td>
                <td data-label="Ventas de contado" class="num"><?= e($m($c['ventas'])) ?></td><td data-label="Recibos" class="num"><?= e($m($c['recibos'])) ?></td>
                <td data-label="Recibido" class="num"><?= e($m($c['recibido'])) ?></td><td data-label="Vuelto" class="num"><?= e($m($c['vuelto'])) ?></td>
                <td data-label="Total en caja" class="num"><b><?= e($m($c['total'])) ?></b></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th colspan="6">Total cobrado</th><th class="num"><?= e($m($r['total_cobrado'])) ?></th></tr></tfoot>
    </table></div>
<?php endif; ?>

<h2>Cobros (ventas y cobranzas)</h2>
<?php if ($filas === []): ?>
    <p class="muted vacio">No hay movimientos de caja.</p>
<?php else: ?>
    <?= paginador($pagina, $paginas, $total, $porPagina, $link, true) ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><?= th_orden('Fecha', 'fecha', $f, $u) ?><?= th_orden('Hora', 'hora', $f, $u) ?><?= th_orden('Usuario', 'usuario', $f, $u) ?><?= th_orden('Comprobante', 'comprobante', $f, $u) ?><?= th_orden('Cliente', 'cliente', $f, $u) ?><?= th_orden('Forma de pago', 'forma', $f, $u) ?><?= th_orden('Importe', 'importe', $f, $u, true) ?><?= th_orden('Vuelto', 'vuelto', $f, $u, true) ?></tr></thead>
        <tbody>
        <?php foreach ($filas as $x): $esRecibo = $x['cobra_id'] !== null; ?>
            <tr><td data-label="Fecha" class="nw"><?= e(date('d/m/Y', (int) strtotime($x['fecha']))) ?></td>
                <td data-label="Hora" class="nw"><?= $x['creado_en'] !== null ? e(date('H:i', (int) strtotime($x['creado_en']))) : '—' ?></td>
                <td data-label="Usuario"><?= e((string) $x['usuario'] !== '' ? (string) $x['usuario'] : '—') ?></td>
                <td data-label="Comprobante" class="nw"><?php if ($esRecibo): ?><a href="<?= e(url('/recibos/' . (int) $x['cobra_id'])) ?>">Recibo X <?= e(sprintf('%04d-%08d', $x['prefijo'], $x['numero'])) ?></a>
                    <?php elseif ($x['subdiario_id'] !== null): ?><a href="<?= e(url('/comprobantes/' . (int) $x['subdiario_id'])) ?>"><?= e(Tipos::titulo($x['tipo'], $x['letra']) . ' ' . sprintf('%04d-%08d', $x['prefijo'], $x['numero'])) ?></a>
                    <?php else: ?><?= e($x['tipo'] . ' ' . $x['letra'] . ' ' . sprintf('%04d-%08d', $x['prefijo'], $x['numero'])) ?><?php endif; ?></td>
                <td data-label="Cliente"><?= e((string) $x['razon_social']) ?></td>
                <td data-label="Forma de pago"><?= e($x['forma']) ?></td>
                <td data-label="Importe" class="num"><b><?= e($m($x['p_importe'])) ?></b></td>
                <td data-label="Vuelto" class="num"><?= (float) $x['p_vuelto'] > 0 ? e($m($x['p_vuelto'])) : '' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>

<h2>Apertura, ingresos y egresos de dinero <span class="muted small">(no son ventas)</span></h2>
<?php if ($manuales === [] && $aperturas === []): ?>
    <p class="muted vacio">No hubo ingresos ni egresos de dinero en ese período.</p>
<?php else: ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th>Fecha y hora</th><th>Usuario</th><th>Concepto</th><th>Motivo</th><th class="num">Entra</th><th class="num">Sale</th></tr></thead>
        <tbody>
        <?php foreach ($aperturas as $x): ?>
            <tr class="fila-manual" data-apertura>
                <td data-label="Fecha y hora" class="nw"><?= e(date('d/m/Y H:i', (int) strtotime($x['abierto_en']))) ?></td>
                <td data-label="Usuario"><?= e((string) $x['usuario']) ?></td>
                <td data-label="Concepto"><b>Apertura de caja</b></td>
                <td data-label="Motivo">Fondo inicial del turno</td>
                <td data-label="Entra" class="num"><?= e($m($x['fondo_inicial'])) ?></td>
                <td data-label="Sale" class="num"></td>
            </tr>
        <?php endforeach; ?>
        <?php foreach ($manuales as $x): $ing = $x['tipo'] === 'I'; ?>
            <tr class="fila-manual">
                <td data-label="Fecha y hora" class="nw"><?= e(date('d/m/Y H:i', (int) strtotime($x['creado_en']))) ?></td>
                <td data-label="Usuario"><?= e((string) $x['usuario']) ?></td>
                <td data-label="Concepto"><b><?= $ing ? 'Ingreso de dinero' : 'Egreso de dinero' ?></b><?= $x['anula_id'] !== null ? ' <span class="muted small">(anulación)</span>' : '' ?><?= (int) $x['anulado'] > 0 ? ' <span class="muted small">(anulado)</span>' : '' ?></td>
                <td data-label="Motivo"><?= e($x['motivo']) ?></td>
                <td data-label="Entra" class="num"><?= $ing ? e($m($x['importe'])) : '' ?></td>
                <td data-label="Sale" class="num"><?= $ing ? '' : e($m($x['importe'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
