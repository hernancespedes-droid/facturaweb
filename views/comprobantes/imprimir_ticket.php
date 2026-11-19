<?php
/** @var array<string,mixed> $d de ComprobanteImpreso::datos() */
/** @var int $id */
/** @var string[] $copias */
/** @var string $papel 't80' o 't50' */
$e = $d['emisor'];
$c = $d['cliente'];
$mm = $papel === 't50' ? 50 : 80;
$f = static fn (float $v, int $dec = 2): string => number_format($v, $dec, ',', '.');
$vuelto = 0.0;
foreach ($d['pagos'] as $p) {
    $vuelto += $p['vuelto'];
}
?><!doctype html>
<html lang="es-AR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($d['titulo'] . ' ' . $d['letra'] . ' ' . $d['numero']) ?></title>
    <style>
        @page { size: <?= $mm ?>mm auto; margin: 2mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font: <?= $mm === 50 ? 10 : 12 ?>px/1.3 "Courier New", Courier, monospace; color: #000; background: #e9ecf1; }
        .acciones { max-width: 160mm; margin: 12px auto; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; font: 14px Arial, sans-serif; }
        .acciones button, .acciones a.b { padding: 8px 16px; border: 0; border-radius: 6px; background: #2f6bff; color: #fff; font-weight: 700; text-decoration: none; cursor: pointer; }
        .ticket { width: <?= $mm - 4 ?>mm; margin: 0 auto 20px; background: #fff; padding: 2mm; }
        .c { text-align: center; } .r { text-align: right; } b { font-weight: 700; }
        h1 { margin: 0 0 1mm; font-size: <?= $mm === 50 ? 13 : 16 ?>px; text-align: center; }
        .logo { display: block; max-width: 100%; max-height: 14mm; margin: 0 auto 1mm; }
        hr { border: 0; border-top: 1px dashed #000; margin: 1.5mm 0; }
        .fila { display: flex; justify-content: space-between; gap: 2mm; }
        .fila span:first-child { min-width: 0; overflow-wrap: anywhere; }
        .renglon { margin-bottom: 1mm; } .renglon .t { font-size: .9em; }
        .total { font-size: <?= $mm === 50 ? 14 : 18 ?>px; font-weight: 700; }
        .vuelto { border: 2px solid #000; padding: 1.5mm; text-align: center; font-weight: 700; margin: 1.5mm 0; }
        .qr svg { width: <?= $mm === 50 ? 32 : 38 ?>mm; height: <?= $mm === 50 ? 32 : 38 ?>mm; display: block; margin: 1mm auto; }
        .no-valido { border: 2px solid #000; padding: 1.5mm; text-align: center; font-weight: 700; margin-top: 2mm; }
        .prueba { color: #b00020; font-weight: 700; }
        .chico { font-size: .85em; }
        @media print { body { background: #fff; } .acciones { display: none; } .ticket { margin: 0; width: auto; padding: 0; } }
    </style>
</head>
<body>
<div class="acciones">
    <button type="button" onclick="window.print()">Imprimir ticket</button>
    <a class="b" href="<?= e(url('/comprobantes/' . $id . '/pdf') . '?copia=' . $d['copia']) ?>">Bajar PDF (A4)</a>
    <a href="<?= e(url('/comprobantes/' . $id . '/pdf') . '?copia=' . $d['copia'] . '&papel=' . $papel) ?>">PDF ticket</a>
    <a href="<?= e(url('/comprobantes/' . $id . '/imprimir') . '?papel=a4') ?>">Ver en A4</a>
    <a href="<?= e(url('/comprobantes/' . $id)) ?>">Volver al comprobante</a>
</div>
<div class="ticket">
    <?php if ($d['logo'] !== null): ?><img class="logo" src="data:image/jpeg;base64,<?= base64_encode($d['logo']['datos']) ?>" alt=""><?php endif; ?>
    <h1><?= e($e['nombre'] !== '' ? $e['nombre'] : 'Empresa sin cargar') ?></h1>
    <div class="c chico">
        <?php foreach (array_filter([$e['domicilio'], $e['telefono'] !== '' ? 'Tel.: ' . $e['telefono'] : '', 'C.U.I.T.: ' . $e['cuit'], $e['iibb'] !== '' ? 'IIBB: ' . $e['iibb'] : '', $e['inicio'] !== '' ? 'Inicio act.: ' . $e['inicio'] : '', $e['condicion']]) as $l): ?><div><?= e($l) ?></div><?php endforeach; ?>
    </div>
    <hr>
    <div class="c"><b><?= e(mb_strtoupper($d['titulo'])) ?> <?= e($d['letra']) ?></b></div>
    <div class="c">Nº <?= e($d['numero']) ?></div>
    <div class="c chico"><?= e($d['interno'] ? 'No fiscal' : 'Cód. ' . str_pad((string) $d['codigo'], 2, '0', STR_PAD_LEFT)) ?> · <?= e($d['fecha']) ?></div>
    <hr>
    <div><b><?= e($c['razon_social']) ?></b></div>
    <div class="chico">CUIT: <?= e($c['cuit'] !== '' ? $c['cuit'] : 'sin CUIT') ?> · IVA: <?= e($c['condicion']) ?></div>
    <?php if ($d['asociado'] !== ''): ?><div class="chico">Corrige: <?= e($d['asociado']) ?></div><?php endif; ?>
    <hr>
    <?php foreach ($d['items'] as $it): ?>
        <div class="renglon">
            <div><?= e($it['descripcion']) ?></div>
            <?php if (($it['texto'] ?? '') !== ''): ?><div class="t"><?= nl2br(e($it['texto'])) ?></div><?php endif; ?>
            <div class="fila"><span><?= e($f($it['cantidad'], 3)) ?> x <?= e($f($it['precio'])) ?></span><span><?= e($f($it['importe'])) ?></span></div>
        </div>
    <?php endforeach; ?>
    <?php if ($d['items'] === []): ?><div>(sin detalle)</div><?php endif; ?>
    <hr>
    <?php foreach ($d['totales'] as [$rotulo, $valor, $fuerte]): ?>
        <div class="fila <?= $fuerte ? 'total' : '' ?>"><span><?= e($rotulo) ?></span><span><?= $valor < 0 ? '- ' : '' ?>$ <?= e($f(abs((float) $valor))) ?></span></div>
    <?php endforeach; ?>
    <hr>
    <?php if ($d['pagos'] === []): ?>
        <div><?= e($d['condicion_pago']) ?></div>
    <?php else: foreach ($d['pagos'] as $p): ?>
        <div class="fila"><span><?= e($p['forma']) ?></span><span>$ <?= e($f($p['importe'])) ?></span></div>
        <?php if ($p['recibido'] !== null && $p['vuelto'] > 0): ?><div class="fila chico"><span>Recibido</span><span>$ <?= e($f($p['recibido'])) ?></span></div><?php endif; ?>
    <?php endforeach; endif; ?>
    <?php if ($vuelto > 0): ?><div class="vuelto">VUELTO: $ <?= e($f($vuelto)) ?></div><?php endif; ?>
    <?php if ($d['observaciones'] !== ''): ?><div class="chico">Obs.: <?= e($d['observaciones']) ?></div><?php endif; ?>
    <?php foreach ($d['leyendas'] as $l): ?><p class="chico"><?= e($l) ?></p><?php endforeach; ?>
    <?php if ($d['cae'] !== null): ?>
        <hr>
        <?php if ($d['qr'] !== null): ?><div class="qr"><?= \App\Core\Qr::svg($d['qr'], 3, 'QR de ARCA') ?></div><?php endif; ?>
        <div class="c"><b>CAE: <?= e($d['cae']['cae']) ?></b></div>
        <?php if ($d['cae']['vto'] !== ''): ?><div class="c chico">Vto. CAE: <?= e($d['cae']['vto']) ?></div><?php endif; ?>
        <div class="c chico">Comprobante Autorizado ARCA</div>
        <?php if ($d['aviso_prueba'] !== ''): ?><div class="c chico prueba"><?= e($d['aviso_prueba']) ?></div><?php endif; ?>
    <?php elseif ($d['interno']): ?>
        <div class="no-valido">DOCUMENTO NO VÁLIDO COMO FACTURA</div>
    <?php else: ?>
        <div class="no-valido">COMPROBANTE INTERNO<br><span class="chico">Sin CAE: no tiene validez fiscal</span></div>
    <?php endif; ?>
    <div class="c chico" style="margin-top:2mm"><?= e($d['copia']) ?></div>
    <div class="c chico">¡Gracias por su compra!</div>
</div>
</body>
</html>
