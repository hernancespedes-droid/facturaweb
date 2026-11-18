<?php
use App\Modules\Comprobantes\Tipos;

/** @var array<string,string> $f */
/** @var array<int,array<string,mixed>> $filas */
/** @var int $total */
/** @var float $suma */
/** @var int $pagina */
/** @var int $porPagina */
$link = static fn (int $p) => url('/comprobantes') . '?' . http_build_query(['pagina' => $p] + $f);
$paginas = (int) ceil($total / max(1, $porPagina));
?>
<div class="page-head">
    <h1>Comprobantes</h1>
    <?php if (puede('comprobantes.emitir')): ?>
        <div class="acciones">
            <a class="btn" href="<?= e(url('/comprobantes/nuevo') . '?tipo=FC') ?>">+ Nueva factura</a>
            <a class="btn btn-ghost" href="<?= e(url('/comprobantes/nuevo') . '?tipo=PR') ?>">Presupuesto</a>
            <?php if (\App\Modules\Cambios\CambiosService::habilitado() && puede('cambios.hacer')): ?><a class="btn btn-ghost" href="<?= e(url('/cambios')) ?>">Leer ticket de cambio</a><?php endif; ?>
            <a class="btn btn-ghost" href="<?= e(url('/comprobantes/nuevo') . '?tipo=NC') ?>">Nota de crédito</a>
            <a class="btn btn-ghost" href="<?= e(url('/comprobantes/nuevo') . '?tipo=ND') ?>">Nota de débito</a>
        </div>
    <?php endif; ?>
</div>

<form method="get" action="<?= e(url('/comprobantes')) ?>" class="toolbar filtros">
    <label class="field"><span>Desde</span><input name="desde" value="<?= e($f['desde'] ?? '') ?>" placeholder="dd/mm/aaaa" inputmode="numeric"></label>
    <label class="field"><span>Hasta</span><input name="hasta" value="<?= e($f['hasta'] ?? '') ?>" placeholder="dd/mm/aaaa" inputmode="numeric"></label>
    <select name="tipo" aria-label="Tipo"><option value="">Todos los tipos</option>
        <?php foreach (Tipos::NOMBRES as $k => $t): ?><option value="<?= $k ?>" <?= ($f['tipo'] ?? '') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
    <input name="cliente" value="<?= e($f['cliente'] ?? '') ?>" placeholder="Cliente, código o CUIT" type="search" aria-label="Cliente">
    <input name="numero" value="<?= e($f['numero'] ?? '') ?>" placeholder="Número" inputmode="numeric" aria-label="Número" size="9">
    <select name="estado" aria-label="Estado"><?php foreach (['' => 'Vigentes', 'anulados' => 'Anulados', 'todos' => 'Todos'] as $k => $t): ?>
        <option value="<?= $k ?>" <?= ($f['estado'] ?? '') === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select>
    <button class="btn btn-ghost" type="submit">Buscar</button>
</form>

<?php if ($filas === []): ?>
    <p class="muted vacio">No hay comprobantes para esos filtros.</p>
<?php else: ?>
    <?= paginador($pagina, $paginas, $total, $porPagina, $link, true) ?>
    <div class="table-wrap">
        <table class="table table-cards">
            <?php $u = url('/comprobantes'); ?><thead><tr><?= th_orden('Fecha', 'fecha', $f, $u, true) ?><?= th_orden('Comprobante', 'numero', $f, $u) ?><?= th_orden('Cliente', 'cliente', $f, $u) ?><?= th_orden('Pago', 'pago', $f, $u) ?><?= th_orden('Total', 'total', $f, $u, true) ?><?= th_orden('Estado', 'estado', $f, $u) ?></tr></thead>
            <tbody>
            <?php foreach ($filas as $c): [$estadoTxt, $estadoClase] = estado_comprobante($c); ?>
                <tr>
                    <td data-label="Fecha" class="nw"><?= e(fecha($c['fecha'])) ?></td>
                    <td data-label="Comprobante" class="nw"><a href="<?= e(url('/comprobantes/' . $c['fe_clave'])) ?>"><b><?= e(Tipos::titulo($c['tipo'], $c['letra'])) ?></b> <?= e(numero_visible($c)) ?></a></td>
                    <td data-label="Cliente"><?= e($c['razon_social']) ?> <small class="muted">(<?= e($c['cliente']) ?>)</small></td>
                    <td data-label="Pago"><?= (int) $c['forma_pago'] === 2 ? 'Cta. cte.' : 'Contado' ?></td>
                    <td data-label="Total" class="nw"><?= in_array($c['tipo'], ['NC', 'NX'], true) ? '−' : '' ?><?= e(plata_dec($c['neto'])) ?></td>
                    <td data-label="Estado"><span class="badge <?= e($estadoClase) ?>"><?= e($estadoTxt) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= paginador($pagina, $paginas, $total, $porPagina, $link) ?>
    <p class="small muted"><?= number_format($total, 0, ',', '.') ?> comprobante(s) · total del filtro (las notas de crédito restan): <b><?= e(plata_dec($suma)) ?></b></p>
<?php endif; ?>
