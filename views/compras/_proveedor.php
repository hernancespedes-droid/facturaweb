<?php
/**
 * Buscador de proveedor (con lupa): el mismo comportamiento que el de clientes; el usuario siempre elige.
 * @var ?array $prov proveedor elegido @var string $nombre nombre del campo oculto (id del proveedor) @var string $titulo
 */
$nombre = $nombre ?? 'proveedor';
$titulo = $titulo ?? 'Proveedor';
$pid = 'prov-' . $nombre;
?>
<div class="field has-picker" data-picker data-preset="proveedores" data-url="<?= e(url('/compras/buscar-proveedor')) ?>">
    <label for="<?= e($pid) ?>"><span><?= e($titulo) ?></span></label>
    <div class="campo-con-boton">
        <input id="<?= e($pid) ?>" type="search" autocomplete="off" placeholder="Nombre, código o CUIT" value="<?= e($prov['razon_social'] ?? '') ?>" data-cliente-q<?= !empty($autofocus) ? ' autofocus' : '' ?>>
        <?= icono_lista('data-lista-clientes', 'proveedores') ?>
        <ul class="picker-lista" data-cliente-lista hidden></ul>
    </div>
    <input type="hidden" name="<?= e($nombre) ?>" value="<?= e((string) ($prov['id'] ?? '')) ?>" data-cliente>
</div>
