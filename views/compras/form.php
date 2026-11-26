<?php
use App\Modules\Compras\ComprasService as S;

/**
 * Nueva compra o gasto. @var array $datos @var array<string,string> $errores @var ?array $prov @var ?array $provElegir @var array $centros @var array $conceptos
 * @var array $plantillas @var int $general @var int $conceptoOmision @var bool $inscripta @var string $operacion @var ?array $turno @var bool $puedeCentros
 * @var array $depositos @var bool $stockActivo
 */
$d = $datos;
$v = static fn (string $k, string $def = '') => e((string) ($d[$k] ?? $def));
$err = static fn (string $k) => isset($errores[$k]) ? '<span class="field-error">' . e($errores[$k]) . '</span>' : '';
$tipo = (string) ($d['tipo'] ?? 'FC');
$forma = (string) ($d['forma'] ?? 'C');
$rep = array_values(array_filter(is_array($d['rep'] ?? null) ? $d['rep'] : [], static fn ($r) => is_array($r) && (($r['centro'] ?? '') !== '' || trim((string) ($r['porcentaje'] ?? '')) !== '')));
$trib = array_values(array_filter(is_array($d['trib'] ?? null) ? $d['trib'] : [], static fn ($t) => is_array($t) && trim((string) ($t['importe'] ?? '')) !== ''));
$netos = is_array($d['neto'] ?? null) ? $d['neto'] : [];
$ivas = is_array($d['iva'] ?? null) ? $d['iva'] : [];
$centroSel = (int) ($d['centro_id'] ?? 0) ?: (int) ($prov['centro_id'] ?? 0) ?: $general;
$conceptoSel = (int) ($d['concepto_id'] ?? 0) ?: (int) ($prov['concepto_id'] ?? 0) ?: $conceptoOmision;
$opcCentros = static function (int $sel) use ($centros): string {
    $h = '';
    foreach ($centros as $id => $c) {
        $h .= '<option value="' . (int) $id . '"' . ($id === $sel ? ' selected' : '') . '>' . ($c['nivel'] ? '— ' : '') . e($c['nombre']) . '</option>';
    }
    return $h;
};
$opcConceptos = static function (int $sel) use ($conceptos): string {
    $h = '';
    foreach ($conceptos as $id => $c) {
        $h .= '<option value="' . (int) $id . '"' . ($id === $sel ? ' selected' : '') . '>' . e($c['nombre']) . '</option>';
    }
    return $h;
};
?>
<div class="page-head"><div><p class="eyebrow">Compras</p><h1>Nueva compra o gasto</h1></div>
    <div class="acciones"><a class="btn btn-ghost" href="<?= e(url('/compras/qr')) ?>">Leer el QR de la factura</a></div></div>
<?php if (!empty($d['avisos_arca']) && is_array($d['avisos_arca'])): ?><div class="alerta alerta-info" role="status"><b><?= ($d['origen_arca'] ?? '') === 'qr' ? 'Datos leídos del QR de la factura.' : 'Datos de Mis Comprobantes de ARCA.' ?></b>
    <?php foreach ($d['avisos_arca'] as $av): ?><br><?= e((string) $av) ?><?php endforeach; ?></div><?php endif; ?>
<?php if (isset($errores['general'])): ?><div class="alerta alerta-error" role="alert"><?= e($errores['general']) ?></div><?php endif; ?>
<?php if ($errores !== [] && !isset($errores['general'])): ?><div class="alerta alerta-error" role="alert">Revisá lo marcado en rojo.</div><?php endif; ?>

