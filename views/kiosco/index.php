<?php
use App\Modules\Comprobantes\Tipos;

/** @var array<int,array{codigo:string,descripcion:string}> $rubros */
/** @var array{codigo:int,razon_social:string}|null $cliente */
/** @var array<int,array<string,mixed>> $sucursales */
/** @var ?array<string,mixed> $hecho */
/** @var bool $puedeVer */
/** @var bool $conTicket */
?>
<div class="kiosco" data-kiosco
     data-url-articulos="<?= e(url('/kiosco/articulos')) ?>" data-url-cliente="<?= e(url('/kiosco/buscar-cliente')) ?>"
     data-cliente-codigo="<?= e((string) ($cliente['codigo'] ?? '')) ?>" data-cliente-nombre="<?= e((string) ($cliente['razon_social'] ?? '')) ?>"
     data-hecho="<?= $hecho !== null ? '1' : '0' ?>" data-url-reservar="<?= e(url('/stock/reservar')) ?>" data-url-liberar="<?= e(url('/stock/liberar')) ?>"
     data-csrf-nombre="<?= e(\App\Core\Csrf::CAMPO) ?>" data-csrf="<?= e(\App\Core\Csrf::token()) ?>" data-stock="<?= \App\Modules\Stock\StockService::activo() ? '1' : '0' ?>" data-rubros="<?= e((string) json_encode($rubros, JSON_UNESCAPED_UNICODE)) ?>">
    <section class="kiosco-catalogo">
        <div class="page-head"><div><p class="eyebrow">Facturación</p><h1>Kiosco</h1></div></div>
        <?php if ($hecho !== null): ?>
            <div class="alerta alerta-ok kiosco-hecho" role="status">
                <b>Venta registrada:</b> <?= e(Tipos::titulo($hecho['tipo'], $hecho['letra']) . ' ' . numero_visible($hecho)) ?> por <b><?= e(plata_dec($hecho['neto'])) ?></b>.
                <?php foreach ($hecho['pagos'] as $p) { if ((float) $p['p_vuelto'] > 0) { echo ' <b>Dar vuelto: ' . e(plata_dec($p['p_vuelto'])) . '.</b>'; } } ?>
                <span class="kiosco-hecho-links">
                    <?php if ($puedeVer): ?>
                        <a href="<?= e(url('/comprobantes/' . (int) $hecho['fe_clave'] . '/pdf')) ?>" target="_blank" rel="noopener">PDF</a>
                        <a href="<?= e(url('/comprobantes/' . (int) $hecho['fe_clave'] . '/imprimir')) ?>" target="_blank" rel="noopener">Imprimir</a>
                        <a href="<?= e(url('/comprobantes/' . (int) $hecho['fe_clave'])) ?>">Ver</a>
                    <?php endif; ?>
                    <?php if ($conTicket): ?><a href="<?= e(url('/cambios/ticket/' . (int) $hecho['fe_clave'])) ?>" target="_blank" rel="noopener">Ticket de cambio</a><?php endif; ?>
                </span>
            </div>
        <?php endif; ?>
        <div class="kiosco-barra">
            <input type="search" class="kiosco-buscar" data-buscar autocomplete="off" autofocus placeholder="Buscar por nombre, código, SKU o leer el código de barras…" aria-label="Buscar producto">
            <div class="kiosco-rubros" data-rubros-chips role="group" aria-label="Rubros"></div>
        </div>
        <div class="kiosco-tarjetas" data-tarjetas aria-live="polite"></div>
        <p class="muted vacio" data-sin-resultados hidden>No hay productos para esa búsqueda.</p>
        <p class="kiosco-mas"><button type="button" class="btn btn-ghost" data-ver-mas hidden>Ver más productos</button></p>
    </section>

    <aside class="kiosco-carrito card" aria-label="Carrito">
        <h2>Carrito <span class="muted" data-cant></span></h2>
        <div class="kiosco-lineas" data-lineas></div>
        <p class="muted vacio" data-vacio>Tocá un producto para agregarlo.</p>
        <div class="kiosco-cliente small">
            Cliente: <b data-cliente-nombre><?= e((string) ($cliente['razon_social'] ?? 'Elegí el cliente')) ?></b>
            <button type="button" class="btn btn-ghost btn-sm" data-cambiar-cliente>Cambiar</button>
            <div data-cliente-caja hidden>
                <input type="search" data-cliente-q autocomplete="off" placeholder="Nombre, código o CUIT" aria-label="Buscar cliente">
                <ul class="picker-lista" data-cliente-lista hidden></ul>
            </div>
        </div>
        <?php if (count($sucursales) > 1): ?>
            <label class="field small"><span>Punto de venta</span>
                <select data-sucursal><?php foreach ($sucursales as $s): ?><option value="<?= e($s['codigo']) ?>"><?= e($s['descripcion'] . ' · PV ' . sprintf('%04d', $s['prefijo'])) ?></option><?php endforeach; ?></select></label>
        <?php elseif (count($sucursales) === 1): ?>
            <input type="hidden" data-sucursal value="<?= e((string) array_key_first($sucursales)) ?>">
        <?php endif; ?>
        <div class="alerta alerta-warn small" data-reserva-aviso hidden role="status">Hay productos reservados por otro puesto o sin stock suficiente: para venderlos igual, al cobrar hace falta la autorización de un supervisor.</div>
        <p class="kiosco-total">Total <b data-total>$ 0,00</b></p>
        <div class="acciones">
            <button type="button" class="btn kiosco-cobrar" data-cobrar disabled>Cobrar</button>
            <button type="button" class="btn btn-ghost" data-vaciar>Vaciar</button>
        </div>
        <p class="small muted">Al cobrar se arma un presupuesto y se elige cómo se paga.</p>
    </aside>

    <form method="post" action="<?= e(url('/kiosco/cobrar')) ?>" data-form-cobrar hidden><?= csrf_field() ?></form>
</div>
<script src="<?= e(asset('assets/stock-articulo.js')) ?>" defer></script>
<script src="<?= e(asset('assets/reservas.js')) ?>" defer></script>
<script src="<?= e(asset('assets/kiosco.js')) ?>" defer></script>
