<?php
/** Buscador de clientes (con lupa). @var string $accion a dónde va el GET @var string $boton texto del botón @var ?array $cli cliente ya elegido @var string $extra HTML de campos ocultos */
$extra = $extra ?? '';
?>
<form method="get" action="<?= e(url($accion)) ?>" class="card form-grid" data-enviar-al-elegir>
    <div class="field has-picker" data-picker data-url="<?= e(url('/cuenta-corriente/buscar-cliente')) ?>">
        <label for="cliente-q"><span>Cliente (nombre, código o CUIT)</span></label>
        <div class="campo-con-boton">
            <input id="cliente-q" type="search" autocomplete="off" placeholder="Escribí para buscar…" value="<?= e($cli['razon_social'] ?? '') ?>" data-cliente-q autofocus>
            <?= icono_lista('data-lista-clientes', 'clientes') ?>
            <ul class="picker-lista" data-cliente-lista hidden></ul>
        </div>
        <input type="hidden" name="cliente" value="<?= e($cli['codigo'] ?? '') ?>" data-cliente>
    </div>
    <?= $extra ?>
    <div class="acciones"><button class="btn" type="submit"><?= e($boton) ?></button></div>
</form>
<script src="<?= e(asset('assets/ventana-lista.js')) ?>" defer></script>
<script src="<?= e(asset('assets/cliente-picker.js')) ?>" defer></script>
