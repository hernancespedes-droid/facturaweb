<?php
declare(strict_types=1);

namespace App\Modules\Tablas;

/**
 * Las 12 tablas del menú Tablas del VFP (menu1, opciones TA a TM), en el orden del VFP. Cada una se administra con
 * la misma pantalla genérica (TablasController); acá se declara qué campos tiene, cuáles forman la clave O&S
 * (no se cambian después del alta, porque los usan los maestros), qué se ve en la grilla y quién la usa
 * (para no borrar un código que todavía usa un cliente o un artículo).
 *
 * Tipos de campo: texto, entero, decimal, fecha, si_no, opciones (lista fija), ref (código de otra tabla),
 * cuit, email.
 */
final class Definiciones
{
    /** @return array<string,array<string,mixed>> */
    public static function todas(): array
    {
        $cod = static fn (int $largo, string $tipo = 'texto') => ['label' => 'Código', 'tipo' => $tipo, 'largo' => $largo, 'requerido' => true, 'clave' => true];
        $desc = static fn (int $largo = 30) => ['label' => 'Descripción', 'tipo' => 'texto', 'largo' => $largo, 'requerido' => true];
        $pct = static fn (string $label) => ['label' => $label, 'tipo' => 'decimal', 'dec' => 2, 'min' => 0, 'max' => 999.99];
        return [
            'sucursales' => [
                'titulo' => 'Sucursales', 'singular' => 'sucursal', 'vfp' => 'TA',
                'ayuda' => 'Puntos de venta y la numeración de cada comprobante (último número usado).',
                'campos' => [
                    'codigo' => $cod(5, 'entero') + ['max' => 99],
                    'descripcion' => $desc(),
                    'direccion' => ['label' => 'Dirección', 'tipo' => 'texto', 'largo' => 60],
                    'prefijo' => ['label' => 'Punto de venta', 'tipo' => 'entero', 'min' => 0, 'max' => 99998, 'requerido' => true],
                    'ultima_factura_a' => ['label' => 'Última factura A', 'tipo' => 'entero', 'min' => 0, 'max' => 99999999, 'grupo' => 'Numeración'],
                    'ultima_factura_b' => ['label' => 'Última factura B', 'tipo' => 'entero', 'min' => 0, 'max' => 99999999, 'grupo' => 'Numeración'],
                    'ultima_factura_e' => ['label' => 'Última factura E', 'tipo' => 'entero', 'min' => 0, 'max' => 99999999, 'grupo' => 'Numeración'],
                    'ultima_nc_a' => ['label' => 'Última nota de crédito A', 'tipo' => 'entero', 'min' => 0, 'max' => 99999999, 'grupo' => 'Numeración'],
                    'ultima_nc_b' => ['label' => 'Última nota de crédito B', 'tipo' => 'entero', 'min' => 0, 'max' => 99999999, 'grupo' => 'Numeración'],
                    'ultima_nd_a' => ['label' => 'Última nota de débito A', 'tipo' => 'entero', 'min' => 0, 'max' => 99999999, 'grupo' => 'Numeración'],
                    'ultima_nd_b' => ['label' => 'Última nota de débito B', 'tipo' => 'entero', 'min' => 0, 'max' => 99999999, 'grupo' => 'Numeración'],
                    'ultimo_recibo' => ['label' => 'Último recibo', 'tipo' => 'entero', 'min' => 0, 'max' => 99999999, 'grupo' => 'Numeración'],
                    'ultimo_remito' => ['label' => 'Último remito', 'tipo' => 'entero', 'min' => 0, 'max' => 99999999, 'grupo' => 'Numeración'],
                    'iva_1' => $pct('IVA 1 (%)') + ['grupo' => 'Facturación'],
                    'iva_2' => $pct('IVA 2 (%)') + ['grupo' => 'Facturación'],
                    'copias_fac' => ['label' => 'Copias de cada factura', 'tipo' => 'entero', 'min' => 1, 'max' => 9, 'grupo' => 'Facturación'],
                    'fac_electronica' => ['label' => 'Factura electrónica', 'tipo' => 'si_no', 'grupo' => 'Facturación'],
                    'emailenvio' => ['label' => 'Mail desde el que se envían las facturas', 'tipo' => 'email', 'largo' => 100, 'grupo' => 'Facturación'],
                ],
                'lista' => ['codigo', 'descripcion', 'prefijo', 'ultima_factura_a', 'ultima_factura_b'],
                'orden' => 'codigo',
            ],
            'rubros' => [
                'titulo' => 'Rubros', 'singular' => 'rubro', 'vfp' => 'TB',
                'campos' => [
                    'codigo' => $cod(3), 'descripcion' => $desc(),
                    'porcentaje_rubro_l1' => $pct('% lista 1'), 'porcentaje_rubro_l2' => $pct('% lista 2'), 'porcentaje_rubro_l3' => $pct('% lista 3'),
                ],
                'lista' => ['codigo', 'descripcion', 'porcentaje_rubro_l1', 'porcentaje_rubro_l2', 'porcentaje_rubro_l3'],
                'orden' => 'codigo',
                'usos' => [['articulos', ['rubro' => 'codigo'], 'artículos'], ['subrubros', ['rubro' => 'codigo'], 'subrubros']],
            ],
            'subrubros' => [
                'titulo' => 'Subrubros', 'singular' => 'subrubro', 'vfp' => 'TC',
                'campos' => [
                    'rubro' => ['label' => 'Rubro', 'tipo' => 'ref', 'ref' => 'rubros', 'requerido' => true, 'clave' => true],
                    'codigo' => $cod(3), 'descripcion' => $desc(),
                    'porcentaje_subrubro_l1' => $pct('% lista 1'), 'porcentaje_subrubro_l2' => $pct('% lista 2'), 'porcentaje_subrubro_l3' => $pct('% lista 3'),
                ],
                'lista' => ['rubro', 'codigo', 'descripcion'],
                'orden' => 'rubro, codigo',
                'usos' => [['articulos', ['rubro' => 'rubro', 'subrubro' => 'codigo'], 'artículos']],
            ],
            'provincias' => [
                'titulo' => 'Provincias', 'singular' => 'provincia', 'vfp' => 'TD',
                'campos' => ['codigo' => $cod(2), 'descripcion' => $desc()],
                'lista' => ['codigo', 'descripcion'],
                'orden' => 'descripcion',
                'usos' => [['clientes', ['provincia' => 'codigo'], 'clientes'], ['transportes', ['provincia' => 'codigo'], 'transportes']],
            ],
            'parametros' => [
                'titulo' => 'Parámetros', 'singular' => 'parámetros', 'vfp' => 'TE', 'unico' => true,
                'ayuda' => 'Datos de la empresa que factura (van impresos en los comprobantes) y numeradores generales.',
                'campos' => [
                    'empresa' => ['label' => 'Razón social', 'tipo' => 'texto', 'largo' => 120, 'requerido' => true],
                    'cuit' => ['label' => 'CUIT', 'tipo' => 'cuit'],
                    'iva' => ['label' => 'Condición de IVA', 'tipo' => 'opciones', 'opciones' => self::CONDICIONES_EMPRESA],
                    'tk_iva' => ['label' => 'Condición de IVA (texto impreso)', 'tipo' => 'texto', 'largo' => 40],
                    'tk_i_brutos' => ['label' => 'Ingresos brutos', 'tipo' => 'texto', 'largo' => 20],
                    'inicio_actividades' => ['label' => 'Inicio de actividades', 'tipo' => 'fecha'],
                    'tk_direccion' => ['label' => 'Domicilio comercial', 'tipo' => 'texto', 'largo' => 100],
                    'tk_telefono' => ['label' => 'Teléfono', 'tipo' => 'texto', 'largo' => 40],
                    'mail' => ['label' => 'Mail', 'tipo' => 'email', 'largo' => 100],
                    'ultimo_cliente' => ['label' => 'Último código de cliente', 'tipo' => 'entero', 'min' => 0, 'max' => 99999999, 'grupo' => 'Numeradores'],
                    'ultimo_recibo' => ['label' => 'Último recibo', 'tipo' => 'entero', 'min' => 0, 'max' => 99999999, 'grupo' => 'Numeradores'],
                    'por_impuesto' => $pct('% impuesto') + ['grupo' => 'Numeradores'],
                ],
                'lista' => ['empresa', 'cuit'],
                'orden' => 'id',
            ],
            'bancos' => [
                'titulo' => 'Bancos', 'singular' => 'banco', 'vfp' => 'TF',
                'campos' => ['codigo' => $cod(5, 'entero') + ['max' => 999], 'descripcion' => $desc()],
                'lista' => ['codigo', 'descripcion'],
                'orden' => 'codigo',
            ],
            'cotizacion_dolar' => [
                'titulo' => 'Cotizaciones del dólar', 'singular' => 'cotización', 'vfp' => 'TG',
                'campos' => [
                    'fecha' => ['label' => 'Fecha', 'tipo' => 'fecha', 'requerido' => true, 'clave' => true],
                    'importe' => ['label' => 'Importe ($ por dólar)', 'tipo' => 'decimal', 'dec' => 4, 'min' => 0.0001, 'max' => 99999999, 'requerido' => true],
                    'observaciones' => ['label' => 'Observaciones', 'tipo' => 'texto', 'largo' => 50],
                ],
                'lista' => ['fecha', 'importe', 'observaciones'],
                'orden' => 'fecha DESC',
            ],
            'vendedores' => [
                'titulo' => 'Vendedores', 'singular' => 'vendedor', 'vfp' => 'TI',
                'campos' => ['codigo' => $cod(2), 'descripcion' => $desc(), 'comision_1' => $pct('Comisión 1 (%)'), 'comision_2' => $pct('Comisión 2 (%)')],
                'lista' => ['codigo', 'descripcion', 'comision_1', 'comision_2'],
                'orden' => 'codigo',
                'usos' => [['clientes', ['vendedor' => 'codigo'], 'clientes']],
            ],
            'tasas_iva' => [
                'titulo' => 'Alícuotas de IVA', 'singular' => 'alícuota', 'vfp' => 'TJ',
                'campos' => ['codigo' => $cod(2), 'descripcion' => $desc(), 'porcentaje' => $pct('Porcentaje') + ['requerido' => true]],
                'lista' => ['codigo', 'descripcion', 'porcentaje'],
                'orden' => 'porcentaje DESC',
                'usos' => [['articulos', ['alicuota' => 'codigo'], 'artículos']],
            ],
            'condicionpago' => [
                'titulo' => 'Condiciones de pago', 'singular' => 'condición de pago', 'vfp' => 'TK',
                'campos' => ['codigo' => $cod(2), 'descripcion' => $desc()],
                'lista' => ['codigo', 'descripcion'],
                'orden' => 'codigo',
                'usos' => [['clientes', ['condicionpago' => 'codigo'], 'clientes']],
            ],
            'transportes' => [
                'titulo' => 'Transportes', 'singular' => 'transporte', 'vfp' => 'TL',
                'campos' => [
                    'codigo' => $cod(5, 'entero') + ['max' => 999], 'descripcion' => $desc(),
                    'domicilio' => ['label' => 'Domicilio', 'tipo' => 'texto', 'largo' => 60],
                    'localidad' => ['label' => 'Localidad', 'tipo' => 'texto', 'largo' => 40],
                    'codigo_postal' => ['label' => 'Código postal', 'tipo' => 'texto', 'largo' => 8],
                    'provincia' => ['label' => 'Provincia', 'tipo' => 'ref', 'ref' => 'provincias'],
                    'telefono' => ['label' => 'Teléfono', 'tipo' => 'texto', 'largo' => 60],
                    'cuit' => ['label' => 'CUIT', 'tipo' => 'cuit'],
                ],
                'lista' => ['codigo', 'descripcion', 'localidad', 'telefono'],
                'orden' => 'codigo',
                'usos' => [['clientes', ['transporte' => 'codigo'], 'clientes']],
            ],
            'forma_de_pagos' => [
                'titulo' => 'Formas de pago', 'singular' => 'forma de pago', 'vfp' => 'TM',
                'campos' => [
                    'codigo' => $cod(2), 'descripcion' => $desc(),
                    'tipo' => ['label' => 'Tipo de pago', 'tipo' => 'opciones', 'requerido' => true, 'opciones' => self::TIPOS_PAGO],
                    'recargo' => $pct('Recargo (%)'),
                    'cta_contable' => ['label' => 'Imputación (cuenta contable)', 'tipo' => 'entero', 'min' => 0, 'max' => 9999],
                ],
                'lista' => ['codigo', 'descripcion', 'tipo', 'recargo'],
                'orden' => 'codigo',
            ],
        ];
    }

    /** Tipo de forma de pago (combo de forma_de_pagos.scx). */
    public const TIPOS_PAGO = [1 => 'Efectivo', 2 => 'Tickets', 3 => 'Tarjeta', 4 => 'Dólares', 5 => 'Cheques', 6 => 'Cta./Cte.', 7 => 'Ajuste', 8 => 'Nota de crédito', 9 => 'Gastos'];

    /** Condición de IVA del emisor (mismos códigos que el cliente). */
    public const CONDICIONES_EMPRESA = [1 => 'Responsable Inscripto', 7 => 'Monotributo', 4 => 'Exento'];

    /** @return array<string,mixed>|null */
    public static function de(string $tabla): ?array
    {
        $d = self::todas()[$tabla] ?? null;
        if ($d === null) {
            return null;
        }
        return $d + ['tabla' => $tabla, 'permiso' => 'tablas.' . $tabla, 'usos' => [], 'unico' => false, 'ayuda' => ''];
    }

    /** Campos que forman la clave O&S. @return string[] */
    public static function clave(array $def): array
    {
        return array_keys(array_filter($def['campos'], static fn ($c) => !empty($c['clave'])));
    }
}
