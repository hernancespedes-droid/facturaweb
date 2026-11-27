<?php
/** @var array<string,string> $f */
/** @var array<int,array<string,mixed>> $filas */
/** @var array<int,array<string,mixed>> $lotes */
/** @var array<int,array<string,mixed>> $formas */
/** @var array<int,array<string,mixed>> $sucursales */
/** @var string $cobroDefecto */
/** @var string $etiquetaArca */
/** @var bool $mailConfigurado */
use App\Modules\Comprobantes\Tipos;
use App\Modules\FacturacionMasiva\PreFacturasService;

$pendientes = array_filter($filas, static fn ($p) => $p['estado'] === 'pendiente');
$listas = array_filter($pendientes, static fn ($p) => (string) $p['errores'] === '');
$sumaListas = array_sum(array_map(static fn ($p) => (float) $p['total'], $listas));
$hoy = date('Y-m-d');
?>
<div class="page-head">
    <div><p class="eyebrow">Facturación · <?= e($etiquetaArca) ?></p><h1>Facturación masiva</h1></div>
    <div class="acciones"><a class="btn btn-ghost" href="<?= e(url('/facturacion-masiva/planilla')) ?>">Bajar planilla modelo</a></div>
</div>

<details class="card" <?= $filas === [] ? 'open' : '' ?>>
    <summary><b>Subir una planilla con facturas</b> <span class="small muted">— quedan como pre-facturas: no descuentan stock, no van a la caja ni a la cuenta corriente y no van a ARCA hasta que las apruebes.</span></summary>
    <form method="post" action="<?= e(url('/facturacion-masiva/subir')) ?>" enctype="multipart/form-data" class="form-grid" id="form-masiva-subir"><?= csrf_field() ?>
        <label class="field"><span>Planilla * (.xlsx, .csv o .txt)</span>
            <input type="file" name="archivo" accept=".xlsx,.csv,.txt,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></label>
        <label class="field"><span>Si la planilla no dice cómo se cobra</span>
            <select name="cobro">
                <option value="CC" <?= $cobroDefecto === 'CC' ? 'selected' : '' ?>>Cuenta corriente</option>
                <?php foreach ($formas as $fp): if ((int) $fp['tipo'] === 6) { continue; } ?>
                    <option value="<?= e($fp['codigo']) ?>" <?= $cobroDefecto === (string) $fp['codigo'] ? 'selected' : '' ?>><?= e(ucfirst(mb_strtolower((string) $fp['descripcion']))) ?></option>
                <?php endforeach; ?>
            </select></label>
        <?php if (count($sucursales) > 1): ?>
            <label class="field"><span>Si la planilla no dice el punto de venta</span>
                <select name="sucursal"><?php foreach ($sucursales as $cod => $s): ?><option value="<?= e($cod) ?>"><?= e($s['descripcion'] . ' · PV ' . sprintf('%04d', $s['prefijo'])) ?></option><?php endforeach; ?></select></label>
        <?php endif; ?>
        <div class="small muted field-ancho">
            <p>Una fila por factura. Para una factura con varios renglones, repetí la misma <b>Referencia</b> en cada fila (los datos de la factura van en la primera).
                Columnas que se reconocen por el título: <b>Cliente</b> (código, CUIT o razón social, obligatorio), Referencia, Fecha, Artículo, <b>Descripción</b>, Cantidad (sin dato: 1),
                <b>Precio</b> (final, con IVA; o «Precio neto» sin IVA; sin precio se toma el del artículo), IVA %, Descuento %, Forma de pago, Punto de venta, Concepto ARCA,
                Servicio desde / hasta, Vencimiento del pago, Vendedor, Orden de compra, Mail y Observaciones.</p>
            <p>Sin fecha, cada factura sale con la fecha del día en que se aprueba. ARCA acepta hasta 5 días antes o después (10 con servicios).</p>
        </div>
        <div class="acciones"><button class="btn" type="submit">Subir y armar las pre-facturas</button></div>
    </form>
