<?php
use App\Modules\Clientes\ClientesService;
use App\Modules\Comprobantes\Tipos;

/** @var string $tipo */
/** @var array<string,mixed> $datos */
/** @var array<string,string> $errores */
/** @var array<string,mixed>|null $cliente */
/** @var array<int,array<string,mixed>> $asociables */
/** @var array<int,array<string,mixed>> $sucursales */
/** @var array<int,array<string,mixed>> $alicuotas */
/** @var array<string,mixed> $emisor */
/** @var string $letra */
/** @var float $deuda */
$err = static fn (string $k): string => isset($errores[$k]) ? '<small class="field-error">' . nl2br(e($errores[$k])) . '</small>' : '';
$v = static fn (string $k, string $d = ''): string => (string) ($datos[$k] ?? $d);
$nombre = $tipo === 'NX' ? 'Nota de crédito' : Tipos::NOMBRES[$tipo];
$interno = Tipos::interno($tipo);
$credito = Tipos::credito($tipo);
$items = array_values((array) ($datos['items'] ?? []));
while (count($items) < 3) {
    $items[] = [];
}
$alicDefecto = '';
foreach ($alicuotas as $a) {
    if ($alicDefecto === '' || abs((float) $a['porcentaje'] - 21) < 0.001) {
        $alicDefecto = (string) $a['codigo'];
    }
}
$formaPago = $v('forma_pago', '2');
$fpagoElegida = $v('fpago');
$sinSucursal = $sucursales === [];
$errorGeneral = array_intersect_key($errores, array_flip(['arca', 'licencia', 'tipo', 'emisor', 'sucursal', 'forma_pago', 'credito']));
$concepto = $v('concepto', (string) $conceptoDefecto);
$hoyIso = date('Y-m-d');
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><?= e($emisor['empresa'] ?: 'Empresa sin cargar') ?> · <?= e($etiquetaArca) ?></p>
        <h1><?= e($nombre) ?> <span data-letra-titulo><?= e($letra) ?></span></h1>
    </div>
</div>

<?php foreach ($errorGeneral as $mensaje): ?><div class="alerta alerta-error" role="alert"><?= nl2br(e($mensaje)) ?></div><?php endforeach; ?>
<?php if ($interno): ?>
    <div class="alerta alerta-info small"><?= $tipo === 'PR'
        ? 'El presupuesto <b>no se factura en ARCA</b> ni va al subdiario: es una venta interna (letra X). Descuenta stock y se cobra o va a cuenta corriente. Después se puede generar una factura, una sola vez.'
            . (!empty($sinIva) ? ' <b>«Presupuestos sin IVA» está activo:</b> no se calcula IVA, el precio de cada renglón es el final.' : '')
        : 'Contrapartida de un presupuesto (como una nota de crédito): repone el stock y deja saldo a favor del cliente. No es fiscal.' ?></div>
<?php elseif ($modoArca !== 'interno' && $electronicas === []): ?>
    <div class="alerta alerta-warn">ARCA está en modo <b><?= e($modoArca) ?></b> pero ninguna sucursal tiene tildada "Factura electrónica": los comprobantes salen internos. Se tilda en Tablas → Sucursales.</div>
<?php endif; ?>
<?php if ($sinSucursal): ?><div class="alerta alerta-error">No hay ninguna sucursal con punto de venta: cargala en Tablas → Sucursales.</div><?php endif; ?>

