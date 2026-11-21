<?php
/** Tablero de compras. @var string $periodo @var array $t @var bool $puedeCargar @var bool $puedeCentros */
$k = $t['kpi'];
$p = static fn ($v) => '$ ' . number_format((float) $v, 0, ',', '.');
$meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$nombreMes = $meses[(int) substr($periodo, 5, 2) - 1] . ' ' . substr($periodo, 0, 4);
$ant = date('Y-m', strtotime($periodo . '-01 -1 month'));
$sig = date('Y-m', strtotime($periodo . '-01 +1 month'));
$hayDatos = $t['centros'] !== [] || abs($k['deuda']) > 0.005;
?>
<div class="page-head">
    <div><p class="eyebrow">Compras</p><h1>Tablero de compras</h1></div>
    <div class="acciones">
        <a class="btn btn-ghost" href="<?= e(url('/compras?periodo=' . $ant)) ?>" aria-label="Mes anterior">‹</a>
        <span class="btn btn-ghost" aria-current="page"><?= e(ucfirst($nombreMes)) ?></span>
        <?php if ($sig <= date('Y-m')): ?><a class="btn btn-ghost" href="<?= e(url('/compras?periodo=' . $sig)) ?>" aria-label="Mes siguiente">›</a><?php endif; ?>
        <?php if ($puedeCargar): ?><a class="btn" href="<?= e(url('/compras/nueva')) ?>">Nueva compra o gasto</a><?php endif; ?>
    </div>
</div>

<?php if (!$hayDatos): ?>
    <section class="card">
        <h2>Para empezar</h2>
        <ol class="pasos-inicio">
            <?php if ($puedeCentros): ?><li>Revisá los <a href="<?= e(url('/compras/centros')) ?>">centros de costos y conceptos</a>: vienen cinco de ejemplo que podés renombrar o desactivar, y si querés cargá un presupuesto por mes.</li><?php endif; ?>
            <li>Cargá tus <a href="<?= e(url('/compras/proveedores')) ?>">proveedores</a> con su centro y concepto habitual: así cada compra se completa sola.</li>
            <?php if ($puedeCargar): ?><li>Cargá la primera <a href="<?= e(url('/compras/nueva')) ?>">compra o gasto</a>. Este tablero se arma con lo que vayas cargando.</li><?php endif; ?>
        </ol>
    </section>
<?php endif; ?>

<section class="kpis" aria-label="Resumen">
    <div class="kpi"><span>Gastado en <?= e($nombreMes) ?></span><b><?= e($p($k['gastado'])) ?></b>
        <span class="small muted"><?= $k['variacion'] === null ? 'Sin datos del mes anterior' : e(($k['variacion'] >= 0 ? '+' : '−') . number_format(abs($k['variacion']), 1, ',', '.') . ' % contra el mes anterior') ?></span></div>
    <div class="kpi"><span>Le debemos a proveedores</span><b><a href="<?= e(url('/compras/a-pagar')) ?>"><?= e($p($k['deuda'])) ?></a></b>
        <span class="small muted"><?= (int) $k['deuda_comprobantes'] ?> comprobantes<?= $k['vencido'] > 0.005 ? ' · <span class="pill pill-mal">' . e($p($k['vencido'])) . ' vencido</span>' : '' ?></span></div>
    <div class="kpi"><span>Vence en los próximos 7 días</span><b><?= e($p($k['semana'])) ?></b>
        <span class="small"><?= (int) $k['semana_cant'] > 0 ? '<span class="pill pill-warn">' . (int) $k['semana_cant'] . ' comprobantes</span>' : '<span class="muted">Nada por vencer</span>' ?></span></div>
    <?php if ($k['con_presupuesto'] > 0): ?>
        <div class="kpi"><span>Centros sobre el presupuesto</span><b><?= (int) $k['sobre'] ?> de <?= (int) $k['con_presupuesto'] ?></b>
            <span class="small"><?= $k['sobre'] > 0 ? '<span class="pill pill-mal">' . e(implode(', ', $k['sobre_nombres'])) . '</span>' : '<span class="pill pill-ok">Todos dentro del presupuesto</span>' ?></span></div>
    <?php else: ?>
        <div class="kpi"><span>Compras para revisar</span><b><a href="<?= e(url('/compras/lista?estado=revisar')) ?>"><?= (int) $k['revisar'] ?></a></b>
            <span class="small muted"><?= $puedeCentros ? 'Sin presupuesto cargado: <a href="' . e(url('/compras/centros?periodo=' . $periodo . '#presupuesto')) . '">cargalo</a> para ver el semáforo' : 'Cargadas desde el celular o marcadas para revisar' ?></span></div>
    <?php endif; ?>
</section>

<div class="graficos">
    <section class="grafico" data-graf="presupuesto">
        <h2><?= $k['con_presupuesto'] > 0 ? 'Presupuesto contra gastado' : 'Gastado por centro' ?>, <?= e($nombreMes) ?></h2>
        <p class="sub"><?= $k['con_presupuesto'] > 0 ? 'La marca es el presupuesto; en rojo, lo que se pasó.' : 'Cargá un presupuesto por centro para ver la marca.' ?> <a href="<?= e(url('/compras/presupuesto?periodo=' . $periodo)) ?>">Ver el detalle</a></p>
    </section>
    <section class="grafico" data-graf="centros">
        <h2>Gasto por centro, mes a mes</h2>
        <p class="sub">Los últimos seis meses. <a href="<?= e(url('/compras/analisis')) ?>">Ver el análisis</a></p>
        <div class="leyenda"></div>
    </section>
    <section class="grafico" data-graf="ventas">
        <h2>Ventas y compras por mes</h2>
        <p class="sub">Venta neta contra compras y gastos (sin IVA computable). La distancia entre las líneas es lo que queda; no reemplaza al balance.</p>
        <div class="leyenda"><span><i style="background:var(--serie-1)"></i>Ventas</span><span><i style="background:var(--serie-2)"></i>Compras y gastos</span></div>
    </section>
    <section class="grafico" data-graf="conceptos">
        <h2>En qué se gastó, <?= e($nombreMes) ?></h2>
        <p class="sub">Por concepto de gasto. <a href="<?= e(url('/compras/analisis?' . http_build_query(['agrupar' => 'concepto', 'desde' => date('01/m/Y', strtotime($periodo . '-01')), 'hasta' => date('t/m/Y', strtotime($periodo . '-01'))]))) ?>">Ver el listado</a></p>
    </section>
</div>
<script type="application/json" id="datos-tablero"><?= json_encode($t, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('assets/compras-graficos.js')) ?>" defer></script>