</details>

<form method="get" action="<?= e(url('/facturacion-masiva')) ?>" class="toolbar filtros">
    <select name="estado" aria-label="Estado"><?php foreach (['' => 'Pendientes', 'emitidas' => 'Facturadas', 'todas' => 'Todas'] as $k => $t): ?>
        <option value="<?= $k ?>" <?= ($f['estado'] ?? '') === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select>
    <select name="lote" aria-label="Planilla"><option value="">Todas las planillas</option>
        <?php foreach ($lotes as $l): ?><option value="<?= (int) $l['id'] ?>" <?= ($f['lote'] ?? '') === (string) $l['id'] ? 'selected' : '' ?>>
            <?= e(date('d/m/Y H:i', (int) strtotime((string) $l['creado_en'])) . ' · ' . $l['archivo'] . ' (' . (int) $l['pendientes'] . ' pendientes de ' . (int) $l['prefacturas'] . ')') ?></option><?php endforeach; ?></select>
    <button class="btn btn-ghost" type="submit">Ver</button>
</form>

<?php if ($filas === []): ?>
    <p class="muted vacio"><?= ($f['estado'] ?? '') === '' ? 'No hay pre-facturas pendientes. Subí una planilla para cargarlas.' : 'No hay pre-facturas para ese filtro.' ?></p>
