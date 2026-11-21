<?php
declare(strict_types=1);

namespace App\Core;

use App\Modules\Auth\Auth;
use App\Modules\Tablas\Definiciones;

/**
 * Menú del sistema, agrupado como el del VFP (Maestros, Facturación, Tablas) más Sistema. Cada opción se muestra solo si el rol
 * tiene su permiso; las rutas lo vuelven a verificar en el servidor.
 */
final class Menu
{
    /** @return array<string,array{texto:string,ruta:string,permiso:?string,grupo:string}> */
    public static function opciones(): array
    {
        $o = [
            'clientes' => ['texto' => 'Clientes', 'ruta' => '/clientes', 'permiso' => 'clientes.ver', 'grupo' => 'Maestros'],
            'articulos' => ['texto' => 'Artículos', 'ruta' => '/articulos', 'permiso' => 'articulos.ver', 'grupo' => 'Maestros'],
        ];
        $o['comprobantes_fc'] = ['texto' => 'Nueva factura', 'ruta' => '/comprobantes/nuevo?tipo=FC', 'permiso' => 'comprobantes.emitir', 'grupo' => 'Facturación'];
        $o['comprobantes_pr'] = ['texto' => 'Nuevo presupuesto', 'ruta' => '/comprobantes/nuevo?tipo=PR', 'permiso' => 'comprobantes.emitir', 'grupo' => 'Facturación'];
        $o['kiosco'] = ['texto' => 'Kiosco', 'ruta' => '/kiosco', 'permiso' => 'kiosco.usar', 'grupo' => 'Facturación'];
        $o['cambios'] = ['texto' => 'Cambio de mercadería', 'ruta' => '/cambios', 'permiso' => 'cambios.hacer', 'grupo' => 'Facturación'];
        $o['comprobantes_nc'] = ['texto' => 'Nueva nota de crédito', 'ruta' => '/comprobantes/nuevo?tipo=NC', 'permiso' => 'comprobantes.emitir', 'grupo' => 'Facturación'];
        $o['comprobantes_nd'] = ['texto' => 'Nueva nota de débito', 'ruta' => '/comprobantes/nuevo?tipo=ND', 'permiso' => 'comprobantes.emitir', 'grupo' => 'Facturación'];
        $o['comprobantes'] = ['texto' => 'Comprobantes', 'ruta' => '/comprobantes', 'permiso' => 'comprobantes.ver', 'grupo' => 'Facturación'];
        $o['ctacte'] = ['texto' => 'Cuenta corriente', 'ruta' => '/cuenta-corriente', 'permiso' => 'ctacte.ver', 'grupo' => 'Cuenta corriente'];
        $o['recibos_nuevo'] = ['texto' => 'Nuevo recibo', 'ruta' => '/recibos/nuevo', 'permiso' => 'ctacte.cobrar', 'grupo' => 'Cuenta corriente'];
        $o['recibos'] = ['texto' => 'Recibos', 'ruta' => '/recibos', 'permiso' => 'ctacte.ver', 'grupo' => 'Cuenta corriente'];
        $o['imputar'] = ['texto' => 'Imputación manual', 'ruta' => '/cuenta-corriente/imputar', 'permiso' => 'ctacte.imputar', 'grupo' => 'Cuenta corriente'];
        $o['ctacte_ajuste'] = ['texto' => 'Ajuste de cuenta corriente', 'ruta' => '/cuenta-corriente/ajuste', 'permiso' => 'ctacte.ajustar', 'grupo' => 'Cuenta corriente'];
        $o['cheques'] = ['texto' => 'Cheques en cartera', 'ruta' => '/cheques', 'permiso' => 'ctacte.ver', 'grupo' => 'Cuenta corriente'];
        $o['mp'] = ['texto' => 'Cobros con Mercado Pago', 'ruta' => '/mercadopago', 'permiso' => 'mercadopago.cobrar', 'grupo' => 'Facturación'];
        $o['caja'] = ['texto' => 'Mi caja (turno y movimientos)', 'ruta' => '/caja', 'permiso' => 'caja.turno', 'grupo' => 'Caja'];
        $o['caja_turnos'] = ['texto' => 'Turnos y arqueos', 'ruta' => '/caja/turnos', 'permiso' => 'caja.turno', 'grupo' => 'Caja'];
        $o['caja_auditoria'] = ['texto' => 'Auditoría de caja', 'ruta' => '/caja/auditoria', 'permiso' => 'caja.auditar', 'grupo' => 'Caja'];
        $o['caja_diaria'] = ['texto' => 'Caja diaria', 'ruta' => '/listados/caja-diaria', 'permiso' => 'listados.ver', 'grupo' => 'Listados'];
        $o['ventas_diarias'] = ['texto' => 'Ventas diarias', 'ruta' => '/listados/ventas-diarias', 'permiso' => 'listados.ver', 'grupo' => 'Listados'];
        $o['iva_ventas'] = ['texto' => 'Subdiario IVA ventas', 'ruta' => '/listados/iva-ventas', 'permiso' => 'listados.ver', 'grupo' => 'Listados'];
        $o['libro_iva'] = ['texto' => 'Libro IVA Digital (ventas)', 'ruta' => '/listados/libro-iva-digital', 'permiso' => 'listados.ver', 'grupo' => 'Listados'];
        $o['estadistica'] = ['texto' => 'Estadística de ventas', 'ruta' => '/listados/estadistica', 'permiso' => 'listados.ver', 'grupo' => 'Listados'];
        $o['rentabilidad'] = ['texto' => 'Rentabilidad de ventas', 'ruta' => '/listados/rentabilidad', 'permiso' => 'listados.costos', 'grupo' => 'Listados'];
        $o['lista_costos'] = ['texto' => 'Costos y márgenes', 'ruta' => '/listados/costos', 'permiso' => 'listados.costos', 'grupo' => 'Listados'];
        $o['compras'] = ['texto' => 'Tablero de compras', 'ruta' => '/compras', 'permiso' => 'compras.ver', 'grupo' => 'Compras'];
        $o['compras_nueva'] = ['texto' => 'Nueva compra o gasto', 'ruta' => '/compras/nueva', 'permiso' => 'compras.cargar', 'grupo' => 'Compras'];
        $o['compras_lista'] = ['texto' => 'Compras cargadas', 'ruta' => '/compras/lista', 'permiso' => 'compras.ver', 'grupo' => 'Compras'];
        $o['compras_apagar'] = ['texto' => 'A pagar y vencimientos', 'ruta' => '/compras/a-pagar', 'permiso' => 'compras.ver', 'grupo' => 'Compras'];
        $o['compras_analisis'] = ['texto' => 'Análisis de gastos', 'ruta' => '/compras/analisis', 'permiso' => 'compras.ver', 'grupo' => 'Compras'];
        $o['compras_presupuesto'] = ['texto' => 'Presupuesto contra real', 'ruta' => '/compras/presupuesto', 'permiso' => 'compras.ver', 'grupo' => 'Compras'];
        $o['compras_iva'] = ['texto' => 'Subdiario IVA compras', 'ruta' => '/compras/iva', 'permiso' => 'compras.ver', 'grupo' => 'Compras'];
        $o['proveedores'] = ['texto' => 'Proveedores', 'ruta' => '/compras/proveedores', 'permiso' => 'compras.ver', 'grupo' => 'Compras'];
        $o['compras_centros'] = ['texto' => 'Centros de costos y conceptos', 'ruta' => '/compras/centros', 'permiso' => 'compras.centros', 'grupo' => 'Compras'];
        foreach (Definiciones::todas() as $t => $d) {
            $o[$t] = ['texto' => $d['titulo'], 'ruta' => '/tablas/' . $t, 'permiso' => 'tablas.' . $t, 'grupo' => 'Tablas'];
        }
        $o['stock'] = ['texto' => 'Saldos de stock', 'ruta' => '/stock', 'permiso' => 'stock.ver', 'grupo' => 'Stock'];
        $o['stock_movimientos'] = ['texto' => 'Movimientos de stock', 'ruta' => '/stock/movimientos', 'permiso' => 'stock.ver', 'grupo' => 'Stock'];
        $o['stock_existencias'] = ['texto' => 'Existencias por depósito', 'ruta' => '/stock/existencias', 'permiso' => 'stock.ver', 'grupo' => 'Stock'];
        $o['stock_ventas_sin_stock'] = ['texto' => 'Ventas sin stock', 'ruta' => '/stock/ventas-sin-stock', 'permiso' => 'stock.ver', 'grupo' => 'Stock'];
        $o['stock_ajuste'] = ['texto' => 'Ingreso, egreso o ajuste', 'ruta' => '/stock/ajuste', 'permiso' => 'stock.mover', 'grupo' => 'Stock'];
        $o['stock_transferir'] = ['texto' => 'Transferencia entre depósitos', 'ruta' => '/stock/transferir', 'permiso' => 'stock.mover', 'grupo' => 'Stock'];
        $o['stock_depositos'] = ['texto' => 'Depósitos', 'ruta' => '/stock/depositos', 'permiso' => 'stock.administrar', 'grupo' => 'Stock'];
        $o['usuarios'] = ['texto' => 'Usuarios', 'ruta' => '/usuarios', 'permiso' => 'usuarios.administrar', 'grupo' => 'Sistema'];
        $o['roles'] = ['texto' => 'Roles', 'ruta' => '/roles', 'permiso' => 'usuarios.administrar', 'grupo' => 'Sistema'];
        $o['configuracion'] = ['texto' => 'Configuración', 'ruta' => '/configuracion', 'permiso' => 'configuracion.administrar', 'grupo' => 'Sistema'];
        $o['importar'] = ['texto' => 'Importar datos (Excel, CSV, TXT)', 'ruta' => '/importar', 'permiso' => 'importar.datos', 'grupo' => 'Sistema'];
        $o['agente'] = ['texto' => 'Agente de sincronización', 'ruta' => '/agente', 'permiso' => 'agente.administrar', 'grupo' => 'Sistema'];
        $o['arca'] = ['texto' => 'ARCA', 'ruta' => '/arca', 'permiso' => 'arca.administrar', 'grupo' => 'Sistema'];
        $o['sistema'] = ['texto' => 'Sistema', 'ruta' => '/sistema', 'permiso' => null, 'grupo' => 'Sistema'];
        $o['instancias'] = ['texto' => 'Instalaciones de clientes', 'ruta' => '/instancias', 'permiso' => null, 'grupo' => 'Sistema'];
        return $o;
    }

