<?php
use App\Modules\CuentaCorriente\CuentaCorrienteService;

/** @var array<string,string> $f @var array<int,array<string,mixed>> $filas @var int $total @var float $suma @var int $pagina @var int $porPagina @var bool $puedeCambiar */
$link = static fn (int $p) => url('/cheques') . '?' . http_build_query(['pagina' => $p] + $f);
$paginas = (int) ceil($total / max(1, $porPagina));
$hoy = date('Y-m-d');
$est = (string) ($f['estado'] ?? '');
?>
<div class="page-head">
    <div><p class="eyebrow">Cuenta corriente</p><h1>Cheques</h1></div>
    <div class="acciones"><a class="btn btn-ghost" href="<?= e(url('/cheques') . '?' . http_build_query($f + ['excel' => '1'])) ?>">Excel</a></div>
</div>
<form method="get" action="<?= e(url('/cheques')) ?>" class="toolbar filtros">
    <select name="estado" aria-label="Estado"><option value="">En cartera</option><option value="todos" <?= $est === 'todos' ? 'selected' : '' ?>>Todos</option>
        <?php foreach (['D', 'E', 'R'] as $k): ?><option value="<?= $k ?>" <?= $est === $k ? 'selected' : '' ?>><?= e(CuentaCorrienteService::ESTADOS_CHEQUE[$k]) ?></option><?php endforeach; ?></select>
    <label class="field"><span>Cobro desde</span><input name="desde" value="<?= e($f['desde'] ?? '') ?>" placeholder="dd/mm/aaaa" inputmode="numeric"></label>
    <label class="field"><span>Hasta</span><input name="hasta" value="<?= e($f['hasta'] ?? '') ?>" placeholder="dd/mm/aaaa" inputmode="numeric"></label>
    <input name="q" value="<?= e($f['q'] ?? '') ?>" placeholder="N.º de cheque o cliente" type="search" aria-label="Buscar">
    <label class="check"><input type="checkbox" name="diferidos" value="1" <?= !empty($f['diferidos']) ? 'checked' : '' ?>> Solo diferidos (a cobrar después de hoy)</label>
    <button class="btn btn-ghost" type="submit">Buscar</button>
</form>
<?php if ($filas === []): ?>
    <p class="muted vacio">No hay cheques para esos filtros.</p>
<?php else: ?>
    <div class="resumen-cifras small"><b><?= (int) $total ?></b> cheque(s) · <b>Total:</b> <?= e(plata_dec($suma)) ?></div>
    <?= paginador($pagina, $paginas, $total, $porPagina, $link, true) ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th>Cobro</th><th>N.º</th><th>Banco</th><th>Cliente</th><th class="num">Importe</th><th>Estado</th><?= $puedeCambiar ? '<th></th>' : '' ?></tr></thead>
        <tbody>
        <?php foreach ($filas as $r): $diferido = $r['p_fecvto'] !== null && $r['p_fecvto'] > $hoy; ?>
            <tr><td data-label="Cobro" class="nw"><?= e(fecha($r['p_fecvto'])) ?><?= $diferido && $r['cheque_estado'] === '' ? ' <span class="chip">diferido</span>' : '' ?></td>
                <td data-label="N.º" class="nw"><?= $r['cobra_id'] ? '<a href="' . e(url('/recibos/' . (int) $r['cobra_id'])) . '">' . e($r['p_nro']) . '</a>' : e((string) $r['p_nro']) ?></td>
                <td data-label="Banco"><?= e((string) ($r['banco'] ?? '')) ?></td>
                <td data-label="Cliente"><?= e($r['razon_social'] ?? '') ?></td>
                <td data-label="Importe" class="num nw"><?= e(plata_dec($r['p_importe'])) ?></td>
                <td data-label="Estado"><?= e(CuentaCorrienteService::ESTADOS_CHEQUE[$r['cheque_estado']] ?? '') ?><?= $r['cheque_fecha'] ? ' <small class="muted">' . e(fecha($r['cheque_fecha'])) . '</small>' : '' ?></td>
                <?php if ($puedeCambiar): ?><td>
                    <form method="post" action="<?= e(url('/cheques/' . (int) $r['fe_clave'] . '/estado')) ?>" class="inline-form"><?= csrf_field() ?>
                        <input type="hidden" name="volver" value="<?= e($f === [] ? '' : '?' . http_build_query($f)) ?>">
                        <input type="hidden" name="fecha" value="<?= e(date('d/m/Y')) ?>">
                        <select name="estado" aria-label="Pasar a"><?php foreach (CuentaCorrienteService::ESTADOS_CHEQUE as $k => $t): ?><option value="<?= $k ?>" <?= $r['cheque_estado'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
                        <button class="btn btn-ghost btn-sm" type="submit">Cambiar</button></form></td><?php endif; ?></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
