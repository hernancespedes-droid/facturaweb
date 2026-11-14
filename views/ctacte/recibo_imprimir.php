<?php
/** @var array<string,mixed> $c */
$e = $c['emisor'];
$cli = $c['cliente_datos'];
$f = static fn (float $v): string => number_format($v, 2, ',', '.');
?><!doctype html>
<html lang="es-AR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Recibo <?= e(numero_cbte($c['prefijo'], $c['recibo'])) ?></title>
    <style>
        @page { size: A4; margin: 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 12px/1.4 Arial, Helvetica, sans-serif; color: #000; background: #e9ecf1; }
        .acciones { max-width: 190mm; margin: 12px auto; display: flex; gap: 10px; font-size: 14px; }
        .acciones button { padding: 8px 16px; border: 0; border-radius: 6px; background: #2f6bff; color: #fff; font-weight: 700; cursor: pointer; }
        .hoja { width: 190mm; margin: 0 auto 20px; background: #fff; padding: 8mm; }
        .cab { display: flex; justify-content: space-between; border: 1.5px solid #000; padding: 4mm; }
        .cab h1 { margin: 0 0 2mm; font-size: 16px; } .cab .nro { text-align: right; font-size: 15px; font-weight: 700; }
        .cliente { margin-top: 4mm; border: 1px solid #000; padding: 3mm; }
        table { width: 100%; border-collapse: collapse; margin-top: 4mm; }
        th { background: #d9d9d9; border: 1px solid #000; padding: 1.5mm; text-align: left; font-size: 11px; }
        td { padding: 1.4mm 1.5mm; border-bottom: 1px solid #bbb; }
        .num { text-align: right; white-space: nowrap; }
        .totales { margin-top: 4mm; margin-left: auto; width: 80mm; } .totales div { display: flex; justify-content: space-between; padding: 1mm 0; }
        .totales .total { border-top: 1.5px solid #000; font-weight: 700; font-size: 14px; }
        .anulado { color: #b00; font-size: 22px; font-weight: 700; text-align: center; margin-top: 3mm; }
        .firma { margin-top: 22mm; display: flex; justify-content: flex-end; } .firma div { width: 70mm; border-top: 1px solid #000; text-align: center; padding-top: 1mm; font-size: 11px; }
        .pie { margin-top: 6mm; font-size: 10px; color: #444; }
        @media print { body { background: #fff; } .acciones { display: none; } .hoja { margin: 0; width: auto; padding: 0; } }
    </style>
</head>
<body>
<div class="acciones"><button type="button" onclick="window.print()">Imprimir</button></div>
<div class="hoja">
    <div class="cab">
        <div><h1><?= e($e['empresa'] ?? '') ?></h1><div><?= e(trim(($e['domicilio'] ?? '') . ' ' . ($e['localidad'] ?? ''))) ?></div>
            <?php if (!empty($e['cuit'])): ?><div>CUIT <?= e(cuit_texto($e['cuit'])) ?></div><?php endif; ?></div>
        <div class="nro">RECIBO X<br><?= e(numero_cbte($c['prefijo'], $c['recibo'])) ?><br><span style="font-weight:400">Fecha <?= e(fecha($c['fecha'])) ?></span></div>
    </div>
    <?php if ($c['estado'] === 'A'): ?><div class="anulado">ANULADO</div><?php endif; ?>
    <div class="cliente"><b><?= e($cli['razon_social']) ?></b> (<?= (int) $cli['codigo'] ?>)<br>
        <?= e(trim((string) ($cli['domicilio'] ?? '') . ' ' . (string) ($cli['localidad'] ?? ''))) ?><?= !empty($cli['cuit']) ? ' · CUIT ' . e(cuit_texto($cli['cuit'])) : '' ?></div>
    <?php if ($c['imputaciones'] !== []): ?>
    <table><thead><tr><th>Comprobantes que cancela</th><th>Fecha</th><th class="num">Importe</th></tr></thead><tbody>
    <?php foreach ($c['imputaciones'] as $i): ?>
        <tr><td><?= e(\App\Modules\CuentaCorriente\CuentaCorrienteService::TIPOS[$i['tipodb']] ?? $i['tipodb']) ?> <?= e($i['letradb']) ?> <?= e(numero_cbte($c['prefijo'], $i['numero_visible'] ?? $i['nrcodb'])) ?></td><td><?= e(fecha($i['fecodb'])) ?></td><td class="num">$ <?= e($f((float) $i['importe'])) ?></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php endif; ?>
    <?php if ($c['anticipo'] > 0 && $c['estado'] !== 'A'): ?><p>Cobro a cuenta (saldo a favor): <b>$ <?= e($f($c['anticipo'])) ?></b></p><?php endif; ?>
    <table><thead><tr><th>Forma de pago</th><th>Detalle</th><th>Cobro</th><th class="num">Importe</th></tr></thead><tbody>
    <?php foreach ($c['pagos'] as $p): ?>
        <tr><td><?= e($p['fpago_descripcion'] ?? $p['p_fpago']) ?></td><td><?= (int) ($p['fpago_tipo'] ?? 0) === 5 ? e('N.º ' . $p['p_nro'] . ' · ' . ($p['banco_descripcion'] ?? '')) : e((string) $p['p_nro']) ?></td>
            <td><?= e(fecha($p['p_fecvto'])) ?></td><td class="num">$ <?= e($f((float) $p['p_importe'])) ?></td></tr>
    <?php endforeach; ?></tbody></table>
    <div class="totales">
        <div><span>Total que se cancela</span><span>$ <?= e($f((float) $c['bruto'])) ?></span></div>
        <?php if ($c['descuento'] > 0): ?><div><span>Descuento</span><span>− $ <?= e($f($c['descuento'])) ?></span></div><?php endif; ?>
        <?php foreach ($c['retenciones'] as $x): ?><div><span>Retención <?= e($x['descripcion']) ?><?= $x['certificado'] !== '' ? ' (cert. ' . e($x['certificado']) . ')' : '' ?></span><span>− $ <?= e($f($x['importe'])) ?></span></div><?php endforeach; ?>
        <div class="total"><span>Total recibido</span><span>$ <?= e($f((float) $c['neto'])) ?></span></div>
    </div>
    <?php if ($c['observaciones']): ?><p class="pie">Observaciones: <?= e($c['observaciones']) ?></p><?php endif; ?>
    <div class="firma"><div>Firma y aclaración</div></div>
    <p class="pie">Recibo interno, sin validez fiscal.</p>
</div>
</body>
</html>
