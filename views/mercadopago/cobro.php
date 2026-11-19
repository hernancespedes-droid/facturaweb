<?php
use App\Core\Qr;
use App\Modules\MercadoPago\MercadoPagoService;

/** @var array<string,mixed> $c */
/** @var string $etiqueta */
/** @var bool $simulado */
/** @var ?string $urlFinal */
/** @var bool $puedeAdministrar */
$estado = (string) $c['estado'];
$segundos = max(0, (int) strtotime((string) $c['vence_en']) - time());
$wa = 'https://wa.me/?text=' . rawurlencode('Podés pagar tu compra de ' . plata_dec((float) $c['importe']) . ' con Mercado Pago acá: ' . (string) $c['link']);
?>
<div class="page-head"><div><p class="eyebrow">Mercado Pago</p><h1>Cobro con Mercado Pago</h1></div></div>
<?php if ($simulado): ?><div class="alerta alerta-warn"><b><?= e($etiqueta) ?></b> — no hay plata de por medio: el pago se aprueba con un botón.</div><?php endif; ?>

<section class="card mp-cobro" data-mp-cobro data-url-estado="<?= e(url('/mercadopago/cobro/' . $c['token'] . '/estado')) ?>" data-estado="<?= e($estado) ?>" data-segundos="<?= $segundos ?>"
         data-url-final="<?= e((string) $urlFinal) ?>">
    <p class="total-cobrar"><?= e($c['descripcion']) ?> · Total a cobrar: <b><?= e(plata_dec((float) $c['importe'])) ?></b></p>
    <p class="mp-estado" data-mp-estado role="status"><b data-mp-texto><?= e(MercadoPagoService::ESTADOS[$estado] ?? $estado) ?></b>
        <?php if ($estado === 'pendiente'): ?><span class="muted small">· vence en <span data-mp-cuenta></span></span><?php endif; ?></p>
    <?php if ($c['error']): ?><div class="alerta alerta-error" role="alert" data-mp-error><?= e((string) $c['error']) ?></div><?php endif; ?>

    <?php if ($estado === 'pendiente'): ?>
        <div class="mp-medios">
            <?php if ($c['qr_data']): ?>
                <div class="mp-qr"><?= Qr::svg((string) $c['qr_data'], 6, 'QR para pagar con Mercado Pago') ?>
                    <p class="small muted">Escaneá el QR con la app de Mercado Pago (o con la cámara) y pagá.</p></div>
            <?php endif; ?>
            <?php if ($c['link']): ?>
                <div class="mp-link">
                    <p><b>Link de pago</b> (para mandarle al cliente):</p>
                    <input readonly value="<?= e((string) $c['link']) ?>" data-mp-link aria-label="Link de pago">
                    <p class="acciones"><button type="button" class="btn btn-ghost btn-sm" data-mp-copiar>Copiar link</button>
                        <a class="btn btn-ghost btn-sm" href="<?= e($wa) ?>" target="_blank" rel="noopener">Mandar por WhatsApp</a>
                        <a class="btn btn-ghost btn-sm" href="<?= e((string) $c['link']) ?>" target="_blank" rel="noopener">Abrir</a></p>
                </div>
            <?php endif; ?>
        </div>
        <p class="muted small">La venta <b>todavía no se emitió</b>: no tiene número ni CAE. Se emite sola cuando Mercado Pago confirma la acreditación. No cierres esta pantalla: se actualiza sola.</p>
        <div class="acciones">
            <?php if ($simulado): ?><a class="btn" href="<?= e(url('/mercadopago/simulador/' . $c['token'])) ?>">Ir al simulador de pago</a><?php endif; ?>
            <form method="post" action="<?= e(url('/mercadopago/cobro/' . $c['token'] . '/cancelar')) ?>" class="inline" onsubmit="return confirm('¿Cancelar este cobro? No se emite nada.');"><?= csrf_field() ?>
                <button class="btn btn-ghost" type="submit">Cancelar el cobro</button></form>
        </div>
    <?php elseif ($estado === 'emitido'): ?>
        <div class="alerta alerta-ok">Pago acreditado: la venta se emitió.</div>
        <p><a class="btn" href="<?= e((string) $urlFinal) ?>">Seguir</a></p>
    <?php elseif ($estado === 'error_emision'): ?>
        <p>Mercado Pago acreditó el pago pero la venta no se pudo emitir.<?= $puedeAdministrar ? '' : ' Avisá a un administrador.' ?></p>
        <p><a class="btn btn-ghost" href="<?= e(url('/mercadopago')) ?>">Ver los cobros</a></p>
    <?php else: ?>
        <p>Este cobro ya no está vigente: no se emitió nada.</p>
        <p><a class="btn btn-ghost" href="<?= e(url($c['origen'] === 'kiosco' ? '/kiosco' : '/comprobantes/nuevo')) ?>">Volver</a></p>
    <?php endif; ?>
</section>
<script src="<?= e(asset('assets/mercadopago.js')) ?>" defer></script>
