<?php
/**
 * Actualización de precios por filtro (0.60.0, D-144…D-146), como la pantalla del VFP: filtros desde/hasta, qué se actualiza, forma y valor;
 * «Ver cómo quedan» muestra la vista previa (GET) y «Confirmar» graba (POST con la firma de la vista previa). Abajo, las últimas y deshacer.
 * @var array<string,mixed> $d @var array<string,string> $errores @var ?array $prev @var array $cfg @var array<string,string> $rubros
 * @var array<int,array<string,mixed>> $registro @var ?int $deshacible
 */
use App\Modules\Articulos\ActualizacionMasiva as AM;
use App\Modules\Articulos\Precios;

$m = static fn ($v) => number_format((float) $v, 2, ',', '.');
$err = static fn (string $k) => isset($errores[$k]) ? '<small class="field-error">' . e($errores[$k]) . '</small>' : '';
$cls = static fn (string $k) => isset($errores[$k]) ? ' has-error' : '';
$red = \App\Modules\Tablas\Definiciones::REDONDEOS[(string) $cfg['redondeo']] ?? '';
$buscar = url('/articulos/actualizacion/buscar-articulo');
$columnas = $prev === null ? [] : ($d['que'] === 'lista' ? [$d['lista']] : ($d['que'] === 'costo' ? [] : array_keys(Precios::LISTAS)));
$ayudaValor = ['coeficiente' => 'Multiplica: 1,10 = 10 % más; 0,95 = 5 % menos.', 'porcentaje' => 'Suma el porcentaje (negativo baja): 8 = 8 % más.',
    'monto' => 'Suma ese importe en pesos a cada precio (negativo resta). No se aplica a los artículos en dólares.'];
?>
<p><a href="<?= e(url('/articulos')) ?>">← Artículos</a></p>
<div class="page-head"><div><p class="eyebrow">Artículos</p><h1>Actualización de precios</h1></div>
    <div class="actions"><a class="btn btn-ghost" href="<?= e(url('/articulos/precios')) ?>">Precios por margen</a></div></div>
<p class="small muted">Aumentá (o bajá) los precios de un grupo de artículos aunque el costo no cambie. Primero se ve cómo quedan; nada se graba hasta que confirmás, y la última actualización se puede deshacer.</p>

