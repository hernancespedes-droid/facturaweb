<?php
use App\Core\Db;
use App\Core\Menu;
use App\Core\View;
use App\Modules\Auth\Auth;
use App\Modules\Tablero\Graficos;
use App\Modules\Tablero\Tablero;

/** @var array<string,mixed> $tb */
$pdo = Db::pdo();
$emp = $pdo->query('SELECT empresa, cuit FROM parametros WHERE id = 1')->fetch() ?: ['empresa' => '', 'cuit' => null];
$per = $tb['periodo'];
$dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
$meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$ts = strtotime($tb['hoy']);
$fechaTexto = ucfirst($dias[(int) date('w', $ts)]) . ' ' . (int) date('j', $ts) . ' de ' . $meses[(int) date('n', $ts)];
$qs = static fn (array $extra): string => url('/') . '?' . http_build_query(array_filter($extra + ['periodo' => $per['clave'], 'sucursal' => $tb['sucursal'] ?: null], static fn ($v) => $v !== null && $v !== ''));
$iconos = [
    'alerta' => 'M12 3l10 18H2L12 3z M12 10v4 M12 17.5v.5',
    'reloj' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z M12 7v5l3 2',
    'caja' => 'M21 8l-9-5-9 5v8l9 5 9-5V8z M3 8l9 5 9-5 M12 13v8',
    'billete' => 'M3 7h18v10H3z M12 14.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
    'persona' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6',
];
$icono = static fn (string $d): string => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . e($d) . '"/></svg>';
$v = $tb['ventas'];
?>
<div class="tb">
<section class="tb-cab">
    <div>
        <p class="eyebrow"><?= e($emp['empresa'] !== '' ? $emp['empresa'] : 'Facturador web') ?><?= $emp['cuit'] ? ' · CUIT ' . e(cuit_texto($emp['cuit'])) : '' ?></p>
        <h1><?= $v !== null ? 'Cómo viene el negocio' : 'Inicio' ?></h1>
        <p class="muted"><?= e($fechaTexto) ?> · Hola, <?= e(Auth::usuario()['nombre'] ?? '') ?>. Estás trabajando como <b><?= e(Auth::rol()['nombre'] ?? '') ?></b>.</p>
    </div>
    <?php if ($v !== null || $tb['conteo'] !== null): ?>
    <form class="tb-filtros" method="get" action="<?= e(url('/')) ?>">
        <nav class="tb-periodos" aria-label="Período">
            <?php foreach (Tablero::PERIODOS as $k => $t): ?>
                <a href="<?= e($qs(['periodo' => $k])) ?>"<?= $k === $per['clave'] ? ' class="activo" aria-current="true"' : '' ?>><?= e($t) ?></a>
            <?php endforeach; ?>
        </nav>
        <input type="hidden" name="periodo" value="<?= e($per['clave']) ?>">
        <?php if (count($tb['sucursales']) > 1): ?>
            <label class="tb-suc">Sucursal
                <select name="sucursal" data-tb-enviar>
                    <option value="">Todas</option>
                    <?php foreach ($tb['sucursales'] as $c => $d): ?>
                        <option value="<?= (int) $c ?>"<?= (int) $c === $tb['sucursal'] ? ' selected' : '' ?>><?= e(str_pad((string) $c, 4, '0', STR_PAD_LEFT) . ' · ' . $d) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <noscript><button class="btn" type="submit">Ver</button></noscript>
        <?php endif; ?>
    </form>
    <?php endif; ?>
</section>

<?php if ($v !== null || $tb['tiles'] !== []): ?>
<section class="tb-numeros">
    <?php if ($v !== null):
        $cerrados = array_values(array_filter($tb['meses'], static fn ($m) => !$m['parcial']));
        $d = $v['delta']; ?>
        <div class="tb-card tb-hero">
            <div>
                <p class="tb-label"><?= e($per['titulo']) ?></p>
                <p class="tb-hero-n"><?= e(Tablero::pesos($v['actual']['neto'])) ?></p>
                <p class="tb-delta">
                    <?php if ($d === null): ?>
                        <span class="muted">Sin ventas para comparar <?= e($per['vs']) ?></span>
                    <?php else: ?>
                        <b class="<?= $d >= 0 ? 'tb-sube' : 'tb-baja' ?>"><?= $d >= 0 ? '▲' : '▼' ?> <?= e(Graficos::signo($d)) ?></b> <span class="muted"><?= e($per['vs']) ?> (<?= e(Tablero::pesos($v['anterior']['neto'])) ?>)</span>
                    <?php endif; ?>
                </p>
            </div>
            <?php if (count($cerrados) >= 2 && max(array_column($cerrados, 'neto')) > 0): ?>
                <div class="tb-spark" title="Tendencia de los meses cerrados"><?= Graficos::sparkline(array_column(array_slice($cerrados, -11), 'neto')) ?><span class="muted">Últimos meses cerrados</span></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php foreach ($tb['tiles'] as $k): ?>
        <a class="tb-card tb-tile" href="<?= e(url($k['url'])) ?>">
            <span class="tb-label"><?= e($k['label']) ?></span>
            <span class="tb-tile-n"><?= e($k['valor']) ?></span>
            <span class="tb-sub"><?= e($k['sub']) ?></span>
        </a>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($v !== null):
    $ac = $tb['acumulado']; ?>
