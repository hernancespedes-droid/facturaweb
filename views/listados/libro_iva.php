<?php
/** @var string $periodo @var ?array<string,mixed> $libro @var bool $hayZip */
$q = static fn (string $archivo): string => url('/listados/libro-iva-digital/descargar') . '?' . http_build_query(['periodo' => $periodo, 'archivo' => $archivo]);
$mostrar = $libro !== null ? array_slice($libro['filas'], 0, 300) : [];
?>
<div class="page-head">
    <div><p class="eyebrow">Listados</p><h1>Libro IVA Digital — ventas</h1></div>
</div>
<form method="get" action="<?= e(url('/listados/libro-iva-digital')) ?>" class="toolbar filtros">
    <label class="field"><span>Mes a informar</span><input type="month" name="periodo" value="<?= e($periodo) ?>" required></label>
    <button class="btn btn-ghost" type="submit">Ver</button>
</form>

<?php if ($libro === null): ?>
    <div class="alerta alerta-error" role="alert">El mes «<?= e($periodo) ?>» no es válido. Elegí un mes con el formato AAAA-MM.</div>
<?php else: $t = $libro['totales']; $o = $libro['omitidos']; ?>
    <?php if ($libro['problemas'] !== []): ?>
        <div class="alerta alerta-error" role="alert"><b>Revisá esto antes de presentar</b> (ARCA lo puede rechazar):
            <ul><?php foreach (array_slice($libro['problemas'], 0, 30) as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul>
            <?= count($libro['problemas']) > 30 ? '<small>… y ' . (count($libro['problemas']) - 30) . ' más.</small>' : '' ?></div>
    <?php endif; ?>
    <?php if ($o['internos'] + $o['pruebas'] > 0): ?>
        <div class="alerta alerta-warn small">No entran al libro: <b><?= (int) $o['internos'] ?></b> comprobante(s) internos (sin CAE) y <b><?= (int) $o['pruebas'] ?></b> de prueba. Solo se informan los comprobantes con CAE de ARCA.</div>
    <?php endif; ?>
    <?php if ($libro['cantidad'] === 0): ?>
        <p class="muted vacio">No hay comprobantes con CAE en <?= e($periodo) ?>. En el Portal IVA se informa «sin movimiento».</p>
    <?php else: ?>
        <section class="card"><dl class="datos datos-col">
            <div><dt>Comprobantes</dt><dd><?= (int) $libro['cantidad'] ?></dd></div>
            <div><dt>Neto gravado</dt><dd><?= e(plata_dec($t['gravado'])) ?></dd></div>
            <div><dt>IVA</dt><dd><?= e(plata_dec($t['iva'])) ?></dd></div>
            <div><dt>Exento</dt><dd><?= e(plata_dec($t['exento'])) ?></dd></div>
            <div><dt>Total</dt><dd><b><?= e(plata_dec($t['total'])) ?></b> <small class="muted">(las notas de crédito restan)</small></dd></div>
        </dl></section>
        <div class="acciones">
            <a class="btn" href="<?= e($q('cbte')) ?>">Bajar comprobantes (CBTE)</a>
            <a class="btn" href="<?= e($q('alicuotas')) ?>">Bajar alícuotas</a>
            <?php if ($hayZip): ?><a class="btn btn-ghost" href="<?= e($q('zip')) ?>">Bajar los dos (ZIP)</a><?php endif; ?>
        </div>
        <section class="card">
            <h2>Cómo se presenta</h2>
            <ol>
                <li>Entrá al <b>Portal IVA</b> de ARCA (con clave fiscal) → <b>Libro de IVA Digital</b> → el período → <b>Ventas</b> → <b>Importar</b>.</li>
                <li>Subí <code>LIBRO_IVA_DIGITAL_VENTAS_CBTE</code> en «Comprobantes» y <code>LIBRO_IVA_DIGITAL_VENTAS_ALICUOTAS</code> en «Alícuotas» (los dos del mismo mes).</li>
                <li>Si el portal marca errores, copialos y avisá: el diseño sale del anexo oficial de la RG 4597 (revisión 07/2025).</li>
                <li>Confirmá el libro como declaración jurada en el portal. Vence dentro de los primeros 15 días corridos del mes siguiente.</li>
            </ol>
            <p class="small muted">Archivos en formato ANSI (ISO 8859-1). Las compras no están en este sistema: se importan aparte en el Portal IVA.</p>
        </section>
        <div class="table-wrap"><table class="table table-cards">
            <thead><tr><th>Fecha</th><th>Comprobante</th><th>Comprador</th><th class="num">Neto gravado</th><th class="num">IVA</th><th class="num">Exento</th><th class="num">Total</th><th class="num">Alíc.</th></tr></thead>
            <tbody>
            <?php foreach ($mostrar as $f): ?>
                <tr><td data-label="Fecha" class="nw"><?= e(fecha($f['fecha'])) ?></td><td data-label="Comprobante" class="nw"><?= e($f['titulo']) ?></td><td data-label="Comprador"><?= e($f['razon_social']) ?></td>
                    <td data-label="Neto gravado" class="num nw"><?= e(plata_dec($f['gravado'])) ?></td><td data-label="IVA" class="num nw"><?= e(plata_dec($f['iva'])) ?></td>
                    <td data-label="Exento" class="num nw"><?= e(plata_dec($f['exento'])) ?></td><td data-label="Total" class="num nw"><?= e(plata_dec($f['total'])) ?></td>
                    <td data-label="Alícuotas" class="num"><?= (int) $f['alicuotas'] ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if (count($libro['filas']) > count($mostrar)): ?><p class="small muted">Se muestran los primeros <?= count($mostrar) ?> de <?= (int) $libro['cantidad'] ?>; los archivos llevan todos.</p><?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