<form method="get" action="<?= e(url('/articulos/actualizacion')) ?>" class="card actualizacion-form" data-enter="enviar">
    <input type="hidden" name="vista" value="1"><input type="hidden" name="enviado" value="1">
    <div class="actualizacion-grid">
        <fieldset class="desde-hasta"><legend>Filtros</legend>
            <span></span><span class="dh-tit">Desde</span><span class="dh-tit">Hasta</span>
            <span class="dh-lbl">Código</span>
            <?php foreach (['desde', 'hasta'] as $x): ?>
                <div class="field<?= $cls('codigo_' . $x) ?>"><input name="codigo_<?= $x ?>" value="<?= e($d['codigo_' . $x]) ?>" autocomplete="off" maxlength="15"
                    placeholder="<?= $x === 'desde' ? 'El primero' : 'El último' ?>" data-buscador-url="<?= e($buscar) ?>" aria-label="Código <?= $x ?>"><?= $err('codigo_' . $x) ?></div>
            <?php endforeach; ?>
            <span class="dh-lbl">Proveedor</span>
            <?php foreach (['desde', 'hasta'] as $x): ?>
                <div class="field<?= $cls('proveedor_' . $x) ?>"><input name="proveedor_<?= $x ?>" value="<?= e($d['proveedor_' . $x]) ?>" inputmode="numeric" maxlength="5" placeholder="Código" aria-label="Proveedor <?= $x ?>"><?= $err('proveedor_' . $x) ?></div>
            <?php endforeach; ?>
            <span class="dh-lbl">Rubro</span>
            <?php foreach (['desde', 'hasta'] as $x): ?>
                <div class="field<?= $cls('rubro_' . $x) ?>"><select name="rubro_<?= $x ?>" aria-label="Rubro <?= $x ?>"><option value=""><?= $x === 'desde' ? 'El primero' : 'El último' ?></option>
                    <?php foreach ($rubros as $k => $t): ?><option value="<?= e((string) $k) ?>" <?= $d['rubro_' . $x] === (string) $k ? 'selected' : '' ?>><?= e($k . ' - ' . $t) ?></option><?php endforeach; ?></select><?= $err('rubro_' . $x) ?></div>
            <?php endforeach; ?>
            <span class="dh-lbl">Sub rubro</span>
            <?php foreach (['desde', 'hasta'] as $x): ?>
                <div class="field<?= $cls('subrubro_' . $x) ?>"><input name="subrubro_<?= $x ?>" value="<?= e($d['subrubro_' . $x]) ?>" maxlength="3" placeholder="Código" aria-label="Sub rubro <?= $x ?>"><?= $err('subrubro_' . $x) ?></div>
            <?php endforeach; ?>
            <p class="small muted dh-nota">Vacío = sin límite. Solo artículos activos.</p>
        </fieldset>

        <fieldset><legend>Precios</legend>
            <div class="checks radios-col">
                <?php foreach (AM::QUE as $k => $t): ?><label class="check"><input type="radio" name="que" value="<?= e($k) ?>" <?= $d['que'] === $k ? 'checked' : '' ?> data-que> <?= e($t) ?></label><?php endforeach; ?>
            </div>
            <label class="field" data-solo-lista><span>Lista</span><select name="lista">
                <?php foreach (Precios::LISTAS as $k => [, $n]): ?><option value="<?= e($k) ?>" <?= $d['lista'] === $k ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></label>
        </fieldset>

        <fieldset><legend>Forma de actualización</legend>
            <div class="checks radios-col">
                <?php foreach (AM::FORMAS as $k => $t): ?><label class="check"><input type="radio" name="forma" value="<?= e($k) ?>" <?= $d['forma'] === $k ? 'checked' : '' ?> data-forma> <?= e($t) ?></label><?php endforeach; ?>
            </div>
            <label class="field<?= $cls('valor') ?>"><span data-valor-titulo><?= e(AM::FORMAS[$d['forma']]) ?></span>
                <input name="valor" value="<?= e($d['valor_texto']) ?>" data-decimales="4" inputmode="decimal" autocomplete="off" required autofocus>
                <small class="muted" data-valor-ayuda><?= e($ayudaValor[$d['forma']]) ?></small><?= $err('valor') ?></label>
        </fieldset>
    </div>
    <div class="actualizacion-opciones">
        <label class="check"><input type="checkbox" name="redondear" value="1" <?= $d['redondear'] ? 'checked' : '' ?>> Redondear los precios hacia arriba como en Parámetros (<?= e((int) $cfg['redondeo'] > 0 ? mb_strtolower($red) : 'hoy no tiene redondeo: a centavos') ?>)</label>
        <label class="field field-inline" data-solo-listas><span>Artículos con «Precio automático»</span><select name="automaticos">
            <?php foreach (AM::AUTOMATICOS as $k => $t): ?><option value="<?= e($k) ?>" <?= $d['automaticos'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
        <p class="small muted" data-solo-costo>En los artículos con «Precio automático», al cambiar el costo las listas se recalculan con sus márgenes.</p>
    </div>
    <div class="actions"><button class="btn" type="submit">Ver cómo quedan</button></div>
</form>

<?php if ($prev !== null): ?>
<section class="card" id="vista-previa">
    <h2>Vista previa</h2>
    <p><b><?= e(AM::descripcion($d)) ?></b></p>
    <div class="kpis">
        <div class="kpi"><span class="kpi-n"><?= e(number_format($prev['cambian'], 0, ',', '.')) ?></span><span class="kpi-l">artículos cambian</span></div>
        <?php if ($prev['a_manual'] > 0): ?><div class="kpi kpi-warn"><span class="kpi-n"><?= e(number_format($prev['a_manual'], 0, ',', '.')) ?></span><span class="kpi-l">pasan de precio automático a manual</span></div><?php endif; ?>
        <?php $salt = array_sum(array_map('count', $prev['salteados'])); if ($salt > 0): ?><div class="kpi kpi-warn"><span class="kpi-n"><?= e(number_format($salt, 0, ',', '.')) ?></span><span class="kpi-l">no se tocan (ver abajo)</span></div><?php endif; ?>
        <?php if ($prev['sin_cambio'] > 0): ?><div class="kpi"><span class="kpi-n"><?= e(number_format($prev['sin_cambio'], 0, ',', '.')) ?></span><span class="kpi-l">quedan igual (precio en 0 o redondeo)</span></div><?php endif; ?>
        <?php if ($prev['suba']['antes'] > 0): ?><div class="kpi"><span class="kpi-n"><?= e(number_format(($prev['suba']['despues'] / $prev['suba']['antes'] - 1) * 100, 1, ',', '.')) ?> %</span>
            <span class="kpi-l">cambio promedio de <?= e($d['que'] === 'costo' ? 'el costo' : Precios::LISTAS[$d['que'] === 'lista' ? $d['lista'] : 'l1'][1]) ?></span></div><?php endif; ?>
    </div>
    <?php if ($prev['salteados'] !== []): ?>
        <details class="small"><summary>Los que no se tocan y por qué</summary><ul>
            <?php foreach ($prev['salteados'] as $motivo => $cods): ?><li><b><?= e($motivo) ?></b> (<?= count($cods) ?>): <?= e(implode(', ', array_slice($cods, 0, 40))) ?><?= count($cods) > 40 ? '…' : '' ?></li><?php endforeach; ?>
        </ul></details>
    <?php endif; ?>
    <?php if ($prev['cambian'] === 0): ?>
        <p class="muted vacio">Con esos filtros no cambia ningún precio.</p>
    <?php else: ?>
        <?php if ($prev['limite']): ?><p class="small muted">Se muestran los primeros <?= count($prev['filas']) ?>; al confirmar se actualizan los <?= e(number_format($prev['cambian'], 0, ',', '.')) ?>.</p><?php endif; ?>
        <div class="table-wrap"><table class="table table-cards">
            <thead><tr><th>Código</th><th>Descripción</th><th>Rubro</th>
                <th class="num">Costo</th>
                <?php foreach (Precios::LISTAS as $k => [, $n]): ?><?php if (in_array($k, $columnas, true) || $d['que'] === 'costo'): ?><th class="num"><?= e($n) ?></th><?php endif; ?><?php endforeach; ?>
                <th>Automático</th></tr></thead>
            <tbody>
            <?php foreach ($prev['filas'] as $x): $a = $x['a']; $pl = $x['plan']; ?>
                <tr><td data-label="Código" class="nw"><a href="<?= e(url('/articulos/ficha') . '?' . http_build_query(['codigo' => $a['codigo']])) ?>"><?= e($a['codigo']) ?></a></td>
                    <td data-label="Descripción"><?= e($a['descripcion']) ?><?= $a['moneda'] === 'u$s' ? ' <span class="chip">u$s</span>' : '' ?></td>
                    <td data-label="Rubro"><?= e((string) ($a['rubro_nombre'] ?? $a['rubro'] ?? '')) ?></td>
                    <?php foreach (array_merge(['costo'], array_keys(Precios::LISTAS)) as $k): ?>
                        <?php if ($k !== 'costo' && !in_array($k, $columnas, true) && $d['que'] !== 'costo') continue; ?>
                        <td data-label="<?= e($k === 'costo' ? 'Costo' : Precios::LISTAS[$k][1]) ?>" class="num"><?php $an = $pl['antes'][$k]; $de = $pl['despues'][$k]; ?>
                            <?php if (abs($de - $an) < 0.00005): ?><?= e($m($an)) ?><?php else: ?><s class="muted"><?= e($m($an)) ?></s> <b><?= e($m($de)) ?></b><?php endif; ?>
                            <?php if (in_array($k, $pl['recalculadas'], true)): ?><br><span class="small muted">por margen</span><?php endif; ?></td>
                    <?php endforeach; ?>
                    <td data-label="Automático"><?= (int) $a['precio_automatico'] === 1 ? ($pl['a_manual'] ? '<span class="chip">pasa a manual</span>' : 'Sí') : 'No' ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <form method="post" action="<?= e(url('/articulos/actualizacion')) ?>" class="actions actualizacion-confirmar" data-confirmar="Se van a actualizar <?= e(number_format($prev['cambian'], 0, ',', '.')) ?> artículos. ¿Seguimos?">
            <?= csrf_field() ?>
            <?php foreach (array_keys(AM::FILTROS) as $k): ?><input type="hidden" name="<?= $k ?>_desde" value="<?= e($d[$k . '_desde']) ?>"><input type="hidden" name="<?= $k ?>_hasta" value="<?= e($d[$k . '_hasta']) ?>"><?php endforeach; ?>
            <?php foreach (['que', 'lista', 'forma', 'automaticos'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= e((string) $d[$k]) ?>"><?php endforeach; ?>
            <input type="hidden" name="valor" value="<?= e($d['valor_texto']) ?>"><input type="hidden" name="redondear" value="<?= $d['redondear'] ? '1' : '0' ?>">
            <input type="hidden" name="enviado" value="1"><input type="hidden" name="firma" value="<?= e($prev['firma']) ?>">
            <button class="btn" type="submit" data-confirmar-actualizacion>Confirmar: actualizar <?= e(number_format($prev['cambian'], 0, ',', '.')) ?> artículo<?= $prev['cambian'] === 1 ? '' : 's' ?></button>
            <span class="small muted">Queda registrada y se puede deshacer.</span>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="card" id="registro">
    <h2>Últimas actualizaciones</h2>
    <?php if ($registro === []): ?>
        <p class="muted vacio">Todavía no se hizo ninguna.</p>
    <?php else: ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th>N.º</th><th>Fecha</th><th>Usuario</th><th>Qué se hizo</th><th class="num">Artículos</th><th>Estado</th></tr></thead>
        <tbody>
        <?php foreach ($registro as $x): ?>
            <tr><td data-label="N.º"><?= (int) $x['id'] ?></td>
                <td data-label="Fecha" class="nw"><?= e(date('d/m/Y H:i', strtotime((string) $x['fecha']))) ?></td>
                <td data-label="Usuario"><?= e((string) ($x['usuario'] ?? '')) ?></td>
                <td data-label="Qué se hizo"><?= e((string) $x['descripcion']) ?><?= (int) $x['a_manual'] > 0 ? '<br><span class="small muted">' . (int) $x['a_manual'] . ' pasaron a precio manual</span>' : '' ?></td>
                <td data-label="Artículos" class="num"><?= e(number_format((int) $x['articulos'], 0, ',', '.')) ?></td>
                <td data-label="Estado"><?php if ($x['deshecha_en'] !== null): ?>Deshecha el <?= e(date('d/m/Y H:i', strtotime((string) $x['deshecha_en']))) ?><?= $x['deshizo'] ? ' por ' . e((string) $x['deshizo']) : '' ?>
                        <?= (int) $x['no_deshechos'] > 0 ? '<br><span class="small muted">' . (int) $x['no_deshechos'] . ' quedaron (cambiaron después)</span>' : '' ?>
                    <?php elseif ((int) $x['id'] === $deshacible): ?>
                        <form method="post" action="<?= e(url('/articulos/actualizacion/deshacer')) ?>" data-confirmar="Los artículos de la actualización N.º <?= (int) $x['id'] ?> vuelven a los precios de antes (salvo los que se cambiaron después). ¿Seguimos?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $x['id'] ?>">
                            <button class="btn btn-ghost btn-sm" type="submit" data-deshacer>Deshacer</button></form>
                    <?php else: ?>Vigente<?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>
<script src="<?= e(asset('assets/ventana-lista.js')) ?>" defer></script>
<script src="<?= e(asset('assets/buscador.js')) ?>" defer></script>
<script src="<?= e(asset('assets/actualizacion-precios.js')) ?>" defer></script>