<section class="tb-fila tb-fila-2">
    <figure class="tb-card tb-graf">
        <figcaption class="tb-graf-cab">
            <span class="tb-titulo">Venta acumulada del mes</span>
            <span class="tb-leyenda"><span><i class="tb-key tb-key-marca"></i><?= e(ucfirst($ac['nombre'])) ?></span><span><i class="tb-key tb-key-comp"></i><?= e(ucfirst($ac['nombre_anterior'])) ?></span></span>
        </figcaption>
        <div class="tb-lienzo" data-tb-grafico="lineas"><?= Graficos::lineas($ac['actual'], $ac['anterior'], $ac['dias_mes'], $ac['nombre'], $ac['nombre_anterior']) ?><div class="tb-tip" hidden></div></div>
        <details class="tb-datos">
            <summary>Ver como tabla</summary>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Día</th><th class="num"><?= e(ucfirst($ac['nombre'])) ?></th><th class="num"><?= e(ucfirst($ac['nombre_anterior'])) ?></th></tr></thead>
                <tbody>
                <?php for ($i = 0, $n = max(count($ac['actual']), count($ac['anterior'])); $i < $n; $i++): ?>
                    <tr><td><?= $i + 1 ?></td><td class="num"><?= isset($ac['actual'][$i]) ? e(Tablero::pesos($ac['actual'][$i])) : '' ?></td><td class="num"><?= isset($ac['anterior'][$i]) ? e(Tablero::pesos($ac['anterior'][$i])) : '' ?></td></tr>
                <?php endfor; ?>
                </tbody>
            </table></div>
        </details>
    </figure>
    <figure class="tb-card tb-graf">
        <figcaption class="tb-graf-cab">
            <span class="tb-titulo">Venta por mes</span>
            <span class="muted tb-chico">Últimos 12 meses · con IVA y las NC restadas</span>
        </figcaption>
        <div class="tb-lienzo" data-tb-grafico="columnas"><?= Graficos::columnas($tb['meses']) ?><div class="tb-tip" hidden></div></div>
        <details class="tb-datos">
            <summary>Ver como tabla</summary>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Mes</th><th class="num">Venta</th></tr></thead>
                <tbody>
                <?php foreach ($tb['meses'] as $m): ?>
                    <tr><td><?= e(ucfirst(Tablero::mesLargo($m['mes'])) . ($m['parcial'] ? ' (en curso)' : '')) ?></td><td class="num"><?= e(Tablero::pesos($m['neto'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </details>
    </figure>
</section>

<section class="tb-fila tb-fila-3">
    <?php foreach ($tb['rankings'] as $r):
        $tope = $r['filas'] === [] ? 1.0 : max(1.0, ...array_map(static fn ($x) => (float) $x['total'], $r['filas'])); ?>
        <div class="tb-card">
            <div class="tb-graf-cab"><h2 class="tb-titulo"><?= e($r['titulo']) ?></h2><a class="tb-chico" href="<?= e(url($r['url'])) ?>">Ver estadística</a></div>
            <p class="muted tb-chico"><?= e($r['sub']) ?></p>
            <?php if ($r['filas'] === []): ?>
                <p class="muted">Sin ventas en el período.</p>
            <?php else: ?>
                <ol class="tb-barras">
                    <?php foreach ($r['filas'] as $x): ?>
                        <li><span class="tb-barra-nombre"><?= e((string) ($x['nombre'] ?? $x['grupo'])) ?></span>
                            <span class="tb-barra"><i style="width: <?= number_format(max(1.0, (float) $x['total'] / $tope * 78), 1, '.', '') ?>%"></i><b><?= e(Tablero::corto((float) $x['total'])) ?></b></span></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($tb['cobros'] !== null):
        $c = $tb['cobros']; ?>
        <div class="tb-card">
            <div class="tb-graf-cab"><h2 class="tb-titulo">Cómo te pagaron</h2><a class="tb-chico" href="<?= e(url('/listados/caja-diaria?' . http_build_query(['desde' => date('d/m/Y', strtotime($per['desde'])), 'hasta' => date('d/m/Y', strtotime($per['hasta']))]))) ?>">Caja diaria</a></div>
            <p class="muted tb-chico">Cobrado en el período: <?= e(Tablero::pesos($c['total'])) ?></p>
            <?php if ($c['total'] <= 0): ?>
                <p class="muted">Todavía no hay cobros en el período.</p>
            <?php else:
                $partes = [];
                foreach ($c['grupos'] as $i => $g) {
                    if ($g['importe'] > 0) {
                        $partes[] = $g + ['i' => $i + 1, 'pct' => $g['importe'] / $c['total'] * 100];
                    }
                } ?>
                <div class="tb-seg" role="img" aria-label="<?= e(implode('; ', array_map(static fn ($p) => $p['nombre'] . ' ' . Tablero::pesos($p['importe']) . ' (' . round($p['pct']) . ' %)', $partes))) ?>">
                    <?php foreach ($partes as $p): ?>
                        <span class="tb-seg-<?= (int) $p['i'] ?>" style="flex: <?= number_format($p['pct'], 2, '.', '') ?> 1 0" title="<?= e($p['nombre'] . ': ' . Tablero::pesos($p['importe'])) ?>"><?= $p['pct'] >= 12 ? round($p['pct']) . ' %' : '' ?></span>
                    <?php endforeach; ?>
                </div>
                <ul class="tb-ley-lista">
                    <?php foreach ($partes as $p): ?>
                        <li><i class="tb-seg-<?= (int) $p['i'] ?>"></i><span><?= e($p['nombre']) ?></span><span class="num muted"><?= e(Tablero::corto($p['importe'])) ?> · <?= round($p['pct']) ?> %</span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php foreach ($tb['extras'] as $x): ?>
    <section class="tb-fila"><?= View::render($x['vista'], ['tb' => $tb], null) ?></section>
<?php endforeach; ?>

<section class="tb-fila tb-fila-2">
    <?php if ($tb['alertas'] !== [] || $v !== null): ?>
    <div class="tb-card">
        <h2 class="tb-titulo">Para atender</h2>
        <?php if ($tb['alertas'] === []): ?>
            <p class="tb-ok"><span class="tb-ic tb-nivel-ok"><?= $icono('M5 12l5 5L20 7') ?></span> Nada pendiente por ahora.</p>
        <?php else: ?>
            <ul class="tb-alertas">
                <?php foreach ($tb['alertas'] as $a): ?>
                    <li><span class="tb-ic tb-nivel-<?= e($a['nivel']) ?>"><?= $icono($iconos[$a['icono']] ?? $iconos['alerta']) ?></span>
                        <span><b><?= e($a['titulo']) ?></b><br><span class="muted tb-chico"><?= e($a['sub']) ?></span></span>
                        <a href="<?= e(url($a['url'])) ?>"><?= e($a['cta']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($tb['accesos'] !== []): ?>
    <div class="tb-card">
        <h2 class="tb-titulo">Accesos rápidos</h2>
        <div class="tb-accesos">
            <?php foreach ($tb['accesos'] as $q): ?>
                <a href="<?= e(url($q['ruta'])) ?>"><span class="tb-ic tb-nivel-marca"><?= $icono('M5 12h14 M13 6l6 6-6 6') ?></span><?= e($q['texto']) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</section>

<details class="tb-menu"<?= $v === null && $tb['tiles'] === [] ? ' open' : '' ?>>
    <summary>Todo el menú</summary>
    <?php foreach (Menu::grupos() as $grupo => $items): ?>
        <h2><?= e($grupo) ?></h2>
        <div class="tiles">
            <?php foreach ($items as $o): ?>
                <a class="tile" href="<?= e(url($o['ruta'])) ?>"><span class="tile-title"><?= e($o['texto']) ?></span></a>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</details>
</div>
<script src="<?= e(asset('assets/tablero.js')) ?>" defer></script>
