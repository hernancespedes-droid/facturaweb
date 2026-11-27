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
            'articulos_precios' => ['texto' => 'Precios por margen', 'ruta' => '/articulos/precios', 'permiso' => 'articulos.precios', 'grupo' => 'Maestros'],
            'articulos_actualizacion' => ['texto' => 'Actualización de precios', 'ruta' => '/articulos/actualizacion', 'permiso' => 'articulos.precios', 'grupo' => 'Maestros'],
        ];
        $o['comprobantes_fc'] = ['texto' => 'Nueva factura', 'ruta' => '/comprobantes/nuevo?tipo=FC', 'permiso' => 'comprobantes.emitir', 'grupo' => 'Facturación'];
        $o['comprobantes_pr'] = ['texto' => 'Nuevo presupuesto', 'ruta' => '/comprobantes/nuevo?tipo=PR', 'permiso' => 'comprobantes.emitir', 'grupo' => 'Facturación'];
        $o['kiosco'] = ['texto' => 'Kiosco', 'ruta' => '/kiosco', 'permiso' => 'kiosco.usar', 'grupo' => 'Facturación'];
        $o['cambios'] = ['texto' => 'Cambio de mercadería', 'ruta' => '/cambios', 'permiso' => 'cambios.hacer', 'grupo' => 'Facturación'];
        $o['comprobantes_nc'] = ['texto' => 'Nueva nota de crédito', 'ruta' => '/comprobantes/nuevo?tipo=NC', 'permiso' => 'comprobantes.emitir', 'grupo' => 'Facturación'];
        $o['comprobantes_nd'] = ['texto' => 'Nueva nota de débito', 'ruta' => '/comprobantes/nuevo?tipo=ND', 'permiso' => 'comprobantes.emitir', 'grupo' => 'Facturación'];
        $o['masiva'] = ['texto' => 'Facturación masiva', 'ruta' => '/facturacion-masiva', 'permiso' => 'comprobantes.masiva', 'grupo' => 'Facturación'];
        $o['comprobantes'] = ['texto' => 'Comprobantes', 'ruta' => '/comprobantes', 'permiso' => 'comprobantes.ver', 'grupo' => 'Facturación'];
        $o['ctacte'] = ['texto' => 'Cuenta corriente', 'ruta' => '/cuenta-corriente', 'permiso' => 'ctacte.ver', 'grupo' => 'Cuenta corriente'];
        $o['recibos_nuevo'] = ['texto' => 'Nuevo recibo', 'ruta' => '/recibos/nuevo', 'permiso' => 'ctacte.cobrar', 'grupo' => 'Cuenta corriente'];
        $o['recibos'] = ['texto' => 'Recibos', 'ruta' => '/recibos', 'permiso' => 'ctacte.ver', 'grupo' => 'Cuenta corriente'];
        $o['imputar'] = ['texto' => 'Imputación manual', 'ruta' => '/cuenta-corriente/imputar', 'permiso' => 'ctacte.imputar', 'grupo' => 'Cuenta corriente'];
        $o['ctacte_ajuste'] = ['texto' => 'Ajuste de cuenta corriente', 'ruta' => '/cuenta-corriente/ajuste', 'permiso' => 'ctacte.ajustar', 'grupo' => 'Cuenta corriente'];
        $o['cheques'] = ['texto' => 'Cheques en cartera', 'ruta' => '/cheques', 'permiso' => 'ctacte.ver', 'grupo' => 'Cuenta corriente'];
        $o['mp_ingresos'] = ['texto' => 'Ingresos de Mercado Pago', 'ruta' => '/mercadopago/ingresos', 'permiso' => 'mercadopago.ingresos', 'grupo' => 'Facturación'];
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
        $o['compras_qr'] = ['texto' => 'Cargar desde el QR', 'ruta' => '/compras/qr', 'permiso' => 'compras.cargar', 'grupo' => 'Compras'];
        $o['compras_lista'] = ['texto' => 'Compras cargadas', 'ruta' => '/compras/lista', 'permiso' => 'compras.ver', 'grupo' => 'Compras'];
        $o['compras_apagar'] = ['texto' => 'A pagar y vencimientos', 'ruta' => '/compras/a-pagar', 'permiso' => 'compras.ver', 'grupo' => 'Compras'];
        $o['compras_pagos'] = ['texto' => 'Órdenes de pago', 'ruta' => '/compras/pagos', 'permiso' => 'compras.ver', 'grupo' => 'Compras'];
        $o['compras_cuenta'] = ['texto' => 'Cuenta corriente de proveedores', 'ruta' => '/compras/proveedores/cuenta', 'permiso' => 'compras.ver', 'grupo' => 'Compras'];
        $o['compras_analisis'] = ['texto' => 'Análisis de gastos', 'ruta' => '/compras/analisis', 'permiso' => 'compras.ver', 'grupo' => 'Compras'];
        $o['compras_presupuesto'] = ['texto' => 'Presupuesto contra real', 'ruta' => '/compras/presupuesto', 'permiso' => 'compras.ver', 'grupo' => 'Compras'];
        $o['compras_iva'] = ['texto' => 'Subdiario IVA compras', 'ruta' => '/compras/iva', 'permiso' => 'compras.ver', 'grupo' => 'Compras'];
        $o['compras_arca'] = ['texto' => 'Mis Comprobantes de ARCA', 'ruta' => '/compras/mis-comprobantes', 'permiso' => 'compras.ver', 'grupo' => 'Compras'];
        $o['compras_resultado'] = ['texto' => 'Resultado del mes', 'ruta' => '/compras/resultado', 'permiso' => 'compras.ver', 'grupo' => 'Compras'];
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
        $o['soporte_bandeja'] = ['texto' => 'Tickets de soporte', 'ruta' => '/soporte/bandeja', 'permiso' => null, 'grupo' => 'Sistema'];
        $o['soporte'] = ['texto' => 'Soporte de O&S (tickets)', 'ruta' => '/soporte', 'permiso' => 'soporte.usar', 'grupo' => 'Ayuda'];
        return $o;
    }

    /**
     * Íconos del menú (0.54.0, D-138): trazos simples de 24×24 dibujados para FacturaWeb, con el color del texto (currentColor),
     * así siguen al tema claro/oscuro y al color del cliente. Sin librerías ni archivos aparte.
     */
    private const SVG = [
        'inicio' => '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
        'usuario' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
        'usuarios' => '<circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7"/><path d="M18 14.5c2.4.6 4 2.4 4 5.5"/>',
        'caja_art' => '<path d="M3 7l9-4 9 4v10l-9 4-9-4z"/><path d="M3 7l9 4 9-4"/><path d="M12 11v10"/>',
        'etiqueta' => '<path d="M3 12V3h9l9 9-9 9z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'ticket' => '<path d="M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
        'doc_mas' => '<path d="M14 3H6v18h12V7z"/><path d="M14 3v4h4"/><path d="M12 11v6M9 14h6"/>',
        'doc_menos' => '<path d="M14 3H6v18h12V7z"/><path d="M14 3v4h4"/><path d="M9 14h6"/>',
        'docs' => '<path d="M8 3h8l4 4v12H8z"/><path d="M4 7v14h12"/>',
        'portapapeles' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2.5h6V4"/><path d="M9 11h6M9 15h4"/>',
        'carrito' => '<path d="M3 4h2l2.5 11h11L21 7H6.5"/><circle cx="9" cy="19" r="1.5"/><circle cx="17" cy="19" r="1.5"/>',
        'cambio' => '<path d="M4 12V9a3 3 0 0 1 3-3h12l-3-3"/><path d="M20 12v3a3 3 0 0 1-3 3H5l3 3"/>',
        'billetera' => '<path d="M19 7V5a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15v12H5a2 2 0 0 1-2-2V6"/><circle cx="16" cy="14" r="1.2"/>',
        'enlace' => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
        'ajustes' => '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
        'billete' => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 10v4M18 10v4"/>',
        'celular' => '<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/>',
        'ingreso' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v10M8 13l4 4 4-4"/>',
        'caja' => '<rect x="3" y="10" width="18" height="10" rx="2"/><path d="M7 10V5h10v5"/><path d="M10 7.5h4"/><path d="M7 15h.01M12 15h.01M17 15h.01"/>',
        'reloj' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'escudo' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'barras' => '<path d="M4 4v16h16"/><path d="M8 16v-4M12 16V8M16 16v-6M20 16V5"/>',
        'porcentaje' => '<path d="M19 5L5 19"/><circle cx="7" cy="7" r="2.5"/><circle cx="17" cy="17" r="2.5"/>',
        'libro' => '<path d="M4 4h6a2 2 0 0 1 2 2v14a2 2 0 0 0-2-2H4z"/><path d="M20 4h-6a2 2 0 0 0-2 2v14a2 2 0 0 1 2-2h6z"/>',
        'tendencia' => '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
        'monedas' => '<ellipse cx="9" cy="7" rx="6" ry="3"/><path d="M3 7v5c0 1.7 2.7 3 6 3s6-1.3 6-3V7"/><path d="M9 15v2c0 1.7 2.7 3 6 3s6-1.3 6-3v-5c0-1.7-2.7-3-6-3"/>',
        'bolsa' => '<path d="M5 8h14l-1 13H6z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
        'camion' => '<path d="M2 6h12v10H2z"/><path d="M14 10h4l3 3v3h-7"/><circle cx="6" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
        'lista' => '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>',
        'calendario' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'torta' => '<path d="M11 4a8 8 0 1 0 9 9h-9z"/><path d="M14 2.5A8 8 0 0 1 21.5 10H14z"/>',
        'qr' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM21 14v.01M14 21h.01M18 21h3v-4"/>',
        'tabla' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M3 15h18M9 4v16"/>',
        'capas' => '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/>',
        'flechas' => '<path d="M4 8h15l-3-3M20 16H5l3 3"/>',
        'alerta' => '<path d="M12 3l10 18H2z"/><path d="M12 10v5M12 18h.01"/>',
        'deposito' => '<path d="M3 21V9l9-5 9 5v12"/><path d="M7 21v-8h10v8"/><path d="M7 17h10"/>',
        'engranaje' => '<circle cx="12" cy="12" r="3"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M5.3 18.7l2.1-2.1M16.6 7.4l2.1-2.1"/><circle cx="12" cy="12" r="6.5"/>',
        'llave' => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M16 7l3 3M18 5l2 2"/>',
        'subir' => '<path d="M12 15V3M7 8l5-5 5 5"/><path d="M4 15v5h16v-5"/>',
        'sincronizar' => '<path d="M20 11a8 8 0 0 0-14.5-4.5L4 8"/><path d="M4 3v5h5"/><path d="M4 13a8 8 0 0 0 14.5 4.5L20 16"/><path d="M20 21v-5h-5"/>',
        'edificio' => '<path d="M3 21h18M5 18v-8M10 18v-8M14 18v-8M19 18v-8"/><path d="M2 10l10-6 10 6z"/>',
        'servidor' => '<rect x="3" y="4" width="18" height="7" rx="1.5"/><rect x="3" y="13" width="18" height="7" rx="1.5"/><path d="M7 7.5h.01M7 16.5h.01"/>',
        'ayuda' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.3-1 .9-1 1.7"/><path d="M12 17h.01"/>',
        'bandeja' => '<path d="M3 13l3-8h12l3 8v6H3z"/><path d="M3 13h5l1 2h6l1-2h5"/>',
    ];

    /** Ícono de cada grupo (también el de sus opciones sin ícono propio, como las tablas). */
    private const ICONO_GRUPO = [
        'Maestros' => 'usuarios', 'Facturación' => 'ticket', 'Cuenta corriente' => 'billetera', 'Caja' => 'caja',
        'Listados' => 'barras', 'Compras' => 'bolsa', 'Tablas' => 'tabla', 'Stock' => 'capas', 'Sistema' => 'engranaje', 'Ayuda' => 'ayuda',
    ];

    /** Ícono de cada opción del menú. */
    private const ICONO = [
        'inicio' => 'inicio', 'clientes' => 'usuario', 'articulos' => 'caja_art', 'articulos_precios' => 'etiqueta', 'articulos_actualizacion' => 'tendencia',
        'comprobantes_fc' => 'doc_mas', 'comprobantes_pr' => 'portapapeles', 'kiosco' => 'carrito', 'cambios' => 'cambio',
        'comprobantes_nc' => 'doc_menos', 'comprobantes_nd' => 'doc_mas', 'comprobantes' => 'docs', 'masiva' => 'subir', 'mp_ingresos' => 'ingreso', 'mp' => 'celular',
        'ctacte' => 'billetera', 'recibos_nuevo' => 'doc_mas', 'recibos' => 'ticket', 'imputar' => 'enlace', 'ctacte_ajuste' => 'ajustes', 'cheques' => 'billete',
        'caja' => 'caja', 'caja_turnos' => 'reloj', 'caja_auditoria' => 'escudo',
        'caja_diaria' => 'calendario', 'ventas_diarias' => 'tendencia', 'iva_ventas' => 'porcentaje', 'libro_iva' => 'libro',
        'estadistica' => 'barras', 'rentabilidad' => 'monedas', 'lista_costos' => 'etiqueta',
        'compras' => 'barras', 'compras_nueva' => 'doc_mas', 'compras_qr' => 'qr', 'compras_lista' => 'lista', 'compras_apagar' => 'calendario',
        'compras_pagos' => 'billete', 'compras_cuenta' => 'billetera', 'compras_analisis' => 'torta', 'compras_presupuesto' => 'tendencia',
        'compras_iva' => 'porcentaje', 'compras_arca' => 'edificio', 'compras_resultado' => 'monedas', 'proveedores' => 'camion', 'compras_centros' => 'torta',
        'stock' => 'capas', 'stock_movimientos' => 'flechas', 'stock_existencias' => 'deposito', 'stock_ventas_sin_stock' => 'alerta',
        'stock_ajuste' => 'ajustes', 'stock_transferir' => 'flechas', 'stock_depositos' => 'deposito',
        'usuarios' => 'usuarios', 'roles' => 'llave', 'configuracion' => 'ajustes', 'importar' => 'subir', 'agente' => 'sincronizar',
        'arca' => 'edificio', 'sistema' => 'engranaje', 'instancias' => 'servidor', 'soporte_bandeja' => 'bandeja', 'soporte' => 'ayuda',
    ];

    /** SVG del ícono de una opción (o del de su grupo si no tiene propio). Es código fijo: se imprime sin escapar. */
    public static function icono(string $clave, string $grupo = ''): string
    {
        return self::svg(self::ICONO[$clave] ?? self::ICONO_GRUPO[$grupo] ?? 'lista');
    }

    public static function iconoGrupo(string $grupo): string
    {
        return self::svg(self::ICONO_GRUPO[$grupo] ?? 'lista');
    }

    private static function svg(string $nombre): string
    {
        return '<svg class="menu-ico" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"'
            . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . (self::SVG[$nombre] ?? self::SVG['lista']) . '</svg>';
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
        if (($clave === 'mp' || $clave === 'mp_ingresos') && !\App\Modules\MercadoPago\MercadoPago::activo()) {
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
        if ($clave === 'soporte_bandeja') {
            return Auth::esMaster() && \App\Modules\Instancias\InstanciasService::disponible(); // bandeja de O&S: solo en la principal
        }
        if ($clave === 'soporte' && Auth::esMaster() && \App\Modules\Instancias\InstanciasService::disponible()) {
            return false; // O&S en el master usa la bandeja
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