    public static function texto(string $clave): string
    {
        return self::opciones()[$clave]['texto'] ?? $clave;
    }

    public static function visible(string $clave): bool
    {
        $o = self::opciones()[$clave] ?? null;
        if ($o === null) {
            return false;
        }
        if ($clave === 'kiosco' && !\App\Modules\Kiosco\KioscoService::habilitado()) {
            return false; // el modo Kiosco se enciende en Parámetros
        }
        if ($clave === 'mp' && !\App\Modules\MercadoPago\MercadoPago::activo()) {
            return false; // Mercado Pago se enciende en config/local.php
        }
        if ($clave === 'cambios' && !\App\Modules\Cambios\CambiosService::habilitado()) {
            return false; // el modo ticket de cambio se enciende en Parámetros
        }
        $modulo = Modulos::deMenu($clave);
        if ($modulo !== null && !Modulos::activo($modulo)) {
            return false; // módulo apagado para este cliente desde el sitio master
        }
        if ($clave === 'instancias') {
            return Auth::esMaster() && \App\Modules\Instancias\InstanciasService::disponible(); // solo en la principal
        }
        if ($clave === 'sistema') {
            return Auth::esMaster() || Auth::esAdmin();
        }
        return $o['permiso'] !== null && Auth::puede($o['permiso']);
    }

    /** Grupos con las opciones que ve el usuario. @return array<string,array<string,array<string,mixed>>> */
    public static function grupos(): array
    {
        $out = [];
        foreach (self::opciones() as $k => $o) {
            if (self::visible($k)) {
                $out[$o['grupo']][$k] = $o;
            }
        }
        return $out;
    }
}