<?php else: ?>
<form method="post" action="<?= e(url('/facturacion-masiva/procesar')) ?>" id="form-masiva" data-masiva data-url-procesar="<?= e(url('/facturacion-masiva/procesar')) ?>"
      data-url-comprobante="<?= e(url('/comprobantes')) ?>"><?= csrf_field() ?>
    <input type="hidden" name="lote" value="<?= e($f['lote'] ?? '') ?>">
    <?php if ($pendientes !== []): ?>
    <div class="card masiva-barra">
        <p><b data-masiva-elegidas>0</b> elegidas · total <b data-masiva-total>$ 0</b>
            <span class="small muted">· pendientes listas para facturar: <?= count($listas) ?> de <?= count($pendientes) ?> (<?= e(plata_dec($sumaListas)) ?>)</span></p>
        <div class="acciones">
            <label class="check small"><input type="checkbox" name="mail" value="1" <?= $mailConfigurado ? '' : 'disabled' ?>> Mandar cada factura por mail al cliente<?= $mailConfigurado ? '' : ' (el mail de salida no está configurado)' ?></label>
            <button class="btn" type="submit" data-masiva-procesar data-requiere-tildadas="ids[]" data-confirmar-boton="¿Aprobar y facturar las pre-facturas elegidas? Cada una se emite en ARCA y descuenta stock, entra a la caja o a la cuenta corriente según cómo se cobra.">Aprobar y facturar las elegidas</button>
            <button class="btn btn-ghost" type="submit" formaction="<?= e(url('/facturacion-masiva/revisar')) ?>" data-masiva-otra data-requiere-tildadas="ids[]">Volver a revisar</button>
            <button class="btn btn-ghost" type="submit" formaction="<?= e(url('/facturacion-masiva/eliminar')) ?>" data-masiva-otra data-requiere-tildadas="ids[]" data-confirmar-boton="¿Eliminar las pre-facturas elegidas? No se facturan.">Eliminar las elegidas</button>
        </div>
        <div class="masiva-avance" data-masiva-avance hidden>
            <progress max="1" value="0" data-masiva-progreso></progress> <span data-masiva-avance-texto></span>
        </div>
    </div>
    <?php endif; ?>
    <div class="table-wrap">
        <table class="table table-cards" data-masiva-tabla>
            <thead><tr>
                <th><?php if ($pendientes !== []): ?><input type="checkbox" data-masiva-todas aria-label="Elegir todas las listas" title="Elegir todas las listas para facturar"><?php endif; ?></th>
                <th>Referencia</th><th>Fecha</th><th>Cliente</th><th>Letra</th><th>Cobro</th><th class="num">Total</th><th>Estado</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($filas as $p): $id = (int) $p['id']; $lista = $p['estado'] === 'pendiente' && (string) $p['errores'] === ''; ?>
                <tr id="pf-<?= $id ?>" data-pf="<?= $id ?>" data-orden="<?= e(($p['fecha'] ?? $hoy) . '-' . sprintf('%010d', $id)) ?>" data-total="<?= e((string) (float) $p['total']) ?>"
                    data-lista="<?= $lista ? '1' : '0' ?>" class="<?= $p['estado'] === 'pendiente' && !$lista ? 'fila-error' : '' ?>">
                    <td data-label="Elegir"><?php if ($p['estado'] === 'pendiente' || $p['estado'] === 'procesando'): ?><input type="checkbox" name="ids[]" value="<?= $id ?>" data-masiva-check aria-label="Elegir <?= e($p['referencia']) ?>"><?php endif; ?></td>
                    <td data-label="Referencia"><?= e($p['referencia']) ?><?= $p['filas_planilla'] !== '' ? '<br><small class="muted">fila ' . e($p['filas_planilla']) . '</small>' : '' ?></td>
                    <td data-label="Fecha" class="nw"><?= $p['fecha'] !== null ? e(fecha($p['fecha'])) : '<span class="muted" title="La del día en que se factura">Al facturar</span>' ?></td>
                    <td data-label="Cliente"><?= $p['razon_social'] !== null ? e($p['razon_social']) . ' <small class="muted">(' . e($p['cliente']) . ')</small>' : '<span class="field-error">' . e($p['cliente_texto'] !== '' ? $p['cliente_texto'] : 'Sin cliente') . '</span>' ?></td>
                    <td data-label="Letra"><?= e($p['letra']) ?></td>
                    <td data-label="Cobro"><?= e($p['cobro']) ?></td>
                    <td data-label="Total" class="num nw"><?= $p['total'] !== null ? e(plata_dec($p['total'])) : '—' ?></td>
                    <td data-label="Estado" data-pf-estado>
                        <?php if ($p['estado'] === 'emitida' && $p['s_tipo'] === null): ?>
                            <span class="badge badge-off">Facturada</span> <small class="muted">(el comprobante ya no está: se borró con los de prueba)</small>
                        <?php elseif ($p['estado'] === 'emitida'): ?>
                            <span class="badge badge-ok">Facturada</span> <a href="<?= e(url('/comprobantes/' . (int) $p['subdiario_id'])) ?>"><?= e(Tipos::titulo((string) $p['s_tipo'], (string) $p['s_letra']) . ' ' . numero_visible($p)) ?></a>
                            <?= $p['aviso'] !== null ? '<br><small class="muted">' . e($p['aviso']) . '</small>' : '' ?>
                        <?php elseif ($lista): ?>
                            <span class="badge badge-ok">Lista para facturar</span>
                        <?php else: ?>
                            <span class="badge <?= $p['estado'] === 'procesando' ? 'badge-off' : 'badge-warn' ?>"><?= e($p['estado'] === 'procesando' ? 'Facturando' : 'Para corregir') ?></span>
                            <?php if ((string) $p['errores'] !== ''): ?><br><small class="field-error"><?= nl2br(e((string) $p['errores'])) ?></small><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="" class="nw"><?php if ($p['estado'] === 'pendiente'): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('/facturacion-masiva/' . $id . '/modificar')) ?>">Modificar</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</form>
<p class="small muted"><?= count($filas) ?> pre-factura(s). Se facturan en orden de fecha (las que no tienen fecha, con la de hoy), de a una: si una no se puede facturar queda pendiente con el motivo y siguen las demás.</p>
<?php endif; ?>
<script src="<?= e(asset('assets/facturacion-masiva.js')) ?>" defer></script>