<form method="post" action="<?= e(url('/compras/nueva')) ?>" enctype="multipart/form-data" id="form-compra" data-inscripta="<?= $inscripta ? '1' : '0' ?>"
      data-general="<?= (int) $general ?>" data-concepto="<?= (int) $conceptoOmision ?>" data-plantillas="<?= e(json_encode(array_values($plantillas), JSON_UNESCAPED_UNICODE)) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="operacion" value="<?= e($operacion) ?>">

    <section class="card form-grid grid-campos">
        <h2>Comprobante</h2>
        <div>
            <?php $nombre = 'proveedor'; $titulo = 'Proveedor *'; $autofocus = $prov === null; include __DIR__ . '/_proveedor.php'; ?>
            <?= $err('proveedor') ?>
            <p class="small"><a href="<?= e(url('/compras/proveedores/ficha?volver=nueva')) ?>">+ Nuevo proveedor</a></p>
            <?php if ($provElegir !== null): ?><script type="application/json" id="prov-inicial"><?= json_encode($provElegir, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?></script><?php endif; ?>
        </div>
        <label class="field"><span>Tipo *</span><select name="tipo" data-tipo>
            <?php foreach (S::TIPOS as $k => $t): ?><option value="<?= e($k) ?>" <?= $tipo === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select><?= $err('tipo') ?></label>
        <label class="field" data-con-numero><span>Letra *</span><select name="letra" data-letra>
            <?php foreach (S::LETRAS as $l): ?><option value="<?= e($l) ?>" <?= ($d['letra'] ?? '') === $l ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?= $err('letra') ?></label>
        <div class="field" data-con-numero><span>Punto de venta y número *</span>
            <div class="campo-con-boton"><input name="punto_venta" value="<?= $v('punto_venta') ?>" inputmode="numeric" maxlength="5" size="6" placeholder="00003" aria-label="Punto de venta">
                <input name="numero" value="<?= $v('numero') ?>" inputmode="numeric" maxlength="8" placeholder="00014587" aria-label="Número"></div>
            <?= $err('punto_venta') ?><?= $err('numero') ?></div>
        <label class="field"><span>Fecha *</span><input name="fecha" value="<?= $v('fecha', date('d/m/Y')) ?>" placeholder="dd/mm/aaaa" data-fecha inputmode="numeric" required><?= $err('fecha') ?></label>
        <label class="field" data-con-numero><span>Período de IVA</span><input name="periodo_iva" value="<?= $v('periodo_iva') ?>" placeholder="El mes de la fecha" inputmode="numeric" data-periodo><?= $err('periodo_iva') ?></label>
        <label class="field" data-con-numero><span>CAE (optativo)</span><input name="cae" value="<?= $v('cae') ?>" inputmode="numeric" maxlength="14"><?= $err('cae') ?></label>
    </section>

    <section class="card">
        <h2>Importes</h2>
        <p class="small muted" data-aviso-iva></p>
        <div data-discrimina>
            <div class="table-wrap"><table class="table compra-importes" data-grilla="no">
                <thead><tr><th>Alícuota</th><th class="num">Neto gravado</th><th class="num">IVA</th></tr></thead>
                <tbody>
                <?php foreach (S::ALICUOTAS as $k => $a): ?>
                    <tr data-alicuota="<?= e((string) $a) ?>"><td>IVA <?= e(str_replace('.', ',', (string) $k)) ?> %</td>
                        <td class="num"><input name="neto[<?= e($k) ?>]" value="<?= e((string) ($netos[$k] ?? '')) ?>" inputmode="decimal" data-decimales="2" data-neto aria-label="Neto al <?= e($k) ?> %"></td>
                        <td class="num"><input name="iva[<?= e($k) ?>]" value="<?= e((string) ($ivas[$k] ?? '')) ?>" inputmode="decimal" data-decimales="2" data-iva aria-label="IVA al <?= e($k) ?> %"></td></tr>
                <?php endforeach; ?>
                    <tr><td>No gravado</td><td class="num"><input name="no_gravado" value="<?= $v('no_gravado') ?>" inputmode="decimal" data-decimales="2" data-suma aria-label="No gravado"></td><td></td></tr>
                    <tr><td>Exento</td><td class="num"><input name="exento" value="<?= $v('exento') ?>" inputmode="decimal" data-decimales="2" data-suma aria-label="Exento"></td><td></td></tr>
                </tbody>
            </table></div>
            <?= $err('neto') ?><?= $err('no_gravado') ?><?= $err('exento') ?>
        </div>
        <div data-sin-discriminar>
            <label class="field" style="max-width:18rem"><span>Importe (sin las percepciones)</span><input name="importe" value="<?= $v('importe') ?>" inputmode="decimal" data-decimales="2" data-suma></label>
            <?= $err('importe') ?>
        </div>

        <h3>Percepciones e impuestos</h3>
        <div class="table-wrap"><table class="table" data-grilla="no" data-tributos>
            <thead><tr><th>Impuesto</th><th>Jurisdicción o detalle</th><th class="num">Importe</th><th></th></tr></thead>
            <tbody>
            <?php foreach (($trib !== [] ? $trib : [['tipo' => 'IIBB']]) as $i => $t): ?>
                <tr><td><select name="trib[<?= $i ?>][tipo]" aria-label="Impuesto"><?php foreach (S::TRIBUTOS as $tk => $tt): ?><option value="<?= e($tk) ?>" <?= ($t['tipo'] ?? '') === $tk ? 'selected' : '' ?>><?= e($tt) ?></option><?php endforeach; ?></select></td>
                    <td><input name="trib[<?= $i ?>][detalle]" value="<?= e((string) ($t['detalle'] ?? '')) ?>" maxlength="60" placeholder="Buenos Aires, CABA…" aria-label="Detalle"></td>
                    <td class="num"><input name="trib[<?= $i ?>][importe]" value="<?= e((string) ($t['importe'] ?? '')) ?>" inputmode="decimal" data-decimales="2" data-suma aria-label="Importe de la percepción"></td>
                    <td><button type="button" class="btn btn-ghost btn-sm" data-quitar aria-label="Quitar">✕</button></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= $err('trib') ?>
        <p><button type="button" class="btn btn-ghost btn-sm" data-agregar-trib>+ Agregar percepción</button></p>

        <div class="compra-total">
            <label class="field"><span>Total impreso en el comprobante</span><input name="total_impreso" value="<?= $v('total_impreso') ?>" inputmode="decimal" data-decimales="2" data-impreso></label>
            <div><span class="muted small">Total calculado</span><br><b data-total>$ 0,00</b> <span data-control></span></div>
            <div><span class="muted small">Costo para los centros</span><br><b data-costo>$ 0,00</b></div>
        </div>
        <?= $err('total_impreso') ?>
    </section>

    <?php $items = array_values(array_filter(is_array($d['items'] ?? null) ? $d['items'] : [], static fn ($x) => is_array($x) && trim((string) ($x['articulo'] ?? '')) !== ''));
    $conArt = ($d['con_articulos'] ?? '') === '1'; ?>
    <section class="card">
        <h2>Artículos</h2>
        <label class="check"><input type="checkbox" name="con_articulos" value="1" <?= $conArt ? 'checked' : '' ?> data-con-articulos> Con artículos: suman stock<?= $stockActivo ? '' : ' (el control de stock está apagado: solo se registran)' ?> y actualizan el costo</label>
        <div data-articulos<?= $conArt ? '' : ' hidden' ?>>
            <div class="table-wrap"><table class="table compra-items" data-grilla="no">
                <thead><tr><th>Artículo</th><th class="num">Cantidad</th><th class="num" data-costo-titulo>Costo unitario</th><th class="num">Total</th><th></th></tr></thead>
                <tbody data-filas-items>
                <?php foreach (($items !== [] ? $items : [[]]) as $i => $it): ?>
                    <tr><td data-label="Artículo"><div class="campo-con-boton"><input name="items[<?= $i ?>][articulo]" value="<?= e((string) ($it['articulo'] ?? '')) ?>" maxlength="60" autocomplete="off"
                            placeholder="Código, barras, SKU o descripción" data-buscador-url="<?= e(url('/compras/buscar-articulo')) ?>" data-item-articulo data-con-lista aria-label="Artículo"><?= icono_lista('data-lista-articulos', 'artículos') ?></div></td>
                        <td data-label="Cantidad" class="num"><input name="items[<?= $i ?>][cantidad]" value="<?= e((string) ($it['cantidad'] ?? '')) ?>" inputmode="decimal" data-item-cant aria-label="Cantidad"></td>
                        <td data-label="Costo unitario" class="num"><input name="items[<?= $i ?>][costo]" value="<?= e((string) ($it['costo'] ?? '')) ?>" inputmode="decimal" data-item-costo aria-label="Costo unitario"></td>
                        <td data-label="Total" class="num" data-item-total></td>
                        <td><button type="button" class="btn btn-ghost btn-sm" data-quitar-item aria-label="Quitar">✕</button></td></tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><th colspan="3">Suma de los artículos</th><th class="num" data-items-suma></th><th></th></tr></tfoot>
            </table></div>
            <?= $err('items') ?>
            <div class="toolbar">
                <button type="button" class="btn btn-ghost btn-sm" data-agregar-item>+ Agregar artículo</button>
                <?php if (count($depositos) > 1): ?>
                    <label class="field"><span>Depósito</span><select name="deposito"><?php foreach ($depositos as $dep): ?><option value="<?= (int) $dep['id'] ?>" <?= (int) ($d['deposito'] ?? 0) === (int) $dep['id'] || ((int) ($d['deposito'] ?? 0) === 0 && (int) $dep['principal'] === 1) ? 'selected' : '' ?>><?= e($dep['nombre']) ?></option><?php endforeach; ?></select></label>
                <?php endif; ?>
                <label class="check"><input type="checkbox" name="actualizar_costo" value="1" <?= ($d['actualizar_costo'] ?? '1') === '1' ? 'checked' : '' ?>> Actualizar el costo de los artículos</label>
            </div>
            <p class="small muted">El costo unitario va <span data-costo-aclara>sin IVA</span>. Los artículos no tienen que sumar todo el comprobante (puede haber flete u otros gastos). Una nota de crédito con artículos los descuenta del stock.</p>
        </div>
    </section>

    <section class="card">
        <h2>¿Para qué parte del negocio?</h2>
        <div class="form-grid grid-campos" data-simple<?= $rep !== [] ? ' hidden' : '' ?>>
            <label class="field"><span>Centro de costos</span><select name="centro_id" data-centro><?= $opcCentros($centroSel) ?></select></label>
            <label class="field"><span>Concepto</span><select name="concepto_id" data-concepto><?= $opcConceptos($conceptoSel) ?></select></label>
            <div class="acciones"><button type="button" class="btn btn-ghost" data-repartir>Repartir en varios centros</button></div>
        </div>
        <div data-reparto<?= $rep === [] ? ' hidden' : '' ?>>
            <?php if ($plantillas !== []): ?>
                <label class="field" style="max-width:24rem"><span>Usar una plantilla</span><select data-plantilla><option value="">Elegí una plantilla…</option>
                    <?php foreach ($plantillas as $pl): ?><option value="<?= (int) $pl['id'] ?>"><?= e($pl['nombre']) ?></option><?php endforeach; ?></select></label>
            <?php endif; ?>
            <div class="table-wrap"><table class="table reparto-tabla" data-grilla="no">
                <thead><tr><th>Centro de costos</th><th>Concepto</th><th class="num">%</th><th class="num">Importe</th><th></th></tr></thead>
                <tbody data-filas-reparto>
                <?php foreach (($rep !== [] ? $rep : [['centro' => $centroSel, 'concepto' => $conceptoSel, 'porcentaje' => '100']]) as $i => $r): ?>
                    <tr><td><select name="rep[<?= $i ?>][centro]" aria-label="Centro"><?= $opcCentros((int) ($r['centro'] ?? 0)) ?></select></td>
                        <td><select name="rep[<?= $i ?>][concepto]" aria-label="Concepto"><?= $opcConceptos((int) ($r['concepto'] ?? 0)) ?></select></td>
                        <td class="num"><input name="rep[<?= $i ?>][porcentaje]" value="<?= e((string) ($r['porcentaje'] ?? '')) ?>" inputmode="decimal" aria-label="Porcentaje" data-pct></td>
                        <td class="num" data-imp></td>
                        <td><button type="button" class="btn btn-ghost btn-sm" data-quitar aria-label="Quitar">✕</button></td></tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><th colspan="2">Asignado <span data-estado-reparto></span></th><th class="num" data-suma-pct></th><th class="num" data-suma-imp></th><th></th></tr></tfoot>
            </table></div>
            <?= $err('rep') ?>
            <div class="toolbar">
                <button type="button" class="btn btn-ghost btn-sm" data-agregar-centro>+ Agregar centro</button>
                <button type="button" class="btn btn-ghost btn-sm" data-un-centro>Volver a un solo centro</button>
                <?php if ($puedeCentros): ?>
                    <label class="check"><input type="checkbox" name="guardar_plantilla" value="1" data-guardar-pl> Guardar este reparto como plantilla</label>
                    <input name="nombre_plantilla" maxlength="60" placeholder="Nombre (ej.: Luz y gas)" hidden data-nombre-pl aria-label="Nombre de la plantilla">
                <?php endif; ?>
            </div>
            <p class="small muted">El reparto tiene que sumar 100 %. Se reparte el costo (sin IVA computable ni percepciones); el último centro se lleva los centavos del redondeo.</p>
        </div>
    </section>

    <section class="card form-grid grid-campos">
        <h2>¿Cómo se paga?</h2>
        <fieldset class="field" style="grid-column:1/-1"><legend class="sr-only">Forma de pago</legend>
            <div class="toolbar" role="radiogroup">
                <?php foreach (S::FORMAS as $fk => $ft): ?>
                    <label class="check"><input type="radio" name="forma" value="<?= e($fk) ?>" <?= $forma === $fk ? 'checked' : '' ?> data-forma> <?= e($ft) ?></label>
                <?php endforeach; ?>
            </div></fieldset>
        <?= $err('forma') ?>
        <label class="field" data-si-forma="C"><span>Vence</span><input name="vence" value="<?= $v('vence') ?>" placeholder="Fecha + plazo del proveedor" inputmode="numeric" data-vence data-fecha><?= $err('vence') ?></label>
        <label class="field" data-si-forma="O"><span>Medio y detalle</span><input name="detalle_pago" value="<?= $v('detalle_pago') ?>" maxlength="120" placeholder="Transferencia Banco Nación, tarjeta…"></label>
        <?php if ($turno === null): ?><p class="small" data-si-forma="E"><span class="pill pill-warn">Sin turno de caja</span> Para pagar en efectivo abrí tu turno en <a href="<?= e(url('/caja')) ?>">Mi caja</a>.</p><?php endif; ?>
    </section>

    <section class="card form-grid grid-campos">
        <h2>Foto o PDF y notas</h2>
        <label class="field"><span>Adjuntar (hasta 5 archivos de 5 MB: foto o PDF)</span><input type="file" name="adjuntos[]" accept="image/jpeg,image/png,image/webp,application/pdf" multiple></label>
        <label class="field"><span>Observaciones</span><input name="observaciones" value="<?= $v('observaciones') ?>" maxlength="255"><?= $err('observaciones') ?></label>
        <label class="check"><input type="checkbox" name="a_revisar" value="1" <?= ($d['a_revisar'] ?? '') === '1' ? 'checked' : '' ?>> Marcar para revisar después</label>
    </section>

    <div class="acciones">
        <button class="btn" type="submit">Grabar compra</button>
        <button class="btn btn-ghost" type="submit" name="seguir" value="1">Grabar y cargar otra</button>
    </div>
</form>
<script src="<?= e(asset('assets/ventana-lista.js')) ?>" defer></script>
<script src="<?= e(asset('assets/cliente-picker.js')) ?>" defer></script>
<script src="<?= e(asset('assets/buscador.js')) ?>" defer></script>
<script src="<?= e(asset('assets/compras.js')) ?>" defer></script>
