<?php
/**
 * Filtros de artículo y de cliente de los listados: los buscadores de siempre (el usuario elige; la lupa abre la lista).
 * @var array<string,string> $f @var string $urlArticulo @var ?string $urlCliente (null = sin filtro de cliente)
 */
?>
<label class="field"><span>Artículo</span>
    <input name="articulo" value="<?= e($f['articulo'] ?? '') ?>" autocomplete="off" placeholder="Código, código de barras, SKU o descripción" data-buscador-url="<?= e($urlArticulo) ?>"></label>
<?php if ($urlCliente !== null): ?>
    <div class="field has-picker" data-picker data-url="<?= e($urlCliente) ?>">
        <label for="filtro-cliente"><span>Cliente</span></label>
        <div class="campo-con-boton">
            <input id="filtro-cliente" type="search" name="cliente_nombre" autocomplete="off" placeholder="Nombre, código o CUIT" value="<?= e($f['cliente_nombre'] ?? '') ?>" data-cliente-q>
            <?= icono_lista('data-lista-clientes', 'clientes') ?>
            <ul class="picker-lista" data-cliente-lista hidden></ul>
        </div>
        <input type="hidden" name="cliente" value="<?= e($f['cliente'] ?? '') ?>" data-cliente>
    </div>
<?php endif; ?>
