<?php /** @var string $t @var array $x @var array $tabla @var array $campos @var array<string,int> $mapa @var array $op @var array $modelos @var int $modeloId @var array $depositos @var ?int $principal */
use App\Modules\Importacion\Destinos;
use App\Modules\Importacion\Lector;
$d = $x['destino'];
$titulos = $tabla['titulos'];
$muestra = array_slice($tabla['filas'], 0, 8);
$chk = static fn (string $k, bool $porOmision = false) => (!empty($op[$k]) || ($op === [] && $porOmision)) ? 'checked' : '';
$ejemplo = static function (int $i) use ($tabla): string {
    foreach ($tabla['filas'] as $f) {
        if (($f['celdas'][$i] ?? '') !== '') {
            return mb_strimwidth((string) $f['celdas'][$i], 0, 30, '…');
        }
    }
    return '';
};
?>
<div class="page-head"><div><p class="eyebrow">Importar datos · <?= e(Destinos::TITULOS[$d]) ?></p><h1>Elegí las columnas</h1></div></div>
<style>.importar-mapa{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px 18px;margin-bottom:8px}.importar-mapa .field{min-width:0}.importar-mapa select{width:100%}</style>
<p class="small muted"><b><?= e($x['nombre']) ?></b>: <?= number_format(count($tabla['filas']), 0, ',', '.') ?> filas<?= $tabla['separador'] !== null ? ', separadas por «' . e($tabla['separador'] === "\t" ? 'tabulador' : $tabla['separador']) . '»' : '' ?>.
    Lo que no tenga columna no se importa (al actualizar, queda como está).</p>

<?php if ($modelos !== []): ?>
<form method="get" action="<?= e(url('/importar/columnas')) ?>" class="toolbar card">
    <input type="hidden" name="t" value="<?= e($t) ?>">
    <label class="field"><span>Usar un modelo guardado</span><select name="modelo" onchange="this.form.submit()">
        <option value="0">— elegir —</option>
        <?php foreach ($modelos as $m): ?><option value="<?= (int) $m['id'] ?>" <?= $modeloId === $m['id'] ? 'selected' : '' ?>><?= e($m['nombre']) ?></option><?php endforeach; ?>
    </select></label>
    <noscript><button class="btn btn-ghost" type="submit">Usar</button></noscript>
</form>
<?php endif; ?>

<section class="card">
    <h2>Vista previa (primeras filas)</h2>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th>Fila</th><?php foreach ($titulos as $i => $tt): ?><th><?= e(Lector::letra($i)) ?> · <?= e($tt) ?></th><?php endforeach; ?></tr></thead>
        <tbody><?php foreach ($muestra as $f): ?>
            <tr><td data-label="Fila"><?= (int) $f['n'] ?></td><?php foreach ($f['celdas'] as $i => $c): ?><td data-label="<?= e(Lector::letra($i) . ' · ' . ($titulos[$i] ?? '')) ?>"><?= e(mb_strimwidth((string) $c, 0, 40, '…')) ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?></tbody>
    </table></div>
</section>

