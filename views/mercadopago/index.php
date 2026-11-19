<?php
use App\Modules\MercadoPago\MercadoPagoService;

/** @var array<string,string> $f */
/** @var array<int,array<string,mixed>> $filas */
/** @var int $total */
/** @var int $pagina */
/** @var int $porPagina */
/** @var bool $admin */
/** @var string $modo */
/** @var string $etiqueta */
/** @var string[] $faltantes */
/** @var bool $conQr */
/** @var string $webhook */
$u = url('/mercadopago');
$link = static fn (int $p) => $u . '?' . http_build_query(['pagina' => $p] + $f);
$paginas = (int) ceil($total / max(1, $porPagina));
?>
<div class="page-head"><div><p class="eyebrow">Mercado Pago</p><h1>Cobros con Mercado Pago</h1></div></div>
<?php if ($admin): ?>
<section class="card small">
    <p><b>Estado:</b> <?= e($etiqueta) ?>.
        <?php if ($modo === 'produccion'): ?>
            <?= $faltantes === [] ? 'Configuración completa.' : '<span class="texto-error">Falta en config/local.php: ' . e(implode(', ', $faltantes)) . '.</span>' ?>
            QR: <?= $conQr ? 'sí' : 'no (falta user_id / pos_id: solo link de pago)' ?>.
        <?php endif; ?></p>
    <?php if ($modo === 'produccion'): ?><p>Dirección de notificaciones para cargar en Mercado Pago (Webhooks): <code><?= e($webhook) ?></code> — eventos «Pagos» y «Órdenes comerciales».</p><?php endif; ?>
</section>
<?php endif; ?>
<form method="get" action="<?= e($u) ?>" class="toolbar filtros">
    <label class="field"><span>Estado</span><select name="estado"><option value="">Todos</option>
        <?php foreach (MercadoPagoService::ESTADOS as $k => $t): ?><option value="<?= e($k) ?>" <?= ($f['estado'] ?? '') === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
    <button class="btn btn-ghost" type="submit">Ver</button>
</form>
<?php if ($filas === []): ?>
    <p class="muted vacio">No hay cobros con Mercado Pago.</p>
<?php else: ?>
    <?= paginador($pagina, $paginas, $total, $porPagina, $link, true) ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><?= th_orden('Fecha y hora', 'fecha', $f, $u, true) ?><?= th_orden('Cobro', 'descripcion', $f, $u) ?><?= th_orden('Importe', 'importe', $f, $u, true) ?><?= th_orden('Estado', 'estado', $f, $u) ?><?= th_orden('Cajero', 'cajero', $f, $u) ?><th>Comprobante</th><th>Pago en Mercado Pago</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($filas as $x): ?>
            <tr>
                <td data-label="Fecha y hora" class="nw"><?= e(date('d/m/Y H:i', (int) strtotime($x['creado_en']))) ?></td>
                <td data-label="Cobro"><a href="<?= e(url('/mercadopago/cobro/' . $x['token'])) ?>"><?= e($x['descripcion']) ?></a><?= $x['modo'] === 'S' ? ' <span class="muted small">(simulado)</span>' : '' ?></td>
                <td data-label="Importe" class="num"><?= e(number_format((float) $x['importe'], 2, ',', '.')) ?></td>
                <td data-label="Estado"><b class="<?= $x['estado'] === 'error_emision' ? 'texto-error' : '' ?>"><?= e(MercadoPagoService::ESTADOS[$x['estado']] ?? $x['estado']) ?></b><?= $x['error'] ? '<br><span class="small muted">' . e((string) $x['error']) . '</span>' : '' ?></td>
                <td data-label="Cajero"><?= e((string) $x['cajero']) ?></td>
                <td data-label="Comprobante"><?= $x['comprobante_id'] !== null ? '<a href="' . e(url('/comprobantes/' . (int) $x['comprobante_id'])) . '">Ver</a>' : '—' ?></td>
                <td data-label="Pago en Mercado Pago"><?= e((string) ($x['mp_pago_id'] ?? '—')) ?></td>
                <td class="num"><?php if ($admin && $x['estado'] === 'error_emision' && $x['acreditado_en'] !== null): ?>
                    <form method="post" action="<?= e(url('/mercadopago/' . (int) $x['id'] . '/reintentar')) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Emitir ahora</button></form>
                <?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
