<?php
/*
 * Ventana «Nuevo cliente» (alta desde el CUIT con el padrón de ARCA). Se incluye en las pantallas de venta y en la ficha del cliente:
 *   <?php require __DIR__ . '/../clientes/_alta_rapida.php'; ?>
 * Deja la configuración en un elemento [data-alta-cliente] y carga public/assets/alta-cliente.js (window.AltaCliente).
 * Solo aparece si el usuario puede dar de alta clientes (clientes.alta o clientes.editar).
 */
use App\Core\Validador;
use App\Modules\Arca\Padron;
use App\Modules\Clientes\ClientesService;

if (!puede('clientes.alta') && !puede('clientes.editar')) {
    return;
}
$altaProvincias = [];
foreach (Validador::opciones('provincias') as $cod => $desc) {
    $altaProvincias[] = [(string) $cod, (string) $desc];
}
$altaCondiciones = [];
foreach (ClientesService::CONDICIONES_IVA as $cod => $desc) {
    if ($cod !== 2) { // «No inscripto» ya no existe en ARCA
        $altaCondiciones[] = [(string) $cod, $desc];
    }
}
?>
<div hidden data-alta-cliente
     data-url-padron="<?= e(url('/clientes/padron')) ?>" data-url-crear="<?= e(url('/clientes/rapido')) ?>"
     data-csrf-nombre="<?= e(\App\Core\Csrf::CAMPO) ?>" data-csrf="<?= e(\App\Core\Csrf::token()) ?>"
     data-arca="<?= Padron::disponible() ? '1' : '0' ?>" data-arca-motivo="<?= e((string) Padron::motivoNoDisponible()) ?>"
     data-provincias="<?= e((string) json_encode($altaProvincias, JSON_UNESCAPED_UNICODE)) ?>"
     data-condiciones="<?= e((string) json_encode($altaCondiciones, JSON_UNESCAPED_UNICODE)) ?>"></div>
<script src="<?= e(asset('assets/alta-cliente.js')) ?>" defer></script>