<form method="post" action="<?= e(url('/importar/procesar')) ?>" class="card"><?= csrf_field() ?>
    <input type="hidden" name="t" value="<?= e($t) ?>">
    <h2>Qué columna va a cada dato</h2>
    <div class="importar-mapa">
    <?php foreach ($campos as $k => $c): $sel = $mapa[$k] ?? null; ?>
        <label class="field"><span><b><?= e($c['label']) ?></b><?= !empty($c['requerido']) ? ' *' : '' ?></span>
            <select name="col[<?= e($k) ?>]" data-importar-col>
                <option value="">— no importar —</option>
                <?php foreach ($titulos as $i => $tt): ?><option value="<?= $i ?>" <?= $sel === $i ? 'selected' : '' ?>><?= e(Lector::letra($i) . ' · ' . $tt) ?></option><?php endforeach; ?>
            </select>
            <?php if (!empty($c['ayuda'])): ?><small class="muted"><?= e($c['ayuda']) ?></small><?php endif; ?>
            <?php if ($sel !== null && $ejemplo($sel) !== ''): ?><small class="muted">Ej.: <?= e($ejemplo($sel)) ?></small><?php endif; ?>
        </label>
    <?php endforeach; ?>
    </div>

    <h2>Opciones</h2>
    <div class="form-grid">
    <?php if (in_array($d, ['clientes', 'articulos'], true)): ?>
        <label class="field"><span>Si ya existe (mismo código<?= $d === 'clientes' ? '; sin código, mismo CUIT o nombre' : '' ?>)</span><select name="existentes">
            <option value="actualizar">Actualizarlo con las columnas elegidas</option>
            <option value="saltear" <?= ($op['existentes'] ?? '') === 'saltear' ? 'selected' : '' ?>>Dejarlo como está (solo agregar los nuevos)</option></select></label>
        <div class="field"><label class="check"><input type="checkbox" name="crear_faltantes" value="1" <?= $chk('crear_faltantes') ?>> Agregar a las tablas los rubros, vendedores, transportes y condiciones de pago que no existan</label></div>
    <?php elseif ($d === 'stock'): ?>
        <label class="field"><span>Qué hacer con la cantidad</span><select name="modo_stock">
            <option value="dejar">Dejar el stock en esa cantidad (stock inicial o conteo)</option>
            <option value="sumar" <?= ($op['modo_stock'] ?? '') === 'sumar' ? 'selected' : '' ?>>Sumarla a lo que hay (ingreso; negativa = egreso)</option></select></label>
        <label class="field"><span>Depósito si la fila no dice</span><select name="deposito">
            <?php foreach ($depositos as $dp): ?><option value="<?= (int) $dp['id'] ?>" <?= (int) ($op['deposito'] ?? $principal) === (int) $dp['id'] ? 'selected' : '' ?>><?= e($dp['nombre']) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Motivo (queda en cada movimiento)</span><input name="motivo" maxlength="120" value="<?= e((string) ($op['motivo'] ?? 'Stock inicial')) ?>"></label>
    <?php else: ?>
        <div class="field"><label class="check"><input type="checkbox" name="crear_clientes" value="1" <?= $chk('crear_clientes', true) ?>> Dar de alta los clientes que no estén (con la razón social y el CUIT del archivo)</label></div>
        <label class="field"><span>Cliente para los comprobantes sin cliente (código; opcional)</span><input name="cliente_defecto" inputmode="numeric" value="<?= (int) ($op['cliente_defecto'] ?? 0) ?: '' ?>" placeholder="Ej.: el cliente «Consumidor final»"></label>
        <p class="small muted field-ancho">Los comprobantes van al subdiario de IVA (y al Libro IVA Digital si tienen CAE) y, si viene el saldo, a la cuenta corriente. No mueven caja ni stock y
            no se piden a ARCA. Uno que ya esté (mismo tipo, letra, punto de venta, número y fecha) se saltea: se puede importar el mismo archivo de nuevo.</p>
    <?php endif; ?>
        <div class="field"><label class="check"><input type="checkbox" name="parcial" value="1" <?= $chk('parcial') ?>> Si hay filas con error, importar igual las demás</label></div>
        <label class="field"><span>Guardar esta elección como modelo (opcional)</span><input name="modelo_nombre" maxlength="60" placeholder="Ej.: Exportación del sistema anterior"></label>
    </div>
    <div class="acciones">
        <button class="btn" type="submit" name="accion" value="probar">Probar (no graba nada)</button>
        <button class="btn btn-ghost" type="submit" name="accion" value="importar" data-confirmar-boton="Se van a grabar los datos del archivo. ¿Importar?">Importar</button>
        <button class="btn btn-ghost" type="submit" name="accion" value="descartar" formnovalidate>Descartar el archivo</button>
    </div>
</form>
<?php if ($modelos !== []): ?>
<details class="card"><summary>Borrar un modelo guardado</summary>
    <?php foreach ($modelos as $m): ?>
    <form method="post" action="<?= e(url('/importar/modelo/borrar')) ?>" class="toolbar" data-confirmar="¿Borrar el modelo «<?= e($m['nombre']) ?>»?"><?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="t" value="<?= e($t) ?>">
        <span><?= e($m['nombre']) ?></span> <button class="btn btn-ghost btn-sm" type="submit">Borrar</button></form>
    <?php endforeach; ?>
</details>
<?php endif; ?>
