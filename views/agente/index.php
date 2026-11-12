<?php /** @var array $claves @var array $envios @var ?array $nueva */
\App\Modules\Agente\AgenteController::olvidar();
?>
<div class="page-head"><div><p class="eyebrow">Sistema</p><h1>Agente de sincronización</h1></div></div>
<p class="small muted">El agente es un programa chico que corre en la PC del sistema VFP (Windows 10). Cada tanto manda a la web los comprobantes nuevos del VFP, para que
    el subdiario de IVA y el stock de la web los incluyan. Se conecta él a la web (por HTTPS) con una clave; no hace falta abrir nada en la red del cliente.
    Guía de instalación: <code>agente-windows/LEEME.md</code>.</p>

<?php if ($nueva): ?>
<section class="card">
    <h2>Clave nueva: <?= e($nueva['nombre']) ?></h2>
    <p class="alerta alerta-clave" role="alert"><?= e($nueva['clave']) ?></p>
    <p><b>Copiala ahora</b> y pegala en la configuración del agente: por seguridad no se vuelve a mostrar. Si la perdés, revocá esta y creá otra.</p>
</section>
<?php endif; ?>

<section class="card">
    <h2>Claves</h2>
    <?php if ($claves === []): ?><p class="muted">Todavía no hay claves.</p><?php else: ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th>Nombre</th><th>Clave</th><th>Estado</th><th>Último uso</th><th>Desde</th><th></th></tr></thead>
        <tbody><?php foreach ($claves as $c): ?>
            <tr><td data-label="Nombre"><?= e($c['nombre']) ?></td><td data-label="Clave"><code><?= e($c['prefijo']) ?>…</code></td>
                <td data-label="Estado"><?= (int) $c['activa'] === 1 ? 'Activa' : 'Revocada' ?></td>
                <td data-label="Último uso"><?= $c['ultimo_uso'] ? e(date('d/m/Y H:i', (int) strtotime($c['ultimo_uso']))) : 'nunca' ?></td>
                <td data-label="Desde"><?= e((string) $c['ultimo_ip']) ?></td>
                <td><?php if ((int) $c['activa'] === 1): ?><form method="post" action="<?= e(url('/agente/' . (int) $c['id'] . '/revocar')) ?>" data-confirmar="El agente que use esta clave dejará de poder enviar. ¿Revocar?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Revocar</button></form><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/agente')) ?>" class="toolbar"><?= csrf_field() ?>
        <input name="nombre" placeholder="Nombre (ej. PC del VFP de OYS)" maxlength="60" required aria-label="Nombre de la clave">
        <button class="btn" type="submit">Crear clave</button>
    </form>
</section>

<section class="card">
    <h2>Últimos envíos</h2>
    <?php if ($envios === []): ?><p class="muted">El agente todavía no envió nada.</p><?php else: ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th>Fecha</th><th>Clave</th><th>Comprobantes nuevos</th><th>Avisos</th></tr></thead>
        <tbody><?php foreach ($envios as $e1): $r = json_decode((string) $e1['resumen'], true) ?: []; ?>
            <tr><td data-label="Fecha" class="nw"><?= e(date('d/m/Y H:i', (int) strtotime($e1['fecha']))) ?></td>
                <td data-label="Clave"><?= e(substr((string) $e1['origen'], 7)) ?></td>
                <td data-label="Nuevos"><?= (int) ($r['subdiario']['grabadas'] ?? 0) ?> de <?= (int) ($r['subdiario']['leidas'] ?? 0) ?> leídos</td>
                <td data-label="Avisos"><?= (int) array_sum(array_map(static fn ($x) => count($x['avisos'] ?? []), $r)) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Web → VFP (lo emitido en la web)</h2>
    <p class="muted">El agente baja a la PC del VFP los comprobantes emitidos en la web con validez fiscal (con su IVA, renglones, caja y cuenta corriente) y los movimientos de stock.
        Un programa dentro del VFP (<code>IMPORTAR_WEB</code>) los graba y el agente le avisa a la web cuáles quedaron grabados.</p>
    <p><b><?= (int) $vfp['pendientes'] ?></b> comprobante(s) y <b><?= (int) $vfp['movimientos'] ?></b> movimiento(s) de stock sueltos esperan ir al VFP.
        <?php if ((int) $vfp['con_error'] > 0): ?><b class="text-danger"><?= (int) $vfp['con_error'] ?> rechazado(s) por el VFP</b>.<?php endif; ?></p>
    <?php if ($vfp['errores'] !== []): ?>
        <table class="table"><thead><tr><th>Comprobante</th><th>Fecha</th><th>Por qué lo rechazó el VFP</th></tr></thead><tbody>
        <?php foreach ($vfp['errores'] as $f): ?>
            <tr><td><a href="<?= e(url('/comprobantes/' . (int) $f['fe_clave'])) ?>"><?= e($f['tipo'] . ' ' . $f['letra'] . ' ' . str_pad((string) $f['prefijo'], 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) $f['comprobante'], 8, '0', STR_PAD_LEFT)) ?></a></td>
                <td><?= e(fecha($f['fecha'])) ?></td><td><?= e($f['vfp_error']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <form method="post" action="<?= e(url('/agente/vfp/reintentar')) ?>"><?= csrf_field() ?>
            <button class="btn" type="submit">Reintentar los rechazados</button> <span class="muted">(después de arreglar el motivo en el VFP)</span></form>
    <?php endif; ?>
</section>