<form method="post" action="<?= e(url('/comprobantes/revisar')) ?>" class="card form" id="form-comprobante" novalidate
      data-condicion-emisor="<?= e($emisor['condicion'] ?? '') ?>" data-tipo="<?= e($tipo) ?>" data-letra="<?= e($letra) ?>" data-sin-iva="<?= !empty($sinIva) ? '1' : '0' ?>"
      data-url-cliente="<?= e(url('/comprobantes/buscar-cliente')) ?>" data-url-articulo="<?= e(url('/comprobantes/buscar-articulo') . '?reserva=' . $reserva) ?>"
      data-url-asociables="<?= e(url('/comprobantes/asociables')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
    <?php if (in_array($tipo, ['FC', 'PR'], true) && \App\Modules\Stock\StockService::activo()): ?>
        <input type="hidden" name="reserva" value="<?= e((string) $reserva) ?>" data-reserva data-url="<?= e(url('/stock/reservar')) ?>" data-csrf-nombre="<?= e(\App\Core\Csrf::CAMPO) ?>" data-csrf="<?= e(\App\Core\Csrf::token()) ?>">
    <?php endif; ?>

    <h2>Cliente</h2>
    <div class="field has-picker<?= isset($errores['cliente']) ? ' has-error' : '' ?>" data-picker>
        <label for="cliente-q"><span>Buscar cliente por nombre, código o CUIT *</span></label>
        <div class="campo-con-boton">
            <input id="cliente-q" type="search" autocomplete="off" placeholder="Escribí para buscar…" value="<?= e($cliente['razon_social'] ?? '') ?>" data-cliente-q>
            <?= icono_lista('data-lista-clientes', 'clientes') ?>
            <?= in_array($tipo, ['FC', 'PR'], true) ? icono_nuevo_cliente() : '' ?>
            <ul class="picker-lista" data-cliente-lista hidden></ul>
        </div>
        <input type="hidden" name="cliente" value="<?= e($cliente['codigo'] ?? '') ?>" data-cliente>
        <?= $err('cliente') ?>
        <div class="picker-info small muted" data-cliente-info>
            <?php if ($cliente !== null): ?>
                Código <?= e($cliente['codigo']) ?> · <?= e(ClientesService::CONDICIONES_IVA[(int) $cliente['iva']] ?? '') ?><?= $cliente['cuit'] ? ' · CUIT ' . e(cuit_texto($cliente['cuit'])) : '' ?>
                <?= (float) $cliente['credito'] > 0 ? ' · crédito ' . e(plata($cliente['credito'])) . ', deuda ' . e(plata($deuda)) : '' ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if (in_array($tipo, ['NC', 'ND', 'NX'], true)): ?>
        <label class="field<?= isset($errores['asociado']) ? ' has-error' : '' ?>" for="asociado"><span><?= $tipo === 'NX' ? 'Presupuesto que se corrige *' : 'Comprobante que se corrige *' ?></span>
            <select id="asociado" name="asociado" data-asociado>
                <option value=""><?= $tipo === 'NX' ? 'Elegí el presupuesto' : 'Elegí la factura o nota de débito' ?></option>
                <?php foreach ($asociables as $a): ?>
                    <option value="<?= e($a['fe_clave']) ?>" data-letra="<?= e($a['letra']) ?>" data-sin-iva="<?= (int) $a['sin_iva'] ?>" <?= $v('asociado') === (string) $a['fe_clave'] ? 'selected' : '' ?>>
                        <?= e(Tipos::titulo($a['tipo'], $a['letra']) . ' ' . numero_visible($a) . ' del ' . fecha($a['fecha']) . ' · ' . plata($a['neto'])) ?></option>
                <?php endforeach; ?>
            </select><?= $err('asociado') ?></label>
    <?php endif; ?>

    <div class="grid-2">
        <?php if (in_array($tipo, ['FC', 'PR'], true) && count($sucursales) > 1): ?>
            <label class="field" for="sucursal"><span>Sucursal (punto de venta)</span>
                <select id="sucursal" name="sucursal">
                    <?php foreach ($sucursales as $s): ?>
                        <option value="<?= e($s['codigo']) ?>" <?= $v('sucursal') === (string) $s['codigo'] ? 'selected' : '' ?>><?= e($s['descripcion'] . ' · PV ' . sprintf('%04d', $s['prefijo'])) ?></option>
                    <?php endforeach; ?>
                </select></label>
        <?php elseif (count($sucursales) === 1): ?>
            <input type="hidden" name="sucursal" value="<?= e(array_key_first($sucursales)) ?>">
        <?php endif; ?>
        <?php if ($credito): ?>
        <fieldset class="field" data-forma-pago>
            <legend><span>Forma de pago *</span></legend>
            <label class="check"><input type="radio" name="forma_pago" value="2" <?= $formaPago === '2' ? 'checked' : '' ?>> Cuenta corriente</label>
            <label class="check"><input type="radio" name="forma_pago" value="1" <?= $formaPago === '1' ? 'checked' : '' ?>> Contado</label>
        </fieldset>
        <?php endif; ?>
    </div>

    <h2>Renglones</h2>
    <div class="alerta alerta-warn small" role="status" data-reserva-aviso hidden>Hay productos reservados por otro puesto o sin stock suficiente: para venderlos igual, al emitir hace falta la autorización de un supervisor.</div>
    <?php if (isset($errores['items'])): ?><div class="alerta alerta-error" role="alert"><?= nl2br(e($errores['items'])) ?></div><?php endif; ?>
    <p class="small muted" data-leyenda-precio></p>
    <div class="table-wrap">
        <table class="table table-cards renglones" data-renglones data-grilla="no">
            <thead><tr><th>Artículo</th><th>Descripción</th><th>Cantidad</th><th data-th-precio>Precio</th><th>IVA</th><th>Importe</th><th></th></tr></thead>
            <tbody data-renglones-cuerpo>
            <?php foreach ($items as $i => $it): ?>
                <tr data-renglon>
                    <td data-label="Artículo"><div class="campo-con-boton"><input name="items[<?= $i ?>][articulo]" value="<?= e($it['articulo'] ?? '') ?>" maxlength="60" placeholder="Código, barras, SKU o descripción" data-articulo autocomplete="off" role="combobox" aria-autocomplete="list"><?= icono_lista('data-lista-articulos', 'artículos') ?></div></td>
                    <td data-label="Descripción"><input name="items[<?= $i ?>][descripcion]" value="<?= e($it['descripcion'] ?? '') ?>" maxlength="255" data-descripcion>
                        <textarea name="items[<?= $i ?>][texto]" rows="1" maxlength="2000" placeholder="Texto adicional que se imprime en la factura (opcional)" data-texto><?= e($it['texto'] ?? '') ?></textarea>
                        <small data-reserva-estado hidden></small></td>
                    <td data-label="Cantidad"><input name="items[<?= $i ?>][cantidad]" value="<?= e($it['cantidad'] ?? '') ?>" inputmode="decimal" data-decimales="3" data-cantidad></td>
                    <td data-label="Precio"><input name="items[<?= $i ?>][precio]" value="<?= e($it['precio'] ?? '') ?>" inputmode="decimal" data-decimales="4" data-precio></td>
                    <td data-label="IVA"><select name="items[<?= $i ?>][alicuota]" data-alicuota>
                        <?php $sel = (string) ($it['alicuota'] ?? $alicDefecto); foreach ($alicuotas as $a): ?>
                            <option value="<?= e($a['codigo']) ?>" data-pct="<?= e((float) $a['porcentaje']) ?>" <?= $sel === (string) $a['codigo'] ? 'selected' : '' ?>><?= e(numero($a['porcentaje']) . ' %') ?></option>
                        <?php endforeach; ?></select></td>
                    <td data-label="Importe" class="nw" data-importe>—</td>
                    <td><button type="button" class="btn btn-ghost btn-sm" data-quitar aria-label="Quitar renglón" title="Quitar renglón">✕</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <ul class="sugerencias" id="articulos-sugerencias" role="listbox" hidden></ul>
    <p><button type="button" class="btn btn-ghost btn-sm" data-agregar>+ Agregar renglón</button></p>

    <div class="totales card-interno" data-totales aria-live="polite">
        <div><span>Subtotal</span><b data-t-subtotal>—</b></div>
        <div data-t-desc-fila hidden><span>Descuento</span><b data-t-desc>—</b></div>
        <div data-t-iva-filas></div>
        <div class="total-final"><span>Total</span><b data-t-total>—</b></div>
    </div>

    <h2>Otros datos</h2>
    <?php if (!$interno): ?>
    <div class="grid-2">
        <label class="field<?= isset($errores['concepto']) ? ' has-error' : '' ?>"><span>Concepto</span>
            <select name="concepto" data-concepto>
                <?php foreach ([1 => 'Productos', 2 => 'Servicios', 3 => 'Productos y servicios'] as $k => $t): ?>
                    <option value="<?= $k ?>" <?= $concepto === (string) $k ? 'selected' : '' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
            </select><?= $err('concepto') ?></label>
    </div>
    <div class="grid-2" data-servicios <?= (int) $concepto > 1 ? '' : 'hidden' ?>>
        <label class="field<?= isset($errores['serv_desde']) ? ' has-error' : '' ?>"><span>Servicio desde</span>
            <input type="date" name="serv_desde" value="<?= e($v('serv_desde', $hoyIso)) ?>"><?= $err('serv_desde') ?></label>
        <label class="field<?= isset($errores['serv_hasta']) ? ' has-error' : '' ?>"><span>Servicio hasta</span>
            <input type="date" name="serv_hasta" value="<?= e($v('serv_hasta', $hoyIso)) ?>"><?= $err('serv_hasta') ?></label>
        <label class="field<?= isset($errores['vto_pago']) ? ' has-error' : '' ?>"><span>Vencimiento del pago</span>
            <input type="date" name="vto_pago" value="<?= e($v('vto_pago', $hoyIso)) ?>"><?= $err('vto_pago') ?></label>
    </div>
    <?php endif; ?>
    <div class="grid-2">
        <label class="field<?= isset($errores['descuento']) ? ' has-error' : '' ?>"><span>Descuento general (%)</span>
            <input name="descuento" value="<?= e($v('descuento')) ?>" inputmode="decimal" data-decimales="2" data-descuento><?= $err('descuento') ?></label>
        <label class="field"><span>Vendedor</span>
            <select name="vendedor" data-vendedor><option value="">—</option>
                <?php foreach ($vendedores as $x): ?><option value="<?= e($x['codigo']) ?>" <?= $v('vendedor') === (string) $x['codigo'] ? 'selected' : '' ?>><?= e($x['codigo'] . ' - ' . $x['descripcion']) ?></option><?php endforeach; ?>
            </select></label>
        <label class="field"><span>Condición de pago</span>
            <select name="cond_pago" data-cond-pago><option value="">—</option>
                <?php foreach ($condiciones as $x): ?><option value="<?= e($x['codigo']) ?>" <?= $v('cond_pago') === (string) $x['codigo'] ? 'selected' : '' ?>><?= e($x['descripcion']) ?></option><?php endforeach; ?>
            </select></label>
        <label class="field<?= isset($errores['orden_compra']) ? ' has-error' : '' ?>"><span>Orden de compra</span>
            <input name="orden_compra" value="<?= e($v('orden_compra')) ?>" maxlength="20"><?= $err('orden_compra') ?></label>
        <label class="field<?= isset($errores['email']) ? ' has-error' : '' ?>"><span>Mails del cliente para enviarlo</span>
            <input name="email" value="<?= e($v('email')) ?>" maxlength="255" placeholder="uno@mail.com; otro@mail.com" data-email><?= $err('email') ?></label>
    </div>
    <label class="field<?= isset($errores['observaciones']) ? ' has-error' : '' ?>"><span>Observaciones</span>
        <input name="observaciones" value="<?= e($v('observaciones')) ?>" maxlength="255"><?= $err('observaciones') ?></label>

    <?php if (isset($errores['credito']) && $puedeCredito): ?>
        <label class="check"><input type="checkbox" name="excede_ok" value="1" <?= $v('excede_ok') === '1' ? 'checked' : '' ?>> <b>Autorizo exceder el crédito del cliente</b></label>
    <?php endif; ?>

    <div class="actions"><button class="btn" type="submit" <?= $sinSucursal ? 'disabled' : '' ?>>Revisar</button>
        <a class="btn btn-ghost" href="<?= e(url('/comprobantes')) ?>">Cancelar</a></div>
