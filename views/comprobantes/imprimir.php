<?php
/** @var array<string,mixed> $d de ComprobanteImpreso::datos() */
/** @var int $id */
/** @var string[] $copias */
$e = $d['emisor'];
$c = $d['cliente'];
$f = static fn (float $v, int $dec = 2): string => number_format($v, $dec, ',', '.');
?><!doctype html>
<html lang="es-AR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($d['titulo'] . ' ' . $d['letra'] . ' ' . $d['numero']) ?></title>
    <style>
        @page { size: A4; margin: 10mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 12px/1.35 Arial, Helvetica, sans-serif; color: #000; background: #e9ecf1; }
        .acciones { max-width: 190mm; margin: 12px auto; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; font-size: 14px; }
        .acciones button, .acciones a.b { padding: 8px 16px; border: 0; border-radius: 6px; background: #2f6bff; color: #fff; font-weight: 700; text-decoration: none; cursor: pointer; }
        .hoja { width: 190mm; min-height: 277mm; margin: 0 auto 20px; background: #fff; padding: 0; position: relative; }
        .caja { border: 1px solid #000; }
        .cab { display: grid; grid-template-columns: 1fr 18mm 1fr; border: 1.5px solid #000; min-height: 38mm; }
        .cab > div { padding: 3mm; }
        .emisor h1 { margin: 0 0 2mm; font-size: 16px; }
        .emisor div { font-size: 11px; }
        .letra { border-left: 1px solid #000; border-right: 1px solid #000; text-align: center; padding: 1mm !important; }
        .letra b { display: block; font-size: 38px; line-height: 1.1; border-bottom: 1px solid #000; }
        .letra span { font-size: 9px; }
        .datos .titulo { font-size: 16px; font-weight: 700; }
        .datos .nro { font-size: 14px; font-weight: 700; margin: 1mm 0; }
        .cliente { margin-top: 3mm; border: 1.5px solid #000; padding: 2mm 3mm; display: grid; grid-template-columns: 1fr auto; gap: 1mm 6mm; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 3mm; }
        table.items th { background: #d9d9d9; border: 1px solid #000; padding: 1.5mm; font-size: 11px; text-align: right; }
        table.items th:first-child, table.items td:first-child { text-align: left; }
        table.items td { padding: 1.2mm 1.5mm; text-align: right; vertical-align: top; }
        table.items td:first-child { white-space: pre-line; }
        table.items tbody tr:nth-child(even) td { background: #f0f0f0; }
        table.items tbody { border-bottom: 1px solid #000; }
        .pie { display: grid; grid-template-columns: 1fr 80mm; gap: 6mm; margin-top: 4mm; align-items: start; }
        .totales { border: 1px solid #000; padding: 2mm 3mm; }
        .totales div { display: flex; justify-content: space-between; padding: 0.6mm 0; }
        .totales .t { font-size: 15px; font-weight: 700; border-top: 1px solid #000; margin-top: 1mm; padding-top: 1.5mm; }
        .leyenda { font-size: 10px; }
        .estado { margin-top: 6mm; border: 2px solid #000; padding: 4mm; text-align: center; }
        .texto-renglon { font-size: 0.9em; margin-top: 1mm; color: #333; }
        .estado.con-qr { display: flex; align-items: center; gap: 5mm; text-align: left; }
        .estado .qr svg { width: 30mm; height: 30mm; display: block; }
        .estado .prueba { margin-top: 2mm; font-weight: 700; color: #b00020; }
        .estado b { display: block; font-size: 17px; letter-spacing: .5px; }
        .copia { text-align: right; font-weight: 700; letter-spacing: 3px; font-size: 11px; margin-top: 3mm; }
        @media print { body { background: #fff; } .acciones { display: none; } .hoja { margin: 0; width: auto; min-height: 0; } }
    </style>
</head>
<body>
<div class="acciones">
    <button type="button" onclick="window.print()">Imprimir / Guardar PDF</button>
    <a class="b" href="<?= e(url('/comprobantes/' . $id . '/pdf') . '?copia=' . $d['copia']) ?>">Bajar PDF</a>
    <?php foreach ($copias as $k): if ($k !== $d['copia']): ?><a href="<?= e(url('/comprobantes/' . $id . '/imprimir') . '?copia=' . $k) ?>"><?= e(ucfirst(strtolower($k))) ?></a><?php endif; endforeach; ?>
    <a href="<?= e(url('/comprobantes/' . $id)) ?>">Volver al comprobante</a>
</div>
<div class="hoja">
    <div class="cab">
        <div class="emisor">
            <h1><?= e($e['nombre'] !== '' ? $e['nombre'] : 'Empresa sin cargar') ?></h1>
            <?php foreach (array_filter([$e['domicilio'], $e['telefono'] !== '' ? 'Tel.: ' . $e['telefono'] : '', $e['mail']]) as $l): ?><div><?= e($l) ?></div><?php endforeach; ?>
            <?php if ($e['condicion'] !== ''): ?><div><b><?= e($e['condicion']) ?></b></div><?php endif; ?>
        </div>
        <div class="letra"><b><?= e($d['letra']) ?></b><span>Cód. <?= e(str_pad((string) $d['codigo'], 2, '0', STR_PAD_LEFT)) ?></span></div>
        <div class="datos">
            <div class="titulo"><?= e($d['titulo']) ?></div>
            <div class="nro">Nº <?= e($d['numero']) ?></div>
            <div>Fecha: <?= e($d['fecha']) ?></div>
            <div>C.U.I.T.: <?= e($e['cuit']) ?></div>
            <?php if ($e['iibb'] !== ''): ?><div>Ingresos Brutos: <?= e($e['iibb']) ?></div><?php endif; ?>
            <?php if ($e['inicio'] !== ''): ?><div>Inicio de actividades: <?= e($e['inicio']) ?></div><?php endif; ?>
        </div>
    </div>
    <div class="cliente">
        <div><b>Razón social:</b> <?= e($c['razon_social']) ?> (<?= e($c['codigo']) ?>)</div><div>IVA: <?= e($c['condicion']) ?></div>
        <div><b>Domicilio:</b> <?= e(trim($c['domicilio'] . ' ' . $c['localidad'])) ?></div><div>CUIT: <?= e($c['cuit'] !== '' ? $c['cuit'] : 'sin CUIT') ?></div>
        <div><b>Cond. de pago:</b> <?= e($d['condicion_pago']) ?><?= $d['orden_compra'] !== '' ? ' · Orden de compra: ' . e($d['orden_compra']) : '' ?></div>
        <div><?= $d['asociado'] !== '' ? '<b>Corrige:</b> ' . e($d['asociado']) : '' ?></div>
    </div>
    <table class="items">
        <thead><tr><th>Descripción</th><th style="width:24mm">Cantidad</th><th style="width:30mm">Precio</th><th style="width:30mm">Importe</th></tr></thead>
        <tbody>
        <?php foreach ($d['items'] as $it): ?>
            <tr><td><?= e($it['descripcion']) ?><?php if (($it['texto'] ?? '') !== ''): ?><div class="texto-renglon"><?= nl2br(e($it['texto'])) ?></div><?php endif; ?></td><td><?= e($f($it['cantidad'], 3)) ?></td><td><?= e($f($it['precio'])) ?></td><td><?= e($f($it['importe'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($d['items'] === []): ?><tr><td colspan="4">(sin detalle)</td></tr><?php endif; ?>
        </tbody>
    </table>
    <?php if ($d['observaciones'] !== ''): ?><p>Observaciones: <?= e($d['observaciones']) ?></p><?php endif; ?>
    <div class="pie">
        <div class="leyenda"><?php foreach ($d['leyendas'] as $l): ?><p><?= e($l) ?></p><?php endforeach; ?></div>
        <div class="totales">
            <?php foreach ($d['totales'] as [$rotulo, $valor, $fuerte]): ?>
                <div class="<?= $fuerte ? 't' : '' ?>"><span><?= e($rotulo) ?></span><span><?= $valor < 0 ? '- ' : '' ?>$ <?= e($f(abs((float) $valor))) ?></span></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php if ($d['cae'] !== null): ?>
        <div class="estado con-qr">
            <?php if ($d['qr'] !== null): ?><div class="qr"><?= \App\Core\Qr::svg($d['qr'], 3, 'QR de ARCA') ?></div><?php endif; ?>
            <div><b>CAE: <?= e($d['cae']['cae']) ?><?= $d['cae']['vto'] !== '' ? ' &nbsp; Vto. CAE: ' . e($d['cae']['vto']) : '' ?></b>Comprobante Autorizado ARCA
                <?php if ($d['aviso_prueba'] !== ''): ?><div class="prueba"><?= e($d['aviso_prueba']) ?></div><?php endif; ?></div>
        </div>
    <?php else: ?>
        <div class="estado"><b>COMPROBANTE INTERNO</b>Sin CAE de ARCA: no tiene validez fiscal</div>
    <?php endif; ?>
    <div class="copia"><?= e(implode(' ', mb_str_split($d['copia']))) ?></div>
</div>
</body>
</html>
