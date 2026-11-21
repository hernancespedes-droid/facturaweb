<?php
use App\Modules\MercadoPago\IngresosService;

/** @var array<string,string> $f */
/** @var array{filas:array<int,array<string,mixed>>,total:int,suma:float,suma_neto:float} $r */
/** @var int $pagina */
/** @var int $porPagina */
/** @var int $visto */
/** @var array{sin_ver:int,ultimo_id:int} $novedades */
/** @var bool $simulado */
/** @var string $etiqueta */
/** @var string[] $mails */
/** @var bool $admin */
$u = url('/mercadopago/ingresos');
$link = static fn (int $p) => $u . '?' . http_build_query(['pagina' => $p] + $f);
$paginas = (int) ceil($r['total'] / max(1, $porPagina));
$plata = static fn ($v) => number_format((float) $v, 2, ',', '.');
?>
<div class="page-head"><div><p class="eyebrow">Mercado Pago</p><h1>Ingresos de Mercado Pago</h1>
    <p class="muted small">Toda la plata que entra a la cuenta, venga o no de una venta cobrada desde acá. <?= e($etiqueta) ?>.</p></div>
    <div class="toolbar">
        <?php if ($novedades['sin_ver'] > 0): ?>
            <form method="post" action="<?= e(url('/mercadopago/ingresos/vistos')) ?>"><?= csrf_field() ?><input type="hidden" name="hasta" value="<?= (int) $novedades['ultimo_id'] ?>">
                <button class="btn" type="submit" data-mp-vistos>Marcar como vistos (<?= (int) $novedades['sin_ver'] ?>)</button></form>
        <?php endif; ?>
        <button type="button" class="btn btn-ghost" data-mp-sonido>Sonido: prendido</button>
    </div>
</div>
<?php if ($simulado): ?>
<form method="post" action="<?= e(url('/mercadopago/ingresos/simular')) ?>" class="toolbar filtros">
        <?= csrf_field() ?>
        <b>Modo simulado:</b>
        <label class="field"><span>Importe</span><input name="monto" inputmode="decimal" data-decimales="2" required placeholder="0,00"></label>
        <label class="field"><span>Quién paga</span><input name="pagador" maxlength="100" placeholder="Cliente de prueba"></label>
        <button class="btn btn-ghost" type="submit">Simular ingreso</button>
</form>
<?php endif; ?>
<?php if ($admin): ?>
    <p class="muted small">Aviso por mail: <?= $mails === [] ? 'no se manda (se cargan los mails en Sistema → Configuración, «mercadopago.ingresos_mails»)' : e(implode(', ', $mails)) ?>.</p>
<?php endif; ?>
<form method="get" action="<?= e($u) ?>" class="toolbar filtros">
    <label class="field"><span>Desde</span><input type="date" name="desde" value="<?= e($f['desde'] ?? '') ?>"></label>
    <label class="field"><span>Hasta</span><input type="date" name="hasta" value="<?= e($f['hasta'] ?? '') ?>"></label>
    <label class="field"><span>Medio</span><select name="medio"><option value="">Todos</option>
        <?php foreach (IngresosService::MEDIOS as $k => $t): ?><option value="<?= e($k) ?>" <?= ($f['medio'] ?? '') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Buscar</span><input type="search" name="texto" value="<?= e($f['texto'] ?? '') ?>" placeholder="Quién pagó, descripción u operación"></label>
    <button class="btn btn-ghost" type="submit">Ver</button>
</form>
<div class="kpis">
    <div class="kpi"><span>Ingresos</span><b data-mp-total><?= (int) $r['total'] ?></b></div>
    <div class="kpi"><span>Total acreditado</span><b>$ <?= e($plata($r['suma'])) ?></b></div>
    <div class="kpi"><span>Neto (sin comisiones)</span><b>$ <?= e($plata($r['suma_neto'])) ?></b></div>
</div>
<?php if ($r['filas'] === []): ?>
    <p class="muted vacio">No entró plata en estas fechas.</p>
<?php else: ?>
    <?= paginador($pagina, $paginas, $r['total'], $porPagina, $link, true) ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><?= th_orden('Fecha y hora', 'fecha', $f, $u, true) ?><?= th_orden('Importe', 'monto', $f, $u, true) ?><?= th_orden('Neto', 'neto', $f, $u, true) ?><?= th_orden('Medio', 'medio', $f, $u) ?><?= th_orden('Quién pagó', 'pagador', $f, $u) ?><?= th_orden('Descripción', 'descripcion', $f, $u) ?><th>Estado</th><th>Venta</th><th>Operación</th></tr></thead>
        <tbody>
        <?php foreach ($r['filas'] as $x): ?>
            <tr class="<?= (int) $x['id'] > $visto ? 'mp-no-visto' : '' ?>">
                <td data-label="Fecha y hora" class="nw"><?= e(date('d/m/Y H:i', (int) strtotime((string) $x['fecha']))) ?><?= (int) $x['id'] > $visto ? ' <b class="small">nuevo</b>' : '' ?></td>
                <td data-label="Importe" class="num"><?= e($plata($x['monto'])) ?><?= $x['moneda'] !== 'ARS' ? ' ' . e((string) $x['moneda']) : '' ?></td>
                <td data-label="Neto" class="num"><?= $x['neto'] !== null ? e($plata($x['neto'])) : '—' ?></td>
                <td data-label="Medio"><?= e(IngresosService::medio((string) $x['medio'])) ?></td>
                <td data-label="Quién pagó"><?= e((string) $x['pagador']) ?><?php $extra = trim($x['pagador_mail'] . ' ' . $x['pagador_doc']); ?><?= $extra !== '' ? '<br><span class="small muted">' . e($extra) . '</span>' : '' ?></td>
                <td data-label="Descripción"><?= e((string) $x['descripcion']) ?></td>
                <td data-label="Estado"><b class="<?= $x['estado'] !== 'approved' ? 'texto-error' : '' ?>"><?= e(IngresosService::estado((string) $x['estado'])) ?></b><?= $x['modo'] === 'S' ? ' <span class="muted small">(simulado)</span>' : '' ?></td>
                <td data-label="Venta"><?php if ($x['comprobante_id'] !== null): ?><a href="<?= e(url('/comprobantes/' . (int) $x['comprobante_id'])) ?>">Ver comprobante</a>
                    <?php elseif ($x['cobro_id'] !== null): ?>Cobro de la web<?php else: ?><span class="muted">Sin venta asociada</span><?php endif; ?></td>
                <td data-label="Operación" class="small"><?= e((string) $x['mp_pago_id']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
<script>
(function () {
  var b = document.querySelector('[data-mp-sonido]');
  function pintar() { var on = !window.MpIngresos || window.MpIngresos.sonidoActivo(); b.textContent = 'Sonido: ' + (on ? 'prendido' : 'apagado'); b.setAttribute('aria-pressed', on ? 'true' : 'false'); }
  b.addEventListener('click', function () { if (window.MpIngresos) { window.MpIngresos.sonido(!window.MpIngresos.sonidoActivo()); } pintar(); });
  document.addEventListener('DOMContentLoaded', pintar);
  // entró plata mientras se mira la grilla: se recarga para mostrarla (si no se está escribiendo en un filtro)
  document.addEventListener('mp-ingresos', function () { if (!document.activeElement || !/INPUT|SELECT/.test(document.activeElement.tagName)) setTimeout(function () { location.reload(); }, 2500); });
})();
</script>