</form>

<template id="renglon-plantilla">
    <tr data-renglon>
        <td data-label="Artículo"><div class="campo-con-boton"><input name="items[__I__][articulo]" maxlength="60" placeholder="Código, barras, SKU o descripción" data-articulo autocomplete="off" role="combobox" aria-autocomplete="list"><?= icono_lista('data-lista-articulos', 'artículos') ?></div></td>
        <td data-label="Descripción"><input name="items[__I__][descripcion]" maxlength="255" data-descripcion>
            <textarea name="items[__I__][texto]" rows="1" maxlength="2000" placeholder="Texto adicional que se imprime en la factura (opcional)" data-texto></textarea>
            <small data-reserva-estado hidden></small></td>
        <td data-label="Cantidad"><input name="items[__I__][cantidad]" inputmode="decimal" data-decimales="3" data-cantidad></td>
        <td data-label="Precio"><input name="items[__I__][precio]" inputmode="decimal" data-decimales="4" data-precio></td>
        <td data-label="IVA"><select name="items[__I__][alicuota]" data-alicuota>
            <?php foreach ($alicuotas as $a): ?><option value="<?= e($a['codigo']) ?>" data-pct="<?= e((float) $a['porcentaje']) ?>" <?= (string) $a['codigo'] === $alicDefecto ? 'selected' : '' ?>><?= e(numero($a['porcentaje']) . ' %') ?></option><?php endforeach; ?></select></td>
        <td data-label="Importe" class="nw" data-importe>—</td>
        <td><button type="button" class="btn btn-ghost btn-sm" data-quitar aria-label="Quitar renglón" title="Quitar renglón">✕</button></td>
    </tr>
</template>
<script src="<?= e(asset('assets/stock-articulo.js')) ?>" defer></script>
<script src="<?= e(asset('assets/ventana-lista.js')) ?>" defer></script>
<script src="<?= e(asset('assets/reservas.js')) ?>" defer></script>
<?php if (in_array($tipo, ['FC', 'PR'], true)) { require __DIR__ . '/../clientes/_alta_rapida.php'; } ?>
<script src="<?= e(asset('assets/comprobante.js')) ?>" defer></script>
