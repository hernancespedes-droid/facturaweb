<?php
/** Tarjeta de compras en el inicio (Tablero::EXTRAS). @var array $tb */
$r = \App\Modules\Compras\ComprasListados::resumenInicio();
$p = static fn ($v) => '$ ' . number_format((float) $v, 0, ',', '.');
?>
<div class="tb-numeros">
    <a class="tb-card tb-tile" href="<?= e(url('/compras')) ?>">
        <span class="tb-label">Compras y gastos del mes</span>
        <span class="tb-tile-n"><?= e($p($r['gastado'])) ?></span>
        <span class="tb-sub">Sin IVA computable · ver el tablero de compras</span>
    </a>
    <a class="tb-card tb-tile" href="<?= e(url('/compras/a-pagar')) ?>">
        <span class="tb-label">Le debemos a proveedores</span>
        <span class="tb-tile-n"><?= e($p($r['deuda'])) ?></span>
        <span class="tb-sub"><?= $r['vencido'] > 0.005 ? e($p($r['vencido']) . ' vencido') : 'Nada vencido' ?></span>
    </a>
    <a class="tb-card tb-tile" href="<?= e(url('/compras/a-pagar')) ?>">
        <span class="tb-label">A pagar en los próximos 7 días</span>
        <span class="tb-tile-n"><?= e($p($r['semana'])) ?></span>
        <span class="tb-sub"><?= (int) $r['semana_cant'] > 0 ? (int) $r['semana_cant'] . ' comprobantes' : 'Nada por vencer' ?></span>
    </a>
</div>
