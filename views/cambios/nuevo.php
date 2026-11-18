<?php
use App\Modules\Comprobantes\Tipos;

/** @var array{h:array<string,mixed>,items:array<int,array<string,mixed>>,cliente:array<string,mixed>} $o */
/** @var array<string,string> $errores */
/** @var array<string,mixed> $datos */
/** @var array<string,mixed> $pagoCargado */
/** @var string $operacion */
$h = $o['h'];
$err = static fn (string $k): string => isset($errores[$k]) ? '<div class="alerta alerta-error" role="alert">' . nl2br(e($errores[$k])) . '</div>' : '';
$dev = (array) ($datos['devolver'] ?? []);
$nuevos = array_values((array) ($datos['nuevos'] ?? []));
$filasNuevas = 10;
$fmt = static fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',');
?>
<p><a href="<?= e(url('/cambios')) ?>">← Leer otro ticket</a></p>
<div class="page-head"><div>
    <p class="eyebrow">Cambio de mercadería</p>
    <h1><?= e(Tipos::titulo($h['tipo'], $h['letra'])) ?> <?= e(numero_visible($h)) ?></h1>
</div></div>
<p class="muted"><?= e(fecha($h['fecha'])) ?> · <?= e((string) ($h['cliente_razon_social'] ?? '')) ?> · total <?= e(plata_dec($h['neto'])) ?>.
    Se devuelve lo que trae el cliente al precio que pagó, se elige lo que se lleva y <b>solo se cobra la diferencia</b>.</p>

<?= $err('original') ?>
<form method="post" action="<?= e(url('/cambios')) ?>" class="stack" id="form-cambio" data-cambio data-url-articulo="<?= e(url('/cambios/buscar-articulo')) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="original" value="<?= (int) $h['fe_clave'] ?>">
    <input type="hidden" name="operacion" value="<?= e($operacion) ?>">

    <section class="card">
        <h2>Lo que devuelve el cliente</h2>
        <?= $err('devolver') ?>
        <div class="table-wrap"><table class="table table-cards">
            <thead><tr><th>Producto</th><th class="num">Compró</th><th class="num">Ya devuelto</th><th class="num">Puede devolver</th><th class="num">Valor c/u</th><th class="num">Devuelve</th></tr></thead>
            <tbody>
            <?php foreach ($o['items'] as $it): ?>
                <tr><td data-label="Producto"><?= e($it['descripcion']) ?><?= $it['articulo'] !== '' ? ' <small class="muted">' . e($it['articulo']) . '</small>' : '' ?></td>
                    <td data-label="Compró" class="num"><?= e($fmt($it['unidades'])) ?></td>
                    <td data-label="Ya devuelto" class="num"><?= e($fmt($it['devuelto'])) ?></td>
                    <td data-label="Puede devolver" class="num"><?= e($fmt($it['disponible'])) ?></td>
                    <td data-label="Valor c/u" class="num nw"><?= e(plata_dec($it['unitario'])) ?></td>
                    <td data-label="Devuelve" class="num">
                        <?php if ($it['devolvible'] && $it['disponible'] > 0): ?>
                            <input name="devolver[<?= (int) $it['id'] ?>]" value="<?= e((string) ($dev[$it['id']] ?? '')) ?>" inputmode="decimal" data-decimales="3" data-devolver data-unitario="<?= e((string) $it['unitario']) ?>" data-max="<?= e((string) $it['disponible']) ?>" aria-label="Cantidad que devuelve">
                            <button type="button" class="btn btn-ghost btn-sm" data-todo title="Devolver todo lo que se puede">Todo</button>
                        <?php else: ?><span class="muted small"><?= $it['devolvible'] ? 'Ya devuelto' : 'No vuelve al stock' ?></span><?php endif; ?>
                    </td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

    <section class="card">
        <h2>Lo que se lleva</h2>
        <?= $err('nuevos') ?>
        <p class="small muted">Buscá el producto (código, código de barras, SKU o nombre) y elegilo de la lista. El precio es el de lista con IVA incluido; se puede corregir.</p>
        <div class="table-wrap"><table class="table table-cards" data-grilla="no">
            <thead><tr><th>Artículo</th><th>Cantidad</th><th>Precio con IVA</th><th>Importe</th></tr></thead>
            <tbody data-nuevos>
            <?php for ($i = 0; $i < $filasNuevas; $i++): $n = $nuevos[$i] ?? []; $visible = $i < 3 || ($n['articulo'] ?? '') !== ''; ?>
                <tr data-nuevo <?= $visible ? '' : 'hidden' ?>>
                    <td data-label="Artículo"><input name="nuevos[<?= $i ?>][articulo]" value="<?= e((string) ($n['articulo'] ?? '')) ?>" maxlength="60" placeholder="Código, barras, SKU o descripción" data-buscador-url="<?= e(url('/cambios/buscar-articulo')) ?>" autocomplete="off" data-art></td>
                    <td data-label="Cantidad"><input name="nuevos[<?= $i ?>][cantidad]" value="<?= e((string) ($n['cantidad'] ?? '')) ?>" inputmode="decimal" data-decimales="3" data-cant placeholder="1"></td>
                    <td data-label="Precio con IVA"><input name="nuevos[<?= $i ?>][precio]" value="<?= e((string) ($n['precio'] ?? '')) ?>" inputmode="decimal" data-decimales="4" data-precio></td>
                    <td data-label="Importe" class="nw" data-importe>—</td>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table></div>
        <p><button type="button" class="btn btn-ghost btn-sm" data-mas>+ Agregar otro producto</button></p>
    </section>

    <section class="card imputador-resumen" aria-live="polite">
        <div><span class="muted small">Devuelve</span><b data-t-devuelve>$ 0,00</b></div>
        <div><span class="muted small">Se lleva</span><b data-t-lleva>$ 0,00</b></div>
        <div><span class="muted small">Diferencia a cobrar</span><b data-t-dif>$ 0,00</b></div>
    </section>
    <?= $err('observaciones') ?>
    <label class="field"><span>Observaciones</span><input name="observaciones" value="<?= e((string) ($datos['observaciones'] ?? '')) ?>" maxlength="200"></label>

    <div data-bloque-pago hidden>
        <?php
        $totalPago = 0.0;
        $formaDefecto = '1';
        $formId = 'form-cambio';
        $cli = $o['cliente'];
        require __DIR__ . '/../comprobantes/_pago.php';
        ?>
    </div>
    <div class="acciones"><button class="btn" type="submit" data-hacer>Hacer el cambio</button><a class="btn btn-ghost" href="<?= e(url('/cambios')) ?>">Cancelar</a></div>
</form>
<script src="<?= e(asset('assets/stock-articulo.js')) ?>" defer></script>
<script src="<?= e(asset('assets/ventana-lista.js')) ?>" defer></script>
<script src="<?= e(asset('assets/buscador.js')) ?>" defer></script>
<script src="<?= e(asset('assets/cambio.js')) ?>" defer></script>
