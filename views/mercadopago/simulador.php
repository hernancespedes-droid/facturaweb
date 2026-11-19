<?php /** @var array<string,mixed> $c */ ?>
<div class="page-head"><div><p class="eyebrow">Mercado Pago (simulado)</p><h1>Simulador de pago</h1></div></div>
<section class="card">
    <p>Así vería el cliente la pantalla de pago de Mercado Pago. Es una simulación: no se cobra nada.</p>
    <p class="total-cobrar"><?= e($c['descripcion']) ?> · <b><?= e(plata_dec((float) $c['importe'])) ?></b></p>
    <?php if ($c['estado'] === 'pendiente'): ?>
        <form method="post" action="<?= e(url('/mercadopago/simulador/' . $c['token'] . '/pagar')) ?>" class="inline"><?= csrf_field() ?>
            <button class="btn" type="submit">Simular pago aprobado</button></form>
    <?php else: ?>
        <p class="muted">Este cobro está «<?= e(\App\Modules\MercadoPago\MercadoPagoService::ESTADOS[$c['estado']] ?? $c['estado']) ?>».</p>
    <?php endif; ?>
    <p><a href="<?= e(url('/mercadopago/cobro/' . $c['token'])) ?>">Volver al cobro</a></p>
</section>
