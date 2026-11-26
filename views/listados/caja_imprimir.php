<?php
use App\Modules\Auth\Auth;
use App\Modules\Configuracion\Apariencia;

/** @var array<string,mixed> $d de ReporteCaja::datos() */
/** @var array<string,string> $f */
$r = $d['r'];
$v = $r['ventas'];
$ef = $r['efectivo'];
$p = static fn ($x) => '$ ' . number_format((float) $x, 2, ',', '.');
$fe = static fn (?string $x) => $x ? date('d/m/Y', (int) strtotime($x)) : '';
$hora = static fn (?string $x) => $x ? date('H:i', (int) strtotime($x)) : '';
$unDia = $d['desde'] === $d['hasta'];
$periodo = $unDia ? 'del ' . $fe($d['desde']) : 'del ' . $fe($d['desde']) . ' al ' . $fe($d['hasta']);
$usuario = Auth::usuario();
$cuentaCorriente = (float) $v['cuenta_corriente'];
$n = 0;
$volver = url('/listados/caja-diaria') . '?' . http_build_query(['desde' => $f['desde'] ?? '', 'hasta' => $f['hasta'] ?? '']);
?><!doctype html>
<html lang="es-AR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Reporte de caja <?= e($periodo) ?></title>
    <style>
        @page { size: A4; margin: 12mm 10mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 12.5px/1.4 Arial, Helvetica, sans-serif; color: #000; background: #e9ecf1; }
        .acciones { max-width: 190mm; margin: 12px auto; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; font-size: 14px; padding: 0 8px; }
        .acciones button, .acciones a.b { padding: 8px 16px; border: 0; border-radius: 6px; background: #2f6bff; color: #fff; font-weight: 700; text-decoration: none; cursor: pointer; font-size: 14px; }
        .acciones a.b.gris { background: #5b6472; }
        .hoja { max-width: 190mm; margin: 0 auto 20px; background: #fff; padding: 8mm; }
        header.cab { display: flex; justify-content: space-between; gap: 12px; border-bottom: 2px solid #000; padding-bottom: 3mm; margin-bottom: 4mm; flex-wrap: wrap; }
        header.cab h1 { margin: 0; font-size: 20px; }
        header.cab .empresa { font-size: 15px; font-weight: 700; }
        header.cab .dato { font-size: 11px; color: #333; text-align: right; }
        h2 { font-size: 15px; margin: 6mm 0 2mm; padding: 1.5mm 2mm; background: #eef1f5; border-left: 4px solid #000; break-after: avoid; }
        h2 small { font-weight: normal; font-size: 11px; color: #333; }
        p.explica { margin: 0 0 2mm; font-size: 11.5px; color: #222; }
        .grandes { display: grid; grid-template-columns: repeat(4, 1fr); gap: 2mm; }
        .grandes div { border: 1.5px solid #000; border-radius: 3px; padding: 2.5mm; }
        .grandes span { display: block; font-size: 11px; }
        .grandes b { display: block; font-size: 17px; margin-top: 1mm; }
        .grandes .clave { background: #000; color: #fff; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 2mm; break-inside: auto; }
        tr { break-inside: avoid; }
        th, td { border-bottom: 1px solid #bbb; padding: 1.4mm 1.6mm; text-align: left; vertical-align: top; }
        th { font-size: 11px; border-bottom: 1.5px solid #000; }
        td.n, th.n { text-align: right; white-space: nowrap; }
        tr.total td { border-top: 1.5px solid #000; border-bottom: 0; font-weight: 700; }
        tr.sub td { font-weight: 700; background: #f6f7f9; }
        tr.signo td:first-child { padding-left: 6mm; }
        .cuenta td { font-size: 13px; }
        .cuenta td.op { width: 8mm; text-align: center; font-weight: 700; }
        .desliza { overflow-x: auto; }
        .nota { font-size: 11px; color: #333; margin: 1mm 0 2mm; }
        .vacio { color: #555; font-style: italic; margin: 0 0 2mm; }
        .dif-mal { font-weight: 700; }
        .firmas { display: grid; grid-template-columns: 1fr 1fr; gap: 20mm; margin-top: 18mm; break-inside: avoid; }
        .firmas div { border-top: 1px solid #000; padding-top: 1.5mm; text-align: center; font-size: 11px; }
        footer { margin-top: 6mm; font-size: 10px; color: #444; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 6px; }
        @media (max-width: 700px) {
            .hoja { padding: 4mm; }
            .grandes { grid-template-columns: 1fr 1fr; }
            th, td { padding: 1.2mm 1mm; font-size: 11px; }
            .ocultar-chico { display: none; }
        }
        @media print {
            body { background: #fff; }
            .acciones { display: none; }
            .hoja { margin: 0; padding: 0; max-width: none; }
            h2, tr.sub td, .grandes .clave { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
<div class="acciones">
    <button type="button" onclick="window.print()">Imprimir</button>
    <a class="b gris" href="<?= e($volver) ?>">Volver a la caja diaria</a>
</div>
<main class="hoja" data-reporte-caja>
    <header class="cab">
        <div>
            <div class="empresa"><?= e((string) $d['empresa']) ?></div>
            <h1>Reporte de caja <?= e($periodo) ?></h1>
        </div>
        <div class="dato">Impreso el <?= e(date('d/m/Y H:i')) ?><?php if ($usuario): ?><br>por <?= e((string) $usuario['nombre']) ?><?php endif; ?></div>
    </header>

    <h2><?= ++$n ?>. Resumen</h2>
    <div class="grandes">
        <div><span>Se vendió</span><b><?= e($p($v['ventas_netas'])) ?></b></div>
        <div><span>Entró dinero</span><b><?= e($p($d['entro'])) ?></b></div>
        <div><span>Salió del cajón</span><b><?= e($p($d['salio'])) ?></b></div>
        <div class="clave"><span>Tiene que haber en el cajón (efectivo)</span><b data-en-cajon><?= e($p($ef['en_caja'])) ?></b></div>
    </div>
    <p class="nota">«Entró dinero» es todo lo cobrado (efectivo, tarjeta, billeteras, cheques…) más los ingresos de dinero; no cuenta lo vendido a cuenta corriente, porque eso todavía no se cobró<?= $cuentaCorriente > 0 ? ' (' . e($p($cuentaCorriente)) . ')' : '' ?>.</p>

    <h2><?= ++$n ?>. ¿Qué se vendió? <small><?= (int) $v['comprobantes'] ?> comprobantes</small></h2>
    <table>
        <tbody>
            <tr><td>Facturas</td><td class="n"><?= e($p($v['facturas'])) ?></td></tr>
            <tr><td>Presupuestos</td><td class="n"><?= e($p($v['presupuestos'])) ?></td></tr>
            <?php if ($v['cambios'] > 0): ?><tr><td>Cambios de mercadería (solo la diferencia cobrada)</td><td class="n"><?= e($p($v['cambios'])) ?></td></tr><?php endif; ?>
            <?php if ($v['notas_debito'] > 0): ?><tr><td>Notas de débito</td><td class="n"><?= e($p($v['notas_debito'])) ?></td></tr><?php endif; ?>
            <?php if ($v['notas_credito'] > 0): ?><tr><td>Menos notas de crédito (devoluciones y descuentos)</td><td class="n">− <?= e($p($v['notas_credito'])) ?></td></tr><?php endif; ?>
            <tr class="total"><td>Total vendido</td><td class="n"><?= e($p($v['ventas_netas'])) ?></td></tr>
        </tbody>
    </table>
    <table>
        <tbody>
            <tr><td>De eso, se cobró en el momento (contado)</td><td class="n"><?= e($p($v['contado'])) ?></td></tr>
            <tr><td>Y quedó a cuenta corriente (el cliente lo debe; ver «Lo que quedó debiendo»)</td><td class="n"><?= e($p($cuentaCorriente)) ?></td></tr>
        </tbody>
    </table>
    <?php if ($v['notas_credito'] > 0): ?><p class="nota">Las notas de crédito no sacan dinero del cajón: quedan a favor del cliente en su cuenta.</p><?php endif; ?>

    <h2><?= ++$n ?>. ¿Cómo se cobró y a dónde fue cada peso?</h2>
    <p class="explica">«Ventas» es lo cobrado al vender; «Cobranzas» es lo que pagaron clientes por deudas anteriores (recibos).</p>
    <?php if ($d['medios'] === []): ?>
        <p class="vacio">No hubo cobros en el período.</p>
    <?php else: ?>
        <table data-medios>
            <thead><tr><th>Forma de pago</th><th class="n">Ventas</th><th class="n">Cobranzas</th><th class="n">Total</th><th>¿A dónde fue?</th></tr></thead>
            <tbody>
            <?php foreach ($d['medios'] as $x): ?>
                <tr><td><b><?= e($x['forma']) ?></b></td><td class="n"><?= e($p($x['ventas'])) ?></td><td class="n"><?= e($p($x['recibos'])) ?></td>
                    <td class="n"><b><?= e($p($x['total'])) ?></b></td><td><?= e($x['destino']) ?></td></tr>
            <?php endforeach; ?>
            <tr class="total"><td>Total cobrado</td><td class="n"></td><td class="n"></td><td class="n"><?= e($p($r['total_cobrado'])) ?></td><td></td></tr>
            </tbody>
        </table>
    <?php endif; ?>
    <?php if ($cuentaCorriente > 0): ?><p class="nota">Además, <?= e($p($cuentaCorriente)) ?> se vendieron a cuenta corriente: no es dinero, es lo que los clientes quedaron debiendo.</p><?php endif; ?>

    <h2><?= ++$n ?>. El efectivo, paso a paso</h2>
    <table class="cuenta">
        <tbody>
            <tr><td class="op"></td><td>Con esto se abrió la caja (fondo inicial)</td><td class="n"><?= e($p($ef['fondo'])) ?></td></tr>
            <tr><td class="op">+</td><td>Efectivo que dieron los clientes (<?= e($p($ef['recibido'])) ?>) menos el vuelto que se les devolvió (<?= e($p($ef['vuelto'])) ?>)</td><td class="n"><?= e($p($ef['neto'])) ?></td></tr>
            <tr><td class="op">+</td><td>Ingresos de dinero (cambio que se trajo, aportes…)</td><td class="n"><?= e($p($ef['ingresos'])) ?></td></tr>
            <tr><td class="op">−</td><td>Salidas de dinero (pagos, retiros, gastos…; el detalle sigue abajo)</td><td class="n"><?= e($p($ef['egresos'])) ?></td></tr>
            <tr class="total"><td class="op">=</td><td>Tiene que haber en el cajón</td><td class="n"><?= e($p($ef['en_caja'])) ?></td></tr>
        </tbody>
    </table>
    <?php if ($d['turnos'] !== []): ?>
        <p class="explica"><b>Turnos de caja</b>: al cerrar, el cajero cuenta el efectivo y el sistema lo compara con lo que tenía que haber.</p>
        <div class="desliza"><table>
            <thead><tr><th>Cajero</th><th>Abrió</th><th>Cerró</th><th class="n">Fondo</th><th class="n">Tenía que haber</th><th class="n">Contó</th><th class="n">Diferencia</th></tr></thead>
            <tbody>
            <?php foreach ($d['turnos'] as $t): $dif = $t['diferencia'] !== null ? (float) $t['diferencia'] : null; ?>
                <tr><td><?= e((string) $t['usuario']) ?></td><td><?= e(($unDia ? '' : $fe($t['abierto_en']) . ' ') . $hora($t['abierto_en'])) ?></td>
                    <td><?= $t['estado'] === 'A' ? 'Sigue abierto' : e(($unDia ? '' : $fe($t['cerrado_en']) . ' ') . $hora($t['cerrado_en'])) ?></td>
                    <td class="n"><?= e($p($t['fondo_inicial'])) ?></td><td class="n"><?= $t['esperado'] !== null ? e($p($t['esperado'])) : '' ?></td>
                    <td class="n"><?= $t['contado'] !== null ? e($p($t['contado'])) : '' ?></td>
                    <td class="n<?= $dif !== null && abs($dif) > 0.004 ? ' dif-mal' : '' ?>"><?php if ($dif !== null): ?><?= abs($dif) < 0.005 ? 'Cierra justo' : e(($dif > 0 ? 'Sobran ' : 'Faltan ') . $p(abs($dif))) ?><?php endif; ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>

    <h2><?= ++$n ?>. Lo que entró y salió del cajón sin ser una venta</h2>
    <?php if ($d['egresos'] === [] && $d['ingresos'] === []): ?>
        <p class="vacio">No hubo ingresos ni salidas de dinero.</p>
    <?php else: ?>
        <table data-salidas>
            <thead><tr><th>Fecha y hora</th><th>Quién</th><th>Motivo</th><th class="n">Entró</th><th class="n">Salió</th></tr></thead>
            <tbody>
            <?php foreach (['Salidas de dinero' => $d['egresos'], 'Ingresos de dinero' => $d['ingresos']] as $titulo => $lista): if ($lista === []) { continue; } $suma = 0.0; ?>
                <tr class="sub"><td colspan="5"><?= e($titulo) ?></td></tr>
                <?php foreach ($lista as $x): $suma += (float) $x['importe']; $ing = $x['tipo'] === 'I'; ?>
                    <tr><td><?= e($fe($x['creado_en']) . ' ' . $hora($x['creado_en'])) ?></td><td><?= e((string) $x['usuario']) ?></td>
                        <td><?= e((string) $x['motivo']) ?><?= $x['anula_id'] !== null ? ' (anula un movimiento anterior)' : '' ?><?= (int) $x['anulado'] > 0 ? ' (después se anuló)' : '' ?></td>
                        <td class="n"><?= $ing ? e($p($x['importe'])) : '' ?></td><td class="n"><?= $ing ? '' : e($p($x['importe'])) ?></td></tr>
                <?php endforeach; ?>
                <tr class="total"><td colspan="3">Total <?= e(mb_strtolower($titulo)) ?></td><td class="n"><?= $titulo === 'Ingresos de dinero' ? e($p($suma)) : '' ?></td><td class="n"><?= $titulo === 'Salidas de dinero' ? e($p($suma)) : '' ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($d['pagos'] !== []): ?>
        <h2><?= ++$n ?>. Pagos a proveedores</h2>
        <p class="explica">Lo pagado en efectivo ya está en las salidas de dinero de arriba. Las transferencias y los cheques propios salen del banco, no del cajón.</p>
        <table data-pagos>
            <thead><tr><th>Fecha</th><th>Orden</th><th>Proveedor</th><th>Cómo se pagó</th><th class="n">Importe</th></tr></thead>
            <tbody>
            <?php foreach ($d['pagos'] as $x): ?>
                <tr><td><?= e($fe($x['fecha'])) ?></td><td><?= e(sprintf('%08d', (int) $x['numero'])) ?></td><td><?= e((string) $x['proveedor']) ?></td>
                    <td><?= e(\App\Modules\Listados\ReporteCaja::MEDIOS_PAGO[(string) $x['medio']] ?? 'Otro medio') ?><?= trim((string) $x['nro_medio'] . ' ' . (string) $x['detalle']) !== '' ? ' <span class="nota">' . e(trim((string) $x['nro_medio'] . ' ' . (string) $x['detalle'])) . '</span>' : '' ?></td>
                    <td class="n"><?= e($p($x['importe'])) ?></td></tr>
            <?php endforeach; ?>
            <?php foreach ($d['pagos_medio'] as $medio => $importe): ?>
                <tr class="sub"><td colspan="4">Total <?= e(mb_strtolower($medio)) ?></td><td class="n"><?= e($p($importe)) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2><?= ++$n ?>. Lo que quedó debiendo cada cliente <small>(cuenta corriente: no es dinero)</small></h2>
    <?php if ($d['fiado'] === []): ?>
        <p class="vacio">No se vendió nada a cuenta corriente.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Fecha</th><th>Comprobante</th><th>Cliente</th><th class="n">Quedó debiendo</th></tr></thead>
            <tbody>
            <?php foreach ($d['fiado'] as $x): ?>
                <tr><td><?= e($fe($x['fecha'])) ?></td><td><?= e($x['comprobante']) ?></td><td><?= e((string) $x['cliente']) ?></td><td class="n"><?= e($p($x['p_importe'])) ?></td></tr>
            <?php endforeach; ?>
            <tr class="total"><td colspan="3">Total a cuenta corriente</td><td class="n"><?= e($p($cuentaCorriente)) ?></td></tr>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($d['cheques'] !== []): ?>
        <h2><?= ++$n ?>. Cheques recibidos <small>(dónde está cada uno hoy)</small></h2>
        <table data-cheques>
            <thead><tr><th>Recibido</th><th>Cliente</th><th>Banco y número</th><th>Se cobra el</th><th>Dónde está</th><th class="n">Importe</th></tr></thead>
            <tbody>
            <?php foreach ($d['cheques'] as $x): ?>
                <tr><td><?= e($fe($x['fecha'])) ?></td><td><?= e((string) $x['cliente']) ?></td><td><?= e(trim((string) $x['banco'] . ' ' . (string) $x['p_nro'])) ?></td><td><?= e($fe($x['p_fecvto'])) ?></td>
                    <td><?= e(\App\Modules\CuentaCorriente\CuentaCorrienteService::ESTADOS_CHEQUE[(string) $x['cheque_estado']] ?? '') ?><?= $x['cheque_estado'] !== '' && $x['cheque_fecha'] ? ' el ' . e($fe($x['cheque_fecha'])) : '' ?><?= trim((string) $x['cheque_obs']) !== '' ? ' <span class="nota">' . e((string) $x['cheque_obs']) . '</span>' : '' ?></td>
                    <td class="n"><?= e($p($x['p_importe'])) ?></td></tr>
            <?php endforeach; ?>
            <?php foreach ($d['cheques_estado'] as $estado => $importe): ?>
                <tr class="sub"><td colspan="5"><?= e($estado) ?></td><td class="n"><?= e($p($importe)) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($d['mp']['cantidad'] > 0): $mp = $d['mp']; ?>
        <h2><?= ++$n ?>. Cuenta de Mercado Pago</h2>
        <table>
            <tbody>
                <tr><td>Pagos que entraron a la cuenta (<?= (int) $mp['cantidad'] ?>)</td><td class="n"><?= e($p($mp['monto'])) ?></td></tr>
                <?php if ($mp['sin_venta'] > 0): ?><tr><td>De eso, no vienen de una venta hecha en el sistema (transferencias, cobros por fuera…)</td><td class="n"><?= e($p($mp['sin_venta'])) ?></td></tr><?php endif; ?>
                <?php if (abs($mp['monto'] - $mp['neto']) > 0.004): ?><tr><td>Comisión de Mercado Pago</td><td class="n">− <?= e($p($mp['monto'] - $mp['neto'])) ?></td></tr><?php endif; ?>
                <tr class="total"><td>Le queda a la cuenta</td><td class="n"><?= e($p($mp['neto'])) ?></td></tr>
            </tbody>
        </table>
    <?php endif; ?>

    <h2><?= ++$n ?>. Detalle de cada cobro</h2>
    <?php if ($d['cobros'] === []): ?>
        <p class="vacio">No hubo cobros en el período.</p>
    <?php else: ?>
        <table data-detalle>
            <thead><tr><th><?= $unDia ? 'Hora' : 'Fecha' ?></th><th>Comprobante</th><th>Cliente</th><th class="ocultar-chico">Cajero</th><th class="n">Importe</th><th class="n">Vuelto</th></tr></thead>
            <tbody>
            <?php foreach ($d['cobros'] as $forma => $lista): $suma = 0.0; ?>
                <tr class="sub"><td colspan="6"><?= e($forma) ?></td></tr>
                <?php foreach ($lista as $x): $suma += (float) $x['p_importe']; ?>
                    <tr><td><?= e($unDia ? $hora($x['creado_en']) : $fe($x['fecha'])) ?></td><td><?= e($x['comprobante']) ?></td><td><?= e((string) $x['cliente']) ?></td>
                        <td class="ocultar-chico"><?= e((string) $x['usuario']) ?></td><td class="n"><?= e($p($x['p_importe'])) ?></td><td class="n"><?= (float) $x['p_vuelto'] > 0 ? e($p($x['p_vuelto'])) : '' ?></td></tr>
                <?php endforeach; ?>
                <tr class="total"><td colspan="4">Total <?= e($forma) ?> (<?= count($lista) ?>)</td><td class="n"><?= e($p($suma)) ?></td><td></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="firmas"><div>Entregó (cajero)</div><div>Recibió / controló</div></div>
    <footer><span>Reporte de caja <?= e($periodo) ?></span><span><?= e(Apariencia::nombreSistema()) ?></span></footer>
</main>
</body>
</html>
