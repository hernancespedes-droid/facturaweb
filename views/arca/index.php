<?php
/** @var string $modo @var string $etiqueta @var string $archivo @var array $pendientes @var int $pruebas @var bool $esMaster @var string $frase @var ?array $diagnostico */
?>
<div class="page-head">
    <div><p class="eyebrow">Sistema</p><h1>ARCA (factura electrónica)</h1></div>
    <span class="chip"><?= e($modo) ?></span>
</div>

<section class="card">
    <h2>Estado</h2>
    <p><b><?= e($etiqueta) ?></b></p>
    <p class="small muted">El modo y el archivo de configuración se definen en <code>config/local.php</code> (<code>afip</code> → <code>modo</code>: interno, simulado, homologacion o produccion).
        Solo se pide CAE en las sucursales con «Factura electrónica» tildada.
        <?php if ($archivo !== ''): ?>Configuración: <code><?= e($archivo) ?></code>.<?php endif; ?></p>
    <form method="post" action="<?= e(url('/arca/diagnostico')) ?>"><?= csrf_field() ?>
        <button class="btn" type="submit">Probar conexión</button>
    </form>
    <?php if (is_array($diagnostico)): ?>
        <div class="table-wrap"><table class="table table-cards">
            <thead><tr><th>Paso</th><th>Resultado</th><th>Detalle</th></tr></thead>
            <tbody><?php foreach ($diagnostico as $d): ?>
                <tr><td data-label="Paso"><?= e($d['paso']) ?></td><td data-label="Resultado"><?= $d['ok'] ? '✔ OK' : '✖ Falló' ?></td><td data-label="Detalle"><?= e($d['detalle']) ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Aprobados por ARCA y no grabados</h2>
    <?php if ($pendientes === []): ?>
        <p>No hay operaciones pendientes.</p>
    <?php else: ?>
        <p class="small muted">ARCA autorizó el comprobante pero no se llegó a guardar (o la conexión se cortó). «Grabar» lo guarda con el número y el CAE que ya dio ARCA.</p>
        <div class="table-wrap"><table class="table table-cards">
            <thead><tr><th>Fecha</th><th>Estado</th><th>PV / N°</th><th>CAE</th><th>Total</th><th>Detalle</th><th></th></tr></thead>
            <tbody><?php foreach ($pendientes as $p): ?>
                <tr><td data-label="Fecha" class="nw"><?= e(date('d/m/Y H:i', (int) strtotime($p['creado_en']))) ?></td>
                    <td data-label="Estado"><?= e($p['estado']) ?></td>
                    <td data-label="PV / N°"><?= e(sprintf('%04d-%08d', $p['pto_vta'], $p['numero'])) ?></td>
                    <td data-label="CAE"><?= e($p['cae']) ?></td>
                    <td data-label="Total" class="num"><?= e(plata_dec((float) $p['total'])) ?></td>
                    <td data-label="Detalle"><?= e((string) $p['detalle']) ?></td>
                    <td><?php if ($p['cae'] !== ''): ?>
                        <form method="post" action="<?= e(url('/arca/' . (int) $p['id'] . '/recuperar')) ?>"><?= csrf_field() ?><button class="btn" type="submit">Grabar</button></form>
                    <?php endif; ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>

<?php if ($esMaster): ?>
<section class="card">
    <h2>Comprobantes de prueba</h2>
    <p>Hay <b><?= $pruebas ?></b> comprobante(s) marcado(s) como prueba (internos, simulados o de homologación). No son fiscales.</p>
    <?php if ($pruebas > 0): ?>
    <form method="post" action="<?= e(url('/arca/borrar-pruebas')) ?>" data-confirmar="Se borran TODOS los comprobantes de prueba y no se pueden recuperar. ¿Seguro?">
        <?= csrf_field() ?>
        <label>Para confirmar escribí <code><?= e($frase) ?></code>
            <input type="text" name="frase" autocomplete="off" required></label>
        <button class="btn btn-danger" type="submit">Borrar comprobantes de prueba</button>
    </form>
    <?php endif; ?>
</section>
<?php endif; ?>
