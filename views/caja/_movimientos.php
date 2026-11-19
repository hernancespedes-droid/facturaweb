<?php
/**
 * Tabla de movimientos de caja, como la lee el dueño: el CONCEPTO dice qué es (venta, cobranza de un recibo, ingreso o egreso de dinero) y el importe va en
 * «Entra» o en «Sale». Los ingresos y egresos de dinero NO son ventas: se distinguen y no suman a lo vendido.
 *
 * @var array<int,array<string,mixed>> $filas
 * @var array<string,string> $f
 * @var string $u ruta de la pantalla (para ordenar)
 * @var bool $anular muestra el botón Anular en los movimientos manuales
 * @var bool $segundos hora con segundos
 */
$m = static fn ($v) => number_format((float) $v, 2, ',', '.');
$cuando = static fn (?string $t) => $t === null ? '—' : date($segundos ? 'd/m/Y H:i:s' : 'd/m/Y H:i', strtotime($t));
$concepto = static function (array $x): string {
    if ($x['origen'] === 'venta') {
        return 'Venta';
    }
    if ($x['origen'] === 'recibo') {
        return 'Cobranza (recibo)';
    }
    return ($x['mtipo'] === 'I' ? 'Ingreso de dinero' : 'Egreso de dinero') . ($x['anula_id'] !== null ? ' (anulación)' : '');
};
?>
<div class="table-wrap"><table class="table table-cards">
    <thead><tr><?= th_orden('Fecha y hora', 'cuando', $f, $u, true) ?><?= th_orden('Usuario', 'usuario', $f, $u) ?><?= th_orden('Concepto', 'origen', $f, $u) ?><?= th_orden('Detalle', 'detalle', $f, $u) ?><?= th_orden('Forma de pago', 'forma', $f, $u) ?><?= th_orden('Entra', 'importe', $f, $u, true) ?><th class="num">Sale</th><th class="num">Vuelto</th><?= th_orden('Turno', 'turno', $f, $u) ?><?php if ($anular): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($filas as $x): $manual = $x['origen'] === 'manual'; $imp = (float) $x['importe']; ?>
        <tr<?= $manual ? ' class="fila-manual"' : '' ?>>
            <td data-label="Fecha y hora" class="nw"><?= e($cuando($x['cuando'])) ?></td>
            <td data-label="Usuario"><?= e((string) $x['usuario'] !== '' ? (string) $x['usuario'] : '—') ?></td>
            <td data-label="Concepto"><b><?= e($concepto($x)) ?></b><?= $manual && (int) $x['anulado'] > 0 ? ' <span class="muted small">(anulado)</span>' : '' ?></td>
            <td data-label="Detalle"><?= e($x['detalle']) ?></td>
            <td data-label="Forma de pago"><?= e($x['forma']) ?></td>
            <td data-label="Entra" class="num"><?= $imp > 0 ? e($m($imp)) : '' ?></td>
            <td data-label="Sale" class="num"><?= $imp < 0 ? e($m(-$imp)) : '' ?></td>
            <td data-label="Vuelto" class="num"><?= (float) $x['vuelto'] > 0 ? e($m($x['vuelto'])) : '' ?></td>
            <td data-label="Turno"><?= $x['turno_id'] !== null ? (int) $x['turno_id'] : '—' ?></td>
            <?php if ($anular): ?><td class="num"><?php if ($manual && $x['anula_id'] === null && (int) $x['anulado'] === 0): ?>
                <form method="post" action="<?= e(url('/caja/anular')) ?>" data-anular><?= csrf_field() ?>
                    <input type="hidden" name="movimiento" value="<?= (int) $x['id'] ?>"><input type="hidden" name="motivo" value="">
                    <button class="btn btn-ghost btn-sm" type="submit">Anular</button></form>
            <?php endif; ?></td><?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
