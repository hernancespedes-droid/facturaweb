# Estructuras de tablas (DBF)

Generado a partir de los encabezados de los `.dbf` y de los `.cdx` de `G:\facturador_web`. Los archivos de datos (`.dbf`, `.fpt`, `.cdx`) **no** están en el repositorio: acá solo figura su estructura.

Total: 147 tablas.

En las tablas que pertenecen a una base de datos (`.dbc`), el nombre de campo es el nombre largo guardado en el DBC; cuando difiere del nombre de 10 caracteres del encabezado DBF se indica entre paréntesis. La cantidad de registros es la del encabezado al momento de la lectura.

Tipos: C=Character, N=Numeric, F=Float, D=Date, T=DateTime, L=Logical, M=Memo, I=Integer, Y=Currency, B=Double, G=General, V=Varchar, Q=Varbinary, W=Blob.

## Índice

- [_precios.DBF](#_preciosdbf)
- [ABC.DBF](#abcdbf)
- [anchos.dbf](#anchosdbf)
- [archivos_a_borrar.DBF](#archivos_a_borrardbf)
- [art_transf.dbf](#art_transfdbf)
- [ARTICAB.DBF](#articabdbf)
- [articulos.dbf](#articulosdbf)
- [articulos_por_proveedor.dbf](#articulos_por_proveedordbf)
- [ARTICULOS_PRECIOS.DBF](#articulos_preciosdbf)
- [auditoria.DBF](#auditoriadbf)
- [BANCOS.DBF](#bancosdbf)
- [CABECERA.DBF](#cabeceradbf)
- [cabecera_pedidos.dbf](#cabecera_pedidosdbf)
- [caja.dbf](#cajadbf)
- [Cierres.DBF](#cierresdbf)
- [cla_accesos.dbf](#cla_accesosdbf)
- [CLA_ERRORES.DBF](#cla_erroresdbf)
- [cla_opciones.DBF](#cla_opcionesdbf)
- [cla_usuarios.dbf](#cla_usuariosdbf)
- [CLI.DBF](#clidbf)
- [clientecab.dbf](#clientecabdbf)
- [clientes.dbf](#clientesdbf)
- [clientesca.dbf](#clientescadbf)
- [clisf.dbf](#clisfdbf)
- [cobra.dbf](#cobradbf)
- [COLORES.DBF](#coloresdbf)
- [condicionpago.DBF](#condicionpagodbf)
- [consignacion.dbf](#consignaciondbf)
- [Copia de mvstk_items.dbf](#copia-de-mvstk_itemsdbf)
- [Copia de REMITOS_CAB.DBF](#copia-de-remitos_cabdbf)
- [CORTES_DET.DBF](#cortes_detdbf)
- [cotizacion_dolar.DBF](#cotizacion_dolardbf)
- [cs_articulos.DBF](#cs_articulosdbf)
- [cs_clientes.DBF](#cs_clientesdbf)
- [ctact.dbf](#ctactdbf)
- [cur_pos.DBF](#cur_posdbf)
- [descuentos.DBF](#descuentosdbf)
- [DET.DBF](#detdbf)
- [domi.DBF](#domidbf)
- [EMPRESAS.DBF](#empresasdbf)
- [envio_taller_cab.dbf](#envio_taller_cabdbf)
- [envio_taller_det.dbf](#envio_taller_detdbf)
- [Equivalencias_afip.DBF](#equivalencias_afipdbf)
- [ERRORES.DBF](#erroresdbf)
- [estadistica.dbf](#estadisticadbf)
- [estado_de_articulos.DBF](#estado_de_articulosdbf)
- [FABRIPACK_ref.DBF](#fabripack_refdbf)
- [fe_Actualizacion.dbf](#fe_actualizaciondbf)
- [fe_Configuracion.DBF](#fe_configuraciondbf)
- [fe_Parametros.dbf](#fe_parametrosdbf)
- [forma_de_pagos.dbf](#forma_de_pagosdbf)
- [formulas_articulo.dbf](#formulas_articulodbf)
- [formulas_base.DBF](#formulas_basedbf)
- [FOXUSER.DBF](#foxuserdbf)
- [gig.DBF](#gigdbf)
- [h_caja.DBF](#h_cajadbf)
- [h_estadistica.DBF](#h_estadisticadbf)
- [h_subdiario.DBF](#h_subdiariodbf)
- [Historia/h_caja.DBF](#historiah_cajadbf)
- [Historia/h_estadistica.DBF](#historiah_estadisticadbf)
- [Historia/h_subdiario.DBF](#historiah_subdiariodbf)
- [IMPORTES_POR_MODELOS_POR_TALLER.DBF](#importes_por_modelos_por_tallerdbf)
- [imputacion_remitos.dbf](#imputacion_remitosdbf)
- [imputaciones.dbf](#imputacionesdbf)
- [ITEMS.DBF](#itemsdbf)
- [items_pedidos.dbf](#items_pedidosdbf)
- [iva.dbf](#ivadbf)
- [Liquidacion_taller_cab.DBF](#liquidacion_taller_cabdbf)
- [liquidacion_taller_det.DBF](#liquidacion_taller_detdbf)
- [LISTADO.DBF](#listadodbf)
- [LISTAS.DBF](#listasdbf)
- [Mangas.DBF](#mangasdbf)
- [materias_primas.dbf](#materias_primasdbf)
- [MINIMOS.DBF](#minimosdbf)
- [modelos.dbf](#modelosdbf)
- [motivos.dbf](#motivosdbf)
- [mp_pendiente_compras.dbf](#mp_pendiente_comprasdbf)
- [mp_por_proveedor.dbf](#mp_por_proveedordbf)
- [mvstk_cabecera.dbf](#mvstk_cabeceradbf)
- [mvstk_items.dbf](#mvstk_itemsdbf)
- [MVSTKMP_CABECERA.DBF](#mvstkmp_cabeceradbf)
- [mvstkmp_items.DBF](#mvstkmp_itemsdbf)
- [notaped_cab.dbf](#notaped_cabdbf)
- [notaped_det.dbf](#notaped_detdbf)
- [ordcomp_detalle.dbf](#ordcomp_detalledbf)
- [ordcomp_detalle_mp.dbf](#ordcomp_detalle_mpdbf)
- [ordcomp_encabezado.dbf](#ordcomp_encabezadodbf)
- [ordcomp_encabezado_mp.dbf](#ordcomp_encabezado_mpdbf)
- [ordcomp_libre_cab.dbf](#ordcomp_libre_cabdbf)
- [ordcomp_libre_det.DBF](#ordcomp_libre_detdbf)
- [orden_de_corte_cab.dbf](#orden_de_corte_cabdbf)
- [orden_de_corte_det.dbf](#orden_de_corte_detdbf)
- [orden_de_corte_promedios.dbf](#orden_de_corte_promediosdbf)
- [OYS_ref.DBF](#oys_refdbf)
- [PAGOS.DBF](#pagosdbf)
- [PAGOS_C.DBF](#pagos_cdbf)
- [parametros.dbf](#parametrosdbf)
- [pasaje_asientos.DBF](#pasaje_asientosdbf)
- [pendientes.dbf](#pendientesdbf)
- [pp.DBF](#ppdbf)
- [precios_personales.dbf](#precios_personalesdbf)
- [presu_cab.dbf](#presu_cabdbf)
- [presu_item.dbf](#presu_itemdbf)
- [productos_en_procesos.DBF](#productos_en_procesosdbf)
- [productos_relacionados.dbf](#productos_relacionadosdbf)
- [PROVE.DBF](#provedbf)
- [PROVE_C.DBF](#prove_cdbf)
- [proveedores.DBF](#proveedoresdbf)
- [provincias.DBF](#provinciasdbf)
- [recepcion_taller_cab.DBF](#recepcion_taller_cabdbf)
- [recepcion_taller_det.dbf](#recepcion_taller_detdbf)
- [remitos_cab.dbf](#remitos_cabdbf)
- [remitos_item.dbf](#remitos_itemdbf)
- [REPORTS/OutputConfig.DBF](#reportsoutputconfigdbf)
- [rollos_telas.dbf](#rollos_telasdbf)
- [ROLLOS_TELAS_DET.DBF](#rollos_telas_detdbf)
- [ropateck_Prod_ref.DBF](#ropateck_prod_refdbf)
- [Ropateck_ref.DBF](#ropateck_refdbf)
- [RUBROS.DBF](#rubrosdbf)
- [rubros_materias_primas.dbf](#rubros_materias_primasdbf)
- [scaner.DBF](#scanerdbf)
- [STOCK.DBF](#stockdbf)
- [STOCK2.DBF](#stock2dbf)
- [subdiario.dbf](#subdiariodbf)
- [subrubros.dbf](#subrubrosdbf)
- [SUC_ART.DBF](#suc_artdbf)
- [sucursales.dbf](#sucursalesdbf)
- [talleres.dbf](#talleresdbf)
- [talles_equivalentes.dbf](#talles_equivalentesdbf)
- [tasas_iva.dbf](#tasas_ivadbf)
- [Telas.DBF](#telasdbf)
- [temptext.DBF](#temptextdbf)
- [tempview.DBF](#tempviewdbf)
- [TICKETS.DBF](#ticketsdbf)
- [tipo_de_cierre.DBF](#tipo_de_cierredbf)
- [tipo_de_clientes.DBF](#tipo_de_clientesdbf)
- [Tipos_de_materia_prima.DBF](#tipos_de_materia_primadbf)
- [tipos_mvstk.dbf](#tipos_mvstkdbf)
- [tmp_cli.dbf](#tmp_clidbf)
- [transformaciones.dbf](#transformacionesdbf)
- [transportes.dbf](#transportesdbf)
- [tributos.dbf](#tributosdbf)
- [unidad_de_medidas.DBF](#unidad_de_medidasdbf)
- [unidad_venta.dbf](#unidad_ventadbf)
- [Unidades_de_medida.DBF](#unidades_de_medidadbf)
- [v_proveedores.DBF](#v_proveedoresdbf)
- [vendedores.dbf](#vendedoresdbf)

## _precios.DBF

Formato: Visual FoxPro · 10 campos · largo de registro: 81 bytes · registros: 2677 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | N (Numeric) | 7 | 0 |  |
| 2 | FILLER1 | C (Character) | 1 |  |  |
| 3 | LISTA1 | N (Numeric) | 17 | 0 |  |
| 4 | FILLER2 | C (Character) | 1 |  |  |
| 5 | LISTA2 | N (Numeric) | 17 | 0 |  |
| 6 | FILLER3 | C (Character) | 1 |  |  |
| 7 | LISTA3 | N (Numeric) | 17 | 0 |  |
| 8 | FILLER4 | C (Character) | 1 |  |  |
| 9 | COSTO | N (Numeric) | 17 | 0 |  |
| 10 | FIN | C (Character) | 1 |  |  |

Índices: sin CDX estructural.

## ABC.DBF

Formato: Visual FoxPro · 1 campos · largo de registro: 11 bytes · registros: 8 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | A1 | C (Character) | 10 |  |  |

Índices: sin CDX estructural.

## anchos.dbf

Formato: Visual FoxPro · 2 campos · largo de registro: 9 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | C (Character) | 3 |  |  |
| 2 | ANCHO | N (Numeric) | 5 | 3 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| ANCHO | `ancho` | Regular | ASC | `.NOT.DELETED()` |
| CODIGO | `codigo` | Regular | ASC | `.NOT.DELETED()` |

## archivos_a_borrar.DBF

Formato: Visual FoxPro · 4 campos · largo de registro: 58 bytes · registros: 0 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | ARCHIVO | C (Character) | 40 |  |  |
| 2 | FECHA | D (Date) | 8 |  |  |
| 3 | MARCA | C (Character) | 1 |  |  |
| 4 | FECHA_MARC | D (Date) | 8 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| FECHA | `DTOS(fecha)` | Regular | ASC |  |

## art_transf.dbf

Formato: Visual FoxPro · 34 campos · largo de registro: 254 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | C (Character) | 15 |  |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 40 |  |  |
| 3 | codigo_asociado (DBF: CODIGO_ASO) | C (Character) | 15 |  |  |
| 4 | RUBRO | C (Character) | 3 |  |  |
| 5 | SUBRUBRO | C (Character) | 3 |  |  |
| 6 | PROVEEDOR | N (Numeric) | 5 | 0 |  |
| 7 | codigo_de_barras (DBF: CODIGO_DE_) | N (Numeric) | 13 | 0 |  |
| 8 | unidad_de_medida (DBF: UNIDAD_DE_) | C (Character) | 2 |  |  |
| 9 | unidades_por_pack (DBF: UNIDADES_P) | N (Numeric) | 3 | 0 |  |
| 10 | cantidad_de_unidades (DBF: CANTIDAD_D) | N (Numeric) | 6 | 0 |  |
| 11 | forma_de_venta (DBF: FORMA_DE_V) | N (Numeric) | 1 | 0 |  |
| 12 | ESTADO | C (Character) | 2 |  |  |
| 13 | stock_minimo_unidades (DBF: STOCK_MINI) | N (Numeric) | 6 | 0 |  |
| 14 | stock_minimo_kilos (DBF: STOCK_MIN2) | N (Numeric) | 9 | 3 |  |
| 15 | bonificacion_a (DBF: BONIFICACI) | N (Numeric) | 6 | 2 |  |
| 16 | bonificacion_b (DBF: BONIFICAC2) | N (Numeric) | 6 | 2 |  |
| 17 | bonificacion_mercaderia_cada (DBF: BONIFICAC3) | N (Numeric) | 3 | 0 |  |
| 18 | bonificacion_mercaderia_cuanto (DBF: BONIFICAC4) | N (Numeric) | 3 | 0 |  |
| 19 | precio_reposicion_caja (DBF: PRECIO_REP) | N (Numeric) | 11 | 4 |  |
| 20 | costo_ultima_entrada_unidad (DBF: COSTO_ULTI) | N (Numeric) | 9 | 2 |  |
| 21 | costo_reposicion_neto (DBF: COSTO_REPO) | N (Numeric) | 9 | 2 |  |
| 22 | precio_de_venta_1 (DBF: PRECIO_DE_) | N (Numeric) | 11 | 4 |  |
| 23 | precio_de_venta_2 (DBF: PRECIO_DE2) | N (Numeric) | 11 | 4 |  |
| 24 | precio_de_venta_3 (DBF: PRECIO_DE3) | N (Numeric) | 11 | 4 |  |
| 25 | precio_de_oferta (DBF: PRECIO_DE4) | N (Numeric) | 11 | 4 |  |
| 26 | fecha_ultimo_cambio_de_precio (DBF: FECHA_ULTI) | D (Date) | 8 |  |  |
| 27 | MARGEN | N (Numeric) | 5 | 2 |  |
| 28 | BASE_COSTO | N (Numeric) | 3 | 0 |  |
| 29 | precio_unitario (DBF: PRECIO_UNI) | N (Numeric) | 11 | 4 |  |
| 30 | lista_forzada (DBF: LISTA_FORZ) | L (Logical) | 1 |  |  |
| 31 | lista_forzada_mi (DBF: LISTA_FOR2) | L (Logical) | 1 |  |  |
| 32 | incluirlista (DBF: INCLUIRLIS) | L (Logical) | 1 |  |  |
| 33 | local_de_venta (DBF: LOCAL_DE_V) | N (Numeric) | 1 | 0 |  |
| 34 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| BARRA | `codigo_de_barras` | Regular | ASC |  |
| CODIGO | `codigo` | Candidate | ASC | `.NOT.DELETED()` |
| ESTADO | `estado` | Regular | ASC |  |
| PROVEEDOR | `proveedor` | Regular | ASC |  |
| RUBRO | `rubro` | Regular | ASC |  |
| SUBRUBRO | `subrubro` | Regular | ASC |  |
| UNIDAD | `unidad_de_medida` | Regular | ASC |  |

## ARTICAB.DBF

Formato: Visual FoxPro · 75 campos · largo de registro: 229 bytes · registros: 17 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | PLATFORM | C (Character) | 8 |  |  |
| 2 | UNIQUEID | C (Character) | 10 |  |  |
| 3 | TIMESTAMP | N (Numeric) | 10 | 0 |  |
| 4 | OBJTYPE | N (Numeric) | 2 | 0 |  |
| 5 | OBJCODE | N (Numeric) | 3 | 0 |  |
| 6 | NAME | M (Memo) | 4 |  |  |
| 7 | EXPR | M (Memo) | 4 |  |  |
| 8 | VPOS | N (Numeric) | 9 | 3 |  |
| 9 | HPOS | N (Numeric) | 9 | 3 |  |
| 10 | HEIGHT | N (Numeric) | 9 | 3 |  |
| 11 | WIDTH | N (Numeric) | 9 | 3 |  |
| 12 | STYLE | M (Memo) | 4 |  |  |
| 13 | PICTURE | M (Memo) | 4 |  |  |
| 14 | ORDER | M (Memo) | 4 |  | binario (NOCPTRANS) |
| 15 | UNIQUE | L (Logical) | 1 |  |  |
| 16 | COMMENT | M (Memo) | 4 |  |  |
| 17 | ENVIRON | L (Logical) | 1 |  |  |
| 18 | BOXCHAR | C (Character) | 1 |  |  |
| 19 | FILLCHAR | C (Character) | 1 |  |  |
| 20 | TAG | M (Memo) | 4 |  |  |
| 21 | TAG2 | M (Memo) | 4 |  | binario (NOCPTRANS) |
| 22 | PENRED | N (Numeric) | 5 | 0 |  |
| 23 | PENGREEN | N (Numeric) | 5 | 0 |  |
| 24 | PENBLUE | N (Numeric) | 5 | 0 |  |
| 25 | FILLRED | N (Numeric) | 5 | 0 |  |
| 26 | FILLGREEN | N (Numeric) | 5 | 0 |  |
| 27 | FILLBLUE | N (Numeric) | 5 | 0 |  |
| 28 | PENSIZE | N (Numeric) | 5 | 0 |  |
| 29 | PENPAT | N (Numeric) | 5 | 0 |  |
| 30 | FILLPAT | N (Numeric) | 5 | 0 |  |
| 31 | FONTFACE | M (Memo) | 4 |  |  |
| 32 | FONTSTYLE | N (Numeric) | 3 | 0 |  |
| 33 | FONTSIZE | N (Numeric) | 3 | 0 |  |
| 34 | MODE | N (Numeric) | 3 | 0 |  |
| 35 | RULER | N (Numeric) | 1 | 0 |  |
| 36 | RULERLINES | N (Numeric) | 1 | 0 |  |
| 37 | GRID | L (Logical) | 1 |  |  |
| 38 | GRIDV | N (Numeric) | 2 | 0 |  |
| 39 | GRIDH | N (Numeric) | 2 | 0 |  |
| 40 | FLOAT | L (Logical) | 1 |  |  |
| 41 | STRETCH | L (Logical) | 1 |  |  |
| 42 | STRETCHTOP | L (Logical) | 1 |  |  |
| 43 | TOP | L (Logical) | 1 |  |  |
| 44 | BOTTOM | L (Logical) | 1 |  |  |
| 45 | SUPTYPE | N (Numeric) | 1 | 0 |  |
| 46 | SUPREST | N (Numeric) | 1 | 0 |  |
| 47 | NOREPEAT | L (Logical) | 1 |  |  |
| 48 | RESETRPT | N (Numeric) | 2 | 0 |  |
| 49 | PAGEBREAK | L (Logical) | 1 |  |  |
| 50 | COLBREAK | L (Logical) | 1 |  |  |
| 51 | RESETPAGE | L (Logical) | 1 |  |  |
| 52 | GENERAL | N (Numeric) | 3 | 0 |  |
| 53 | SPACING | N (Numeric) | 3 | 0 |  |
| 54 | DOUBLE | L (Logical) | 1 |  |  |
| 55 | SWAPHEADER | L (Logical) | 1 |  |  |
| 56 | SWAPFOOTER | L (Logical) | 1 |  |  |
| 57 | EJECTBEFOR | L (Logical) | 1 |  |  |
| 58 | EJECTAFTER | L (Logical) | 1 |  |  |
| 59 | PLAIN | L (Logical) | 1 |  |  |
| 60 | SUMMARY | L (Logical) | 1 |  |  |
| 61 | ADDALIAS | L (Logical) | 1 |  |  |
| 62 | OFFSET | N (Numeric) | 3 | 0 |  |
| 63 | TOPMARGIN | N (Numeric) | 3 | 0 |  |
| 64 | BOTMARGIN | N (Numeric) | 3 | 0 |  |
| 65 | TOTALTYPE | N (Numeric) | 2 | 0 |  |
| 66 | RESETTOTAL | N (Numeric) | 2 | 0 |  |
| 67 | RESOID | N (Numeric) | 3 | 0 |  |
| 68 | CURPOS | L (Logical) | 1 |  |  |
| 69 | SUPALWAYS | L (Logical) | 1 |  |  |
| 70 | SUPOVFLOW | L (Logical) | 1 |  |  |
| 71 | SUPRPCOL | N (Numeric) | 1 | 0 |  |
| 72 | SUPGROUP | N (Numeric) | 2 | 0 |  |
| 73 | SUPVALCHNG | L (Logical) | 1 |  |  |
| 74 | SUPEXPR | M (Memo) | 4 |  |  |
| 75 | USER | M (Memo) | 4 |  |  |

Índices: sin CDX estructural.

## articulos.dbf

Formato: Visual FoxPro · 45 campos · largo de registro: 271 bytes · registros: 69 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | C (Character) | 15 |  |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 40 |  |  |
| 3 | codigo_asociado (DBF: CODIGO_ASO) | C (Character) | 15 |  |  |
| 4 | RUBRO | C (Character) | 3 |  |  |
| 5 | SUBRUBRO | C (Character) | 3 |  |  |
| 6 | codigo_de_barras (DBF: CODIGO_DE_) | N (Numeric) | 13 | 0 |  |
| 7 | unidad_de_medida (DBF: UNIDAD_DE_) | C (Character) | 2 |  |  |
| 8 | unidades_por_pack (DBF: UNIDADES_P) | N (Numeric) | 3 | 0 |  |
| 9 | cantidad_de_unidades (DBF: CANTIDAD_D) | N (Numeric) | 6 | 0 |  |
| 10 | forma_de_venta (DBF: FORMA_DE_V) | N (Numeric) | 1 | 0 |  |
| 11 | ESTADO | C (Character) | 2 |  |  |
| 12 | stock_minimo_unidades (DBF: STOCK_MINI) | N (Numeric) | 6 | 0 |  |
| 13 | stock_minimo_kilos (DBF: STOCK_MIN2) | N (Numeric) | 9 | 3 |  |
| 14 | bonificacion_a (DBF: BONIFICACI) | N (Numeric) | 6 | 2 |  |
| 15 | bonificacion_b (DBF: BONIFICAC2) | N (Numeric) | 6 | 2 |  |
| 16 | bonificacion_mercaderia_cada (DBF: BONIFICAC3) | N (Numeric) | 3 | 0 |  |
| 17 | bonificacion_mercaderia_cuanto (DBF: BONIFICAC4) | N (Numeric) | 3 | 0 |  |
| 18 | precio_de_venta_1 (DBF: PRECIO_DE_) | N (Numeric) | 11 | 4 |  |
| 19 | precio_de_venta_2 (DBF: PRECIO_DE2) | N (Numeric) | 11 | 4 |  |
| 20 | precio_de_venta_3 (DBF: PRECIO_DE3) | N (Numeric) | 11 | 4 |  |
| 21 | precio_de_oferta (DBF: PRECIO_DE4) | N (Numeric) | 11 | 4 |  |
| 22 | MARGEN | N (Numeric) | 6 | 2 |  |
| 23 | BASE_COSTO | N (Numeric) | 6 | 2 |  |
| 24 | precio_unitario (DBF: PRECIO_UNI) | N (Numeric) | 11 | 4 |  |
| 25 | lista_forzada (DBF: LISTA_FORZ) | L (Logical) | 1 |  |  |
| 26 | lista_forzada_mi (DBF: LISTA_FOR2) | L (Logical) | 1 |  |  |
| 27 | incluirlista (DBF: INCLUIRLIS) | L (Logical) | 1 |  |  |
| 28 | COLUMNAS | N (Numeric) | 1 | 0 |  |
| 29 | local_de_venta (DBF: LOCAL_DE_V) | N (Numeric) | 1 | 0 |  |
| 30 | GRABADO | L (Logical) | 1 |  |  |
| 31 | ALICUOTA | C (Character) | 2 |  |  |
| 32 | PROVEEDOR | N (Numeric) | 4 | 0 |  |
| 33 | COSTO | N (Numeric) | 11 | 4 |  |
| 34 | MONEDA | C (Character) | 3 |  |  |
| 35 | unidad_venta (DBF: UNIDAD_VEN) | C (Character) | 2 |  |  |
| 36 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 37 | MODELO | C (Character) | 4 |  |  |
| 38 | MANGA | C (Character) | 2 |  |  |
| 39 | CIERRE | C (Character) | 2 |  |  |
| 40 | TELA | C (Character) | 3 |  |  |
| 41 | COLOR | C (Character) | 3 |  |  |
| 42 | TALLE | N (Numeric) | 2 | 0 |  |
| 43 | talle_en_letras (DBF: TALLE_EN_L) | C (Character) | 3 |  |  |
| 44 | PROMEDIO | N (Numeric) | 8 | 2 |  |
| 45 | cta_contable (DBF: CTA_CONTAB) | N (Numeric) | 4 | 0 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| ASOCIADO | `codigo_asociado` | Regular | ASC |  |
| BARRA | `codigo_de_barras` | Regular | ASC |  |
| CODIGO | `codigo` | Candidate | ASC | `.NOT.DELETED()` |
| ESTADO | `estado` | Regular | ASC |  |
| PATRON | `LEFT(codigo,3)+rubro+subrubro+modelo+manga+cierre+tela+color` | Unique | ASC |  |
| RUBRO | `rubro` | Regular | ASC |  |
| SUBRUBRO | `subrubro` | Regular | ASC |  |
| UNIDAD | `unidad_de_medida` | Regular | ASC |  |

## articulos_por_proveedor.dbf

Formato: Visual FoxPro · 15 campos · largo de registro: 125 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | ARTICULO | C (Character) | 15 |  |  |
| 2 | PROVEEDOR | N (Numeric) | 5 | 0 |  |
| 3 | num_interno (DBF: NUM_INTERN) | C (Character) | 15 |  |  |
| 4 | COSTO | N (Numeric) | 10 | 4 |  |
| 5 | DESC1 | N (Numeric) | 5 | 2 |  |
| 6 | DESC2 | N (Numeric) | 5 | 2 |  |
| 7 | DESC3 | N (Numeric) | 5 | 2 |  |
| 8 | unidad_venta (DBF: UNIDAD_VEN) | C (Character) | 2 |  |  |
| 9 | FECHA1 | D (Date) | 8 |  |  |
| 10 | CANTIDAD1 | N (Numeric) | 10 | 2 |  |
| 11 | FECHA2 | D (Date) | 8 |  |  |
| 12 | CANTIDAD2 | N (Numeric) | 10 | 2 |  |
| 13 | FECHA3 | D (Date) | 8 |  |  |
| 14 | CANTIDAD3 | N (Numeric) | 10 | 2 |  |
| 15 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| ARTICULO | `articulo` | Regular | ASC |  |
| ART_PRO | `articulo+STR(proveedor,5)` | Regular | ASC |  |

## ARTICULOS_PRECIOS.DBF

Formato: Visual FoxPro · 4 campos · largo de registro: 38 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | ARTICULOS | C (Character) | 15 |  |  |
| 2 | NUM_LISTA | N (Numeric) | 3 | 0 |  |
| 3 | VALOR | N (Numeric) | 11 | 4 |  |
| 4 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| ORD | `articulos+STR(num_lista,3)` | Candidate | ASC | `.NOT.DELETED()` |

## auditoria.DBF

Formato: Visual FoxPro · 7 campos · largo de registro: 55 bytes · registros: 9736 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | USUARIO | C (Character) | 20 |  |  |
| 2 | CANAL | C (Character) | 1 |  |  |
| 3 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 4 | TERMINAL | N (Numeric) | 2 | 0 |  |
| 5 | FECHAHORA | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 6 | PROGRAMA | C (Character) | 20 |  |  |
| 7 | OPERACION | C (Character) | 1 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| PROGRAMA | `programa` | Regular | ASC |  |

## BANCOS.DBF

Formato: Visual FoxPro · 3 campos · largo de registro: 42 bytes · registros: 34 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | N (Numeric) | 3 | 0 |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 30 |  |  |
| 3 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Candidate | ASC | `.NOT.DELETED()` |

## CABECERA.DBF

Formato: Visual FoxPro · 6 campos · largo de registro: 182 bytes · registros: 0 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NUMERO | N (Numeric) | 10 | 0 |  |
| 2 | NOMBRE | C (Character) | 40 |  |  |
| 3 | INTERPRETE | C (Character) | 30 |  |  |
| 4 | COMPANIA | C (Character) | 20 |  |  |
| 5 | FORMATO | N (Numeric) | 1 | 0 |  |
| 6 | OBSERVACIO | C (Character) | 80 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| NUMERO | `numero` | Candidate | ASC | `.NOT.DELETED()` |

## cabecera_pedidos.dbf

Formato: Visual FoxPro · 35 campos · largo de registro: 300 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FECHA | D (Date) | 8 |  |  |
| 2 | TIPO | C (Character) | 2 |  |  |
| 3 | LETRA | C (Character) | 1 |  |  |
| 4 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 5 | PREFIJO | N (Numeric) | 4 | 0 |  |
| 6 | comprobante (DBF: COMPROBANT) | N (Numeric) | 7 | 0 |  |
| 7 | CLIENTE | N (Numeric) | 5 | 0 |  |
| 8 | BRUTO | N (Numeric) | 11 | 2 |  |
| 9 | neto_grabado (DBF: NETO_GRABA) | N (Numeric) | 11 | 2 |  |
| 10 | neto_exento (DBF: NETO_EXENT) | N (Numeric) | 11 | 2 |  |
| 11 | IVA_1 | N (Numeric) | 11 | 2 |  |
| 12 | IVA_2 | N (Numeric) | 11 | 2 |  |
| 13 | LIBERADO | N (Numeric) | 11 | 2 |  |
| 14 | bonificacion (DBF: BONIFICACI) | N (Numeric) | 11 | 2 |  |
| 15 | NETO | N (Numeric) | 11 | 2 |  |
| 16 | porcentaje_descuento (DBF: PORCENTAJE) | N (Numeric) | 6 | 2 |  |
| 17 | ESTADO | C (Character) | 1 |  |  |
| 18 | observaciones (DBF: OBSERVACIO) | C (Character) | 60 |  |  |
| 19 | CANAL | C (Character) | 1 |  |  |
| 20 | TERMINAL | N (Numeric) | 2 | 0 |  |
| 21 | iva_cliente (DBF: IVA_CLIENT) | N (Numeric) | 1 | 0 |  |
| 22 | cuit_cliente (DBF: CUIT_CLIEN) | N (Numeric) | 11 | 0 |  |
| 23 | recargo_f_pago (DBF: RECARGO_F_) | N (Numeric) | 11 | 2 |  |
| 24 | IMPUESTO | N (Numeric) | 5 | 2 |  |
| 25 | RECIBO | N (Numeric) | 10 | 0 |  |
| 26 | MVSTK | N (Numeric) | 10 | 0 |  |
| 27 | COBRO | D (Date) | 8 |  |  |
| 28 | CIERRE | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 29 | numero_cierre (DBF: NUMERO_CIE) | N (Numeric) | 10 | 0 |  |
| 30 | VENDEDOR | C (Character) | 2 |  |  |
| 31 | FACTURADO | D (Date) | 8 |  |  |
| 32 | VALIDES | D (Date) | 8 |  |  |
| 33 | FORMA_PAGO | N (Numeric) | 1 | 0 |  |
| 34 | UNIDADES | N (Numeric) | 11 | 2 |  |
| 35 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `tipo+letra+STR(sucursal,2)+STR(comprobante,7)` | Regular | ASC |  |
| CLIENTE | `cliente` | Regular | ASC |  |
| FACTURADO | `facturado` | Regular | ASC |  |
| FECHA | `fecha` | Regular | ASC |  |
| PENDIENTES | `tipo+letra+STR(sucursal,2)+STR(comprobante,7)+STR(cliente,5)+DTOS(facturado)+DTOS(valides)` | Regular | ASC |  |

## caja.dbf

Formato: Visual FoxPro · 21 campos · largo de registro: 131 bytes · registros: 3340 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FECHA | D (Date) | 8 |  |  |
| 2 | TIPO | C (Character) | 2 |  |  |
| 3 | LETRA | C (Character) | 1 |  |  |
| 4 | CANAL | C (Character) | 1 |  |  |
| 5 | TERMINAL | N (Numeric) | 2 | 0 |  |
| 6 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 7 | PREFIJO | N (Numeric) | 4 | 0 |  |
| 8 | comprobante (DBF: COMPROBANT) | N (Numeric) | 7 | 0 |  |
| 9 | CLIENTE | N (Numeric) | 5 | 0 |  |
| 10 | P_FPAGO | C (Character) | 2 |  |  |
| 11 | P_CUOTAS | N (Numeric) | 2 | 0 |  |
| 12 | P_NRO | C (Character) | 20 |  |  |
| 13 | P_FECVTO | D (Date) | 8 |  |  |
| 14 | P_BANCO | N (Numeric) | 3 | 0 |  |
| 15 | P_COTIZ | N (Numeric) | 5 | 2 |  |
| 16 | P_IMPORTE | N (Numeric) | 9 | 2 |  |
| 17 | A_PAGAR | N (Numeric) | 9 | 2 |  |
| 18 | CIERRE | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 19 | numero_cierre (DBF: NUMERO_CIE) | N (Numeric) | 10 | 0 |  |
| 20 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 21 | FE_CLAVE | N (Numeric) | 14 | 0 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CHEQUES | `p_fpago+STR(cliente,5)+p_nro+STR(p_importe,9,2)` | Regular | ASC | `p_fpago="CH"` |
| CLAVE | `tipo+letra+STR(sucursal,2)+STR(comprobante,7)` | Regular | ASC |  |
| RECIBOS | `tipo+STR(comprobante,7)` | Regular | ASC |  |

## Cierres.DBF

Formato: Visual FoxPro · 2 campos · largo de registro: 33 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | C (Character) | 2 |  |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 30 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Regular | ASC |  |
| DESCRIP | `descripcion` | Regular | ASC |  |

## cla_accesos.dbf

Formato: Visual FoxPro · 4 campos · largo de registro: 43 bytes · registros: 7639 · base de datos: `claves.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NOMBRE | C (Character) | 20 |  |  |
| 2 | PROGRAMA | C (Character) | 20 |  |  |
| 3 | ACCESO | L (Logical) | 1 |  |  |
| 4 | NIVEL | N (Numeric) | 1 | 0 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `nombre+programa` | Regular | ASC |  |
| NOMBRE | `nombre` | Regular | ASC |  |
| PROGRAMA | `programa` | Regular | ASC |  |

## CLA_ERRORES.DBF

Formato: Visual FoxPro · 6 campos · largo de registro: 378 bytes · registros: 0 · base de datos: `claves.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FECHAHORA | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 2 | ERROR | N (Numeric) | 5 | 0 |  |
| 3 | MESSAGE | C (Character) | 200 |  |  |
| 4 | MESSAGE1 | C (Character) | 80 |  |  |
| 5 | LINENO | N (Numeric) | 4 | 0 |  |
| 6 | PROGRAM | C (Character) | 80 |  |  |

Índices: sin CDX estructural.

## cla_opciones.DBF

Formato: Visual FoxPro · 2 campos · largo de registro: 51 bytes · registros: 101 · base de datos: `claves.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | PROGRAMA | C (Character) | 20 |  |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 30 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| PROGRAMA | `programa` | Candidate | ASC |  |

## cla_usuarios.dbf

Formato: Visual FoxPro · 3 campos · largo de registro: 42 bytes · registros: 23 · base de datos: `claves.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NOMBRE | C (Character) | 20 |  |  |
| 2 | CLAVE | C (Character) | 20 |  |  |
| 3 | SUPERVISOR | L (Logical) | 1 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| USUARIO | `nombre+clave` | Candidate | ASC | `.NOT.DELETED()` |

## CLI.DBF

Formato: Visual FoxPro · 24 campos · largo de registro: 347 bytes · registros: 2471 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | N (Numeric) | 5 | 0 |  |
| 2 | RAZON_SOCI | C (Character) | 40 |  |  |
| 3 | DOMICILIO | C (Character) | 30 |  |  |
| 4 | LOCALIDAD | C (Character) | 20 |  |  |
| 5 | CODIGO_POS | N (Numeric) | 5 | 0 |  |
| 6 | PROVINCIA | C (Character) | 2 |  |  |
| 7 | IVA | N (Numeric) | 1 | 0 |  |
| 8 | CUIT | N (Numeric) | 11 | 0 |  |
| 9 | CREDITO | N (Numeric) | 12 | 2 |  |
| 10 | TIPO_CLIEN | C (Character) | 2 |  |  |
| 11 | TELEFONO | C (Character) | 40 |  |  |
| 12 | FAX | C (Character) | 40 |  |  |
| 13 | FECHA_ALTA | D (Date) | 8 |  |  |
| 14 | ALTA_X_SIS | L (Logical) | 1 |  |  |
| 15 | OBSERVACIO | C (Character) | 60 |  |  |
| 16 | ULTIMA_COM | D (Date) | 8 |  |  |
| 17 | CALCULA_IM | L (Logical) | 1 |  |  |
| 18 | VENDEDOR | C (Character) | 2 |  |  |
| 19 | LISTA_2_FI | L (Logical) | 1 |  |  |
| 20 | DESCUENTO | N (Numeric) | 5 | 2 |  |
| 21 | LISTA_HABI | C (Character) | 3 |  |  |
| 22 | FORMA_PAGO | N (Numeric) | 1 | 0 |  |
| 23 | NOMBRE_FAN | C (Character) | 40 |  |  |
| 24 | ACTUALIZAC | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices: sin CDX estructural.

## clientecab.dbf

Formato: Visual FoxPro · 75 campos · largo de registro: 229 bytes · registros: 17 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | PLATFORM | C (Character) | 8 |  |  |
| 2 | UNIQUEID | C (Character) | 10 |  |  |
| 3 | TIMESTAMP | N (Numeric) | 10 | 0 |  |
| 4 | OBJTYPE | N (Numeric) | 2 | 0 |  |
| 5 | OBJCODE | N (Numeric) | 3 | 0 |  |
| 6 | NAME | M (Memo) | 4 |  |  |
| 7 | EXPR | M (Memo) | 4 |  |  |
| 8 | VPOS | N (Numeric) | 9 | 3 |  |
| 9 | HPOS | N (Numeric) | 9 | 3 |  |
| 10 | HEIGHT | N (Numeric) | 9 | 3 |  |
| 11 | WIDTH | N (Numeric) | 9 | 3 |  |
| 12 | STYLE | M (Memo) | 4 |  |  |
| 13 | PICTURE | M (Memo) | 4 |  |  |
| 14 | ORDER | M (Memo) | 4 |  | binario (NOCPTRANS) |
| 15 | UNIQUE | L (Logical) | 1 |  |  |
| 16 | COMMENT | M (Memo) | 4 |  |  |
| 17 | ENVIRON | L (Logical) | 1 |  |  |
| 18 | BOXCHAR | C (Character) | 1 |  |  |
| 19 | FILLCHAR | C (Character) | 1 |  |  |
| 20 | TAG | M (Memo) | 4 |  |  |
| 21 | TAG2 | M (Memo) | 4 |  | binario (NOCPTRANS) |
| 22 | PENRED | N (Numeric) | 5 | 0 |  |
| 23 | PENGREEN | N (Numeric) | 5 | 0 |  |
| 24 | PENBLUE | N (Numeric) | 5 | 0 |  |
| 25 | FILLRED | N (Numeric) | 5 | 0 |  |
| 26 | FILLGREEN | N (Numeric) | 5 | 0 |  |
| 27 | FILLBLUE | N (Numeric) | 5 | 0 |  |
| 28 | PENSIZE | N (Numeric) | 5 | 0 |  |
| 29 | PENPAT | N (Numeric) | 5 | 0 |  |
| 30 | FILLPAT | N (Numeric) | 5 | 0 |  |
| 31 | FONTFACE | M (Memo) | 4 |  |  |
| 32 | FONTSTYLE | N (Numeric) | 3 | 0 |  |
| 33 | FONTSIZE | N (Numeric) | 3 | 0 |  |
| 34 | MODE | N (Numeric) | 3 | 0 |  |
| 35 | RULER | N (Numeric) | 1 | 0 |  |
| 36 | RULERLINES | N (Numeric) | 1 | 0 |  |
| 37 | GRID | L (Logical) | 1 |  |  |
| 38 | GRIDV | N (Numeric) | 2 | 0 |  |
| 39 | GRIDH | N (Numeric) | 2 | 0 |  |
| 40 | FLOAT | L (Logical) | 1 |  |  |
| 41 | STRETCH | L (Logical) | 1 |  |  |
| 42 | STRETCHTOP | L (Logical) | 1 |  |  |
| 43 | TOP | L (Logical) | 1 |  |  |
| 44 | BOTTOM | L (Logical) | 1 |  |  |
| 45 | SUPTYPE | N (Numeric) | 1 | 0 |  |
| 46 | SUPREST | N (Numeric) | 1 | 0 |  |
| 47 | NOREPEAT | L (Logical) | 1 |  |  |
| 48 | RESETRPT | N (Numeric) | 2 | 0 |  |
| 49 | PAGEBREAK | L (Logical) | 1 |  |  |
| 50 | COLBREAK | L (Logical) | 1 |  |  |
| 51 | RESETPAGE | L (Logical) | 1 |  |  |
| 52 | GENERAL | N (Numeric) | 3 | 0 |  |
| 53 | SPACING | N (Numeric) | 3 | 0 |  |
| 54 | DOUBLE | L (Logical) | 1 |  |  |
| 55 | SWAPHEADER | L (Logical) | 1 |  |  |
| 56 | SWAPFOOTER | L (Logical) | 1 |  |  |
| 57 | EJECTBEFOR | L (Logical) | 1 |  |  |
| 58 | EJECTAFTER | L (Logical) | 1 |  |  |
| 59 | PLAIN | L (Logical) | 1 |  |  |
| 60 | SUMMARY | L (Logical) | 1 |  |  |
| 61 | ADDALIAS | L (Logical) | 1 |  |  |
| 62 | OFFSET | N (Numeric) | 3 | 0 |  |
| 63 | TOPMARGIN | N (Numeric) | 3 | 0 |  |
| 64 | BOTMARGIN | N (Numeric) | 3 | 0 |  |
| 65 | TOTALTYPE | N (Numeric) | 2 | 0 |  |
| 66 | RESETTOTAL | N (Numeric) | 2 | 0 |  |
| 67 | RESOID | N (Numeric) | 3 | 0 |  |
| 68 | CURPOS | L (Logical) | 1 |  |  |
| 69 | SUPALWAYS | L (Logical) | 1 |  |  |
| 70 | SUPOVFLOW | L (Logical) | 1 |  |  |
| 71 | SUPRPCOL | N (Numeric) | 1 | 0 |  |
| 72 | SUPGROUP | N (Numeric) | 2 | 0 |  |
| 73 | SUPVALCHNG | L (Logical) | 1 |  |  |
| 74 | SUPEXPR | M (Memo) | 4 |  |  |
| 75 | USER | M (Memo) | 4 |  |  |

Índices: sin CDX estructural.

## clientes.dbf

Formato: Visual FoxPro · 33 campos · largo de registro: 469 bytes · registros: 531 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | N (Numeric) | 5 | 0 |  |
| 2 | razon_social (DBF: RAZON_SOCI) | C (Character) | 40 |  |  |
| 3 | DOMICILIO | C (Character) | 30 |  |  |
| 4 | LOCALIDAD | C (Character) | 20 |  |  |
| 5 | codigo_postal (DBF: CODIGO_POS) | N (Numeric) | 5 | 0 |  |
| 6 | PROVINCIA | C (Character) | 2 |  |  |
| 7 | IVA | N (Numeric) | 1 | 0 |  |
| 8 | CUIT | N (Numeric) | 11 | 0 |  |
| 9 | CREDITO | N (Numeric) | 12 | 2 |  |
| 10 | tipo_cliente (DBF: TIPO_CLIEN) | C (Character) | 2 |  |  |
| 11 | TELEFONO | C (Character) | 40 |  |  |
| 12 | FAX | C (Character) | 40 |  |  |
| 13 | FECHA_ALTA | D (Date) | 8 |  |  |
| 14 | alta_x_sistema (DBF: ALTA_X_SIS) | L (Logical) | 1 |  |  |
| 15 | observaciones (DBF: OBSERVACIO) | C (Character) | 60 |  |  |
| 16 | ultima_compra (DBF: ULTIMA_COM) | D (Date) | 8 |  |  |
| 17 | calcula_impuesto (DBF: CALCULA_IM) | L (Logical) | 1 |  |  |
| 18 | VENDEDOR | C (Character) | 2 |  |  |
| 19 | lista_2_fija (DBF: LISTA_2_FI) | L (Logical) | 1 |  |  |
| 20 | DESCUENTO | N (Numeric) | 5 | 2 |  |
| 21 | lista_habitual (DBF: LISTA_HABI) | C (Character) | 3 |  |  |
| 22 | FORMA_PAGO | N (Numeric) | 1 | 0 |  |
| 23 | nombre_fantasia (DBF: NOMBRE_FAN) | C (Character) | 40 |  |  |
| 24 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 25 | condicionpago (DBF: CONDICIONP) | C (Character) | 2 |  |  |
| 26 | consignacion (DBF: CONSIGNACI) | L (Logical) | 1 |  |  |
| 27 | TRANSPORTE | N (Numeric) | 3 | 0 |  |
| 28 | CTA_BRUTO | N (Numeric) | 4 | 0 |  |
| 29 | CTA_IVA1 | N (Numeric) | 4 | 0 |  |
| 30 | CTA_IVA2 | N (Numeric) | 4 | 0 |  |
| 31 | CTA_NETO | N (Numeric) | 4 | 0 |  |
| 32 | EMAILFC | C (Character) | 50 |  |  |
| 33 | EMAILLIS | C (Character) | 50 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Candidate | ASC | `.NOT.DELETED()` |
| PROVINCIA | `provincia` | Regular | ASC |  |
| TIPO_CLIEN | `tipo_cliente` | Regular | ASC |  |

## clientesca.dbf

Formato: FoxBASE+/dBase III · 12 campos · largo de registro: 302 bytes · registros: 1377 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NCLI | N (Numeric) | 5 | 0 |  |
| 2 | RA_ | C (Character) | 48 |  |  |
| 3 | DIR | C (Character) | 45 |  |  |
| 4 | LOC | C (Character) | 36 |  |  |
| 5 | CP | N (Numeric) | 5 | 0 |  |
| 6 | TEL | C (Character) | 35 |  |  |
| 7 | IVA | N (Numeric) | 3 | 0 |  |
| 8 | CUIT | C (Character) | 15 |  |  |
| 9 | VE | N (Numeric) | 3 | 0 |  |
| 10 | CONTACTO | C (Character) | 24 |  |  |
| 11 | OBS1 | C (Character) | 44 |  |  |
| 12 | OBS2 | C (Character) | 38 |  |  |

Índices: sin CDX estructural.

## clisf.dbf

Formato: FoxBASE+/dBase III · 17 campos · largo de registro: 348 bytes · registros: 1093 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NCLI | N (Numeric) | 7 | 0 |  |
| 2 | RAZSOC | C (Character) | 48 |  |  |
| 3 | DIR | C (Character) | 43 |  |  |
| 4 | LOC | C (Character) | 28 |  |  |
| 5 | CP | N (Numeric) | 4 | 0 |  |
| 6 | TEL | C (Character) | 40 |  |  |
| 7 | IVA | N (Numeric) | 4 | 0 |  |
| 8 | CUIT | C (Character) | 13 |  |  |
| 9 | NO | N (Numeric) | 3 | 0 |  |
| 10 | FP | N (Numeric) | 3 | 0 |  |
| 11 | VE | N (Numeric) | 3 | 0 |  |
| 12 | FECHA_ALTA | D (Date) | 8 |  |  |
| 13 | CONTACTO | C (Character) | 31 |  |  |
| 14 | OBS1 | C (Character) | 34 |  |  |
| 15 | OBS2 | C (Character) | 41 |  |  |
| 16 | ULT_COMP | N (Numeric) | 11 | 0 |  |
| 17 | FAX | C (Character) | 26 |  |  |

Índices: sin CDX estructural.

## cobra.dbf

Formato: Visual FoxPro · 20 campos · largo de registro: 189 bytes · registros: 30 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CLIENTE | N (Numeric) | 5 | 0 |  |
| 2 | FECHA | D (Date) | 8 |  |  |
| 3 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 4 | RECIBO | N (Numeric) | 7 | 0 |  |
| 5 | BRUTO | N (Numeric) | 16 | 2 |  |
| 6 | NETO | N (Numeric) | 16 | 2 |  |
| 7 | DTO_BRUTO | N (Numeric) | 16 | 2 |  |
| 8 | DTO_IVA | N (Numeric) | 16 | 2 |  |
| 9 | COD_RT1 | N (Numeric) | 2 | 0 |  |
| 10 | IMP_RT1 | N (Numeric) | 16 | 2 |  |
| 11 | COD_RT2 | N (Numeric) | 2 | 0 |  |
| 12 | IMP_RT2 | N (Numeric) | 16 | 2 |  |
| 13 | COD_RT3 | N (Numeric) | 2 | 0 |  |
| 14 | IMP_RT3 | N (Numeric) | 16 | 2 |  |
| 15 | COD_RT4 | N (Numeric) | 2 | 0 |  |
| 16 | IMP_RT4 | N (Numeric) | 16 | 2 |  |
| 17 | COD_RT5 | N (Numeric) | 2 | 0 |  |
| 18 | IMP_RT5 | N (Numeric) | 16 | 2 |  |
| 19 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 20 | ID_INGVS | I (Integer) | 4 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| FECHA | `DTOS(fecha)+STR(sucursal,2)+STR(recibo,7)` | Regular | ASC |  |
| RECIBO | `STR(sucursal,2)+STR(recibo,7)` | Regular | ASC |  |

## COLORES.DBF

Formato: Visual FoxPro · 2 campos · largo de registro: 34 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | C (Character) | 3 |  |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 30 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Regular | ASC |  |
| DESCRIP | `descripcion` | Regular | ASC |  |

## condicionpago.DBF

Formato: Visual FoxPro · 2 campos · largo de registro: 33 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | C (Character) | 2 |  |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 30 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Candidate | ASC |  |

## consignacion.dbf

Formato: Visual FoxPro · 17 campos · largo de registro: 123 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FECHA | D (Date) | 8 |  |  |
| 2 | TIPO | C (Character) | 2 |  |  |
| 3 | SUCURSAL | N (Numeric) | 4 | 0 |  |
| 4 | NUMERO | N (Numeric) | 9 | 0 |  |
| 5 | CLIENTE | N (Numeric) | 5 | 0 |  |
| 6 | SIGNO | C (Character) | 1 |  |  |
| 7 | LETRA | C (Character) | 1 |  |  |
| 8 | ARTICULO | C (Character) | 15 |  |  |
| 9 | UNIDADES | N (Numeric) | 12 | 2 |  |
| 10 | KILOS | N (Numeric) | 12 | 3 |  |
| 11 | PRECIO | N (Numeric) | 11 | 4 |  |
| 12 | porcentaje_descuento (DBF: PORCENTAJE) | N (Numeric) | 6 | 2 |  |
| 13 | TOTAL_ITEM | N (Numeric) | 11 | 4 |  |
| 14 | GRABADO | L (Logical) | 1 |  |  |
| 15 | POR_IVA | N (Numeric) | 5 | 2 |  |
| 16 | COSTO | N (Numeric) | 11 | 4 |  |
| 17 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| ARTICULO | `articulo+DTOS(fecha)+tipo+STR(numero,9)` | Regular | ASC |  |
| CLAVE | `tipo+letra+STR(sucursal,2)+STR(numero,7)+articulo` | Regular | ASC |  |
| CLIENTE | `STR(cliente,5)+DTOS(fecha)+tipo+STR(sucursal,2)+STR(numero,7)+articulo` | Regular | ASC |  |

## Copia de mvstk_items.dbf

Formato: Visual FoxPro · 4 campos · largo de registro: 42 bytes · registros: 117239 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NUMERO_MVS | N (Numeric) | 10 | 0 |  |
| 2 | ARTICULO | N (Numeric) | 7 | 0 |  |
| 3 | CANTIDAD_U | N (Numeric) | 12 | 2 |  |
| 4 | CANTIDAD_K | N (Numeric) | 12 | 3 |  |

Índices: el encabezado indica CDX estructural, pero el archivo `.cdx` no está en la carpeta.

## Copia de REMITOS_CAB.DBF

Formato: Visual FoxPro · 34 campos · largo de registro: 292 bytes · registros: 38 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FECHA | D (Date) | 8 |  |  |
| 2 | TIPO | C (Character) | 2 |  |  |
| 3 | LETRA | C (Character) | 1 |  |  |
| 4 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 5 | PREFIJO | N (Numeric) | 4 | 0 |  |
| 6 | COMPROBANT | N (Numeric) | 7 | 0 |  |
| 7 | CLIENTE | N (Numeric) | 5 | 0 |  |
| 8 | BRUTO | N (Numeric) | 11 | 2 |  |
| 9 | NETO_GRABA | N (Numeric) | 11 | 2 |  |
| 10 | NETO_EXENT | N (Numeric) | 11 | 2 |  |
| 11 | IVA_1 | N (Numeric) | 11 | 2 |  |
| 12 | IVA_2 | N (Numeric) | 11 | 2 |  |
| 13 | LIBERADO | N (Numeric) | 11 | 2 |  |
| 14 | BONIFICACI | N (Numeric) | 11 | 2 |  |
| 15 | NETO | N (Numeric) | 11 | 2 |  |
| 16 | PORCENTAJE | N (Numeric) | 6 | 2 |  |
| 17 | ESTADO | C (Character) | 1 |  |  |
| 18 | OBSERVACIO | C (Character) | 60 |  |  |
| 19 | CANAL | C (Character) | 1 |  |  |
| 20 | TERMINAL | N (Numeric) | 2 | 0 |  |
| 21 | IVA_CLIENT | N (Numeric) | 1 | 0 |  |
| 22 | CUIT_CLIEN | N (Numeric) | 11 | 0 |  |
| 23 | RECARGO_F_ | N (Numeric) | 11 | 2 |  |
| 24 | IMPUESTO | N (Numeric) | 5 | 2 |  |
| 25 | RECIBO | N (Numeric) | 10 | 0 |  |
| 26 | MVSTK | N (Numeric) | 10 | 0 |  |
| 27 | COBRO | D (Date) | 8 |  |  |
| 28 | CIERRE | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 29 | NUMERO_CIE | N (Numeric) | 10 | 0 |  |
| 30 | VENDEDOR | C (Character) | 2 |  |  |
| 31 | FACTURADO | D (Date) | 8 |  |  |
| 32 | VALIDES | D (Date) | 8 |  |  |
| 33 | FORMA_PAGO | N (Numeric) | 1 | 0 |  |
| 34 | UNIDADES | N (Numeric) | 11 | 2 |  |

Índices: el encabezado indica CDX estructural, pero el archivo `.cdx` no está en la carpeta.

## CORTES_DET.DBF

Formato: Visual FoxPro · 6 campos · largo de registro: 52 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NUMERO | N (Numeric) | 8 | 0 |  |
| 2 | ORDEN_NRO | N (Numeric) | 8 | 0 |  |
| 3 | FECHA | D (Date) | 8 |  |  |
| 4 | ARTICULO | C (Character) | 15 |  |  |
| 5 | UNIDADES | N (Numeric) | 4 | 0 |  |
| 6 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| NUMERO | `numero` | Regular | ASC | `.NOT.DELETED()` |
| NUM_ART | `STR(orden_nro,8)+articulo` | Regular | ASC | `.NOT.DELETED()` |

## cotizacion_dolar.DBF

Formato: Visual FoxPro · 4 campos · largo de registro: 73 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FECHA | D (Date) | 8 |  |  |
| 2 | IMPORTE | N (Numeric) | 6 | 2 |  |
| 3 | observaciones (DBF: OBSERVACIO) | C (Character) | 50 |  |  |
| 4 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| FECHA | `DTOS(fecha)` | Candidate | DESC | `.NOT.DELETED()` |

## cs_articulos.DBF

Formato: Visual FoxPro · 21 campos · largo de registro: 165 bytes · registros: 0 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | COD_ART | N (Numeric) | 5 | 0 |  |
| 2 | DESCRIPCIO | C (Character) | 40 |  |  |
| 3 | LINEA_ART | N (Numeric) | 3 | 0 |  |
| 4 | CLASE_ART | N (Numeric) | 3 | 0 |  |
| 5 | UN_VENTA | N (Numeric) | 2 | 0 |  |
| 6 | PROVEEDOR | N (Numeric) | 5 | 0 |  |
| 7 | COSTO_PROV | N (Numeric) | 12 | 2 |  |
| 8 | MONEDA | C (Character) | 1 |  |  |
| 9 | CONVERSION | N (Numeric) | 12 | 2 |  |
| 10 | IVA | C (Character) | 1 |  |  |
| 11 | IMPUEST_IN | C (Character) | 1 |  |  |
| 12 | LISTA_PR1 | N (Numeric) | 14 | 2 |  |
| 13 | LISTA_PR2 | N (Numeric) | 14 | 2 |  |
| 14 | STOCK_MIN | N (Numeric) | 10 | 0 |  |
| 15 | STOCK_MAX | N (Numeric) | 10 | 0 |  |
| 16 | EXISTENCIA | N (Numeric) | 10 | 0 |  |
| 17 | COSTO_EXIS | N (Numeric) | 14 | 2 |  |
| 18 | DIA | N (Numeric) | 2 | 0 |  |
| 19 | MES | N (Numeric) | 2 | 0 |  |
| 20 | ANIO | N (Numeric) | 2 | 0 |  |
| 21 | BAJO_PEDID | N (Numeric) | 1 | 0 |  |

Índices: sin CDX estructural.

## cs_clientes.DBF

Formato: Visual FoxPro · 20 campos · largo de registro: 174 bytes · registros: 0 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NRO_CLIENT | N (Numeric) | 5 | 0 |  |
| 2 | RAZON_SOCI | C (Character) | 35 |  |  |
| 3 | DIRECCION | C (Character) | 25 |  |  |
| 4 | COD_POST | N (Numeric) | 4 | 0 |  |
| 5 | LOCALIDAD | C (Character) | 15 |  |  |
| 6 | TELEFONO | C (Character) | 20 |  |  |
| 7 | SIT_IVA | N (Numeric) | 2 | 0 |  |
| 8 | CUIT | C (Character) | 15 |  |  |
| 9 | CTA_CTE | C (Character) | 1 |  |  |
| 10 | COND_PAGO | N (Numeric) | 2 | 0 |  |
| 11 | DESCUENTO | N (Numeric) | 2 | 0 |  |
| 12 | CREDITO | N (Numeric) | 11 | 0 |  |
| 13 | SIT_CREDIT | N (Numeric) | 2 | 0 |  |
| 14 | DIA_ULTCPR | N (Numeric) | 2 | 0 |  |
| 15 | MES_ULTCPR | N (Numeric) | 2 | 0 |  |
| 16 | ANIO_ULTCP | N (Numeric) | 2 | 0 |  |
| 17 | COMPRAS_AC | N (Numeric) | 11 | 0 |  |
| 18 | SALDO | N (Numeric) | 13 | 2 |  |
| 19 | CANT_FACTU | N (Numeric) | 2 | 0 |  |
| 20 | CANT_REMIT | N (Numeric) | 2 | 0 |  |

Índices: sin CDX estructural.

## ctact.dbf

Formato: Visual FoxPro · 16 campos · largo de registro: 112 bytes · registros: 3489 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CLIENTE | N (Numeric) | 5 | 0 |  |
| 2 | FECHA | D (Date) | 8 |  |  |
| 3 | TIPO | C (Character) | 2 |  |  |
| 4 | TICO | N (Numeric) | 1 | 0 |  |
| 5 | LETRA | C (Character) | 1 |  |  |
| 6 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 7 | PREFIJO | N (Numeric) | 4 | 0 |  |
| 8 | NUMERO | N (Numeric) | 8 | 0 |  |
| 9 | NETO | N (Numeric) | 10 | 2 |  |
| 10 | DESCUENTO | N (Numeric) | 10 | 2 |  |
| 11 | SALDO | N (Numeric) | 10 | 2 |  |
| 12 | vencimiento (DBF: VENCIMIENT) | D (Date) | 8 |  |  |
| 13 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 14 | valor_dolar (DBF: VALOR_DOLA) | N (Numeric) | 12 | 5 |  |
| 15 | fecha_asiento (DBF: FECHA_ASIE) | D (Date) | 8 |  |  |
| 16 | FE_CLAVE | N (Numeric) | 14 | 0 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `tipo+letra+STR(sucursal,2)+STR(numero,7)` | Regular | ASC |  |
| CLIENTE | `STR(cliente,5)+DTOS(fecha)+tipo+STR(sucursal,2)+STR(numero,7)` | Regular | ASC |  |
| PAGO | `STR(cliente,5)+tipo+letra+STR(sucursal,2)+STR(numero,7)` | Regular | ASC |  |
| RECIBO | `tipo+STR(sucursal,2)+STR(numero,7)` | Regular | ASC |  |

## cur_pos.DBF

Formato: Visual FoxPro · 10 campos · largo de registro: 51 bytes · registros: 3 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NPRO1 | N (Numeric) | 5 | 0 |  |
| 2 | NPRO2 | N (Numeric) | 5 | 0 |  |
| 3 | NPRO3 | N (Numeric) | 5 | 0 |  |
| 4 | NPRO4 | N (Numeric) | 5 | 0 |  |
| 5 | NPRO5 | N (Numeric) | 5 | 0 |  |
| 6 | NPRO6 | N (Numeric) | 5 | 0 |  |
| 7 | NPRO7 | N (Numeric) | 5 | 0 |  |
| 8 | NPRO8 | N (Numeric) | 5 | 0 |  |
| 9 | NPRO9 | N (Numeric) | 5 | 0 |  |
| 10 | NPRO10 | N (Numeric) | 5 | 0 |  |

Índices: sin CDX estructural.

## descuentos.DBF

Formato: Visual FoxPro · 4 campos · largo de registro: 26 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CLIENTE | N (Numeric) | 5 | 0 |  |
| 2 | ARTICULO | N (Numeric) | 7 | 0 |  |
| 3 | DESCUENTO | N (Numeric) | 5 | 2 |  |
| 4 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `STR(cliente,5)+STR(articulo,7)` | Candidate | ASC |  |

## DET.DBF

Formato: Visual FoxPro · 4 campos · largo de registro: 38 bytes · registros: 906 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | ARTICULOS | C (Character) | 15 |  |  |
| 2 | NUM_LISTA | N (Numeric) | 3 | 0 |  |
| 3 | VALOR | N (Numeric) | 11 | 4 |  |
| 4 | ACTUALIZAC | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices: sin CDX estructural.

## domi.DBF

Formato: Visual FoxPro · 1 campos · largo de registro: 31 bytes · registros: 4860 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | DOMICILIO | C (Character) | 30 |  |  |

Índices: sin CDX estructural.

## EMPRESAS.DBF

Formato: Visual FoxPro · 3 campos · largo de registro: 63 bytes · registros: 3 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | N (Numeric) | 2 | 0 |  |
| 2 | DESCRIPCIO | C (Character) | 30 |  |  |
| 3 | DIRECCION | C (Character) | 30 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Candidate | ASC | `.NOT.DELETED()` |

## envio_taller_cab.dbf

Formato: Visual FoxPro · 6 campos · largo de registro: 35 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NUMERO | N (Numeric) | 10 | 0 |  |
| 2 | FECHA | D (Date) | 8 |  |  |
| 3 | TALLER | C (Character) | 3 |  |  |
| 4 | ESTADO | C (Character) | 1 |  |  |
| 5 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 6 | observaciones (DBF: OBSERVACIO) | M (Memo) | 4 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| NUMERO | `numero` | Candidate | ASC | `.NOT.DELETED()` |

## envio_taller_det.dbf

Formato: Visual FoxPro · 8 campos · largo de registro: 77 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NUMERO | N (Numeric) | 10 | 0 |  |
| 2 | ESTADO | C (Character) | 1 |  |  |
| 3 | ARTICULO | C (Character) | 15 |  |  |
| 4 | UNIDADES | N (Numeric) | 11 | 2 |  |
| 5 | SALDO | N (Numeric) | 11 | 2 |  |
| 6 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 7 | nro_orden_de_corte (DBF: NRO_ORDEN_) | N (Numeric) | 10 | 0 |  |
| 8 | nro_de_productos_en_procesos (DBF: NRO_DE_PRO) | N (Numeric) | 10 | 0 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| ARTICULO | `STR(numero)+articulo` | Regular | ASC | `.NOT.DELETED()` |
| NUMERO | `numero` | Regular | ASC | `.NOT.DELETED()` |
| O_DE_CORTE | `STR(numero)+articulo+STR(nro_orden_de_corte)` | Regular | ASC | `.NOT.DELETED()` |

## Equivalencias_afip.DBF

Formato: Visual FoxPro · 4 campos · largo de registro: 57 bytes · registros: 121 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | TABLA | C (Character) | 30 |  |  |
| 2 | CODIGOAFIP | C (Character) | 3 |  |  |
| 3 | CODIGOOYS | C (Character) | 3 |  |  |
| 4 | descripcion (DBF: DESCRIPCIO) | C (Character) | 20 |  |  |

Índices: sin CDX estructural.

## ERRORES.DBF

Formato: Visual FoxPro · 10 campos · largo de registro: 403 bytes · registros: 178 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FECHAHORA | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 2 | ERROR | N (Numeric) | 5 | 0 |  |
| 3 | MESSAGE | C (Character) | 200 |  |  |
| 4 | MESSAGE1 | C (Character) | 80 |  |  |
| 5 | LINENO | N (Numeric) | 4 | 0 |  |
| 6 | PROGRAM | C (Character) | 80 |  |  |
| 7 | USUARIO | C (Character) | 20 |  |  |
| 8 | CANAL | C (Character) | 1 |  |  |
| 9 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 10 | TERMINAL | N (Numeric) | 2 | 0 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| FECHAHORA | `fechahora` | Regular | DESC |  |

## estadistica.dbf

Formato: Visual FoxPro · 27 campos · largo de registro: 208 bytes · registros: 3843 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FECHA | D (Date) | 8 |  |  |
| 2 | TIPO | C (Character) | 2 |  |  |
| 3 | LETRA | C (Character) | 1 |  |  |
| 4 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 5 | PREFIJO | N (Numeric) | 4 | 0 |  |
| 6 | comprobante (DBF: COMPROBANT) | N (Numeric) | 7 | 0 |  |
| 7 | PEDIDO | N (Numeric) | 7 | 0 |  |
| 8 | ARTICULO | C (Character) | 15 |  |  |
| 9 | codigo_de_barra (DBF: CODIGO_DE_) | N (Numeric) | 13 | 0 |  |
| 10 | BULTOS | N (Numeric) | 3 | 0 |  |
| 11 | UNIDADES | N (Numeric) | 11 | 2 |  |
| 12 | KILOS | N (Numeric) | 11 | 3 |  |
| 13 | LISTA | C (Character) | 3 |  |  |
| 14 | PRECIO | N (Numeric) | 11 | 4 |  |
| 15 | precio_siniva (DBF: PRECIO_SIN) | N (Numeric) | 11 | 4 |  |
| 16 | porcentaje_descuento (DBF: PORCENTAJE) | N (Numeric) | 6 | 2 |  |
| 17 | TOTAL_ITEM | N (Numeric) | 11 | 4 |  |
| 18 | total_siniva (DBF: TOTAL_SINI) | N (Numeric) | 11 | 4 |  |
| 19 | CLIENTE | N (Numeric) | 5 | 0 |  |
| 20 | GRABADO | L (Logical) | 1 |  |  |
| 21 | POR_IVA | N (Numeric) | 5 | 2 |  |
| 22 | COSTO | N (Numeric) | 11 | 4 |  |
| 23 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 24 | observaciones (DBF: OBSERVACIO) | M (Memo) | 4 |  |  |
| 25 | MERMA | N (Numeric) | 11 | 2 |  |
| 26 | unidades_ori (DBF: UNIDADES_O) | N (Numeric) | 11 | 2 |  |
| 27 | FE_CLAVE | N (Numeric) | 14 | 0 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| ART | `tipo+letra+STR(sucursal,2)+STR(comprobante,7)+articulo` | Regular | ASC |  |
| CLAVE | `tipo+letra+STR(sucursal,2)+STR(comprobante,7)` | Regular | ASC |  |

## estado_de_articulos.DBF

Formato: Visual FoxPro · 3 campos · largo de registro: 41 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | C (Character) | 2 |  |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 30 |  |  |
| 3 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Candidate | ASC | `.NOT.DELETED()` |

## FABRIPACK_ref.DBF

Formato: Visual FoxPro · 21 campos · largo de registro: 112 bytes · registros: 534 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | UNIQUEID | C (Character) | 10 |  |  |
| 2 | SETID | C (Character) | 10 |  |  |
| 3 | REFID | C (Character) | 10 |  |  |
| 4 | REFTYPE | C (Character) | 1 |  |  |
| 5 | FINDTYPE | C (Character) | 1 |  |  |
| 6 | FILEID | C (Character) | 10 |  |  |
| 7 | SYMBOL | M (Memo) | 4 |  |  |
| 8 | CLASSNAME | M (Memo) | 4 |  |  |
| 9 | PROCNAME | M (Memo) | 4 |  |  |
| 10 | PROCLINENO | I (Integer) | 4 |  | binario (NOCPTRANS) |
| 11 | LINENO | I (Integer) | 4 |  | binario (NOCPTRANS) |
| 12 | COLPOS | I (Integer) | 4 |  | binario (NOCPTRANS) |
| 13 | MATCHLEN | I (Integer) | 4 |  | binario (NOCPTRANS) |
| 14 | ABSTRACT | M (Memo) | 4 |  |  |
| 15 | RECORDID | C (Character) | 10 |  |  |
| 16 | UPDFIELD | C (Character) | 15 |  |  |
| 17 | CHECKED | L (Logical) | 1 |  | admite NULL |
| 18 | NOREPLACE | L (Logical) | 1 |  |  |
| 19 | TIMESTAMP | T (DateTime) | 8 |  | admite NULL, binario (NOCPTRANS) |
| 20 | INACTIVE | L (Logical) | 1 |  |  |
| 21 | _NullFlags | 0 ((sistema: _NullFlags)) | 1 |  | binario (NOCPTRANS), columna de sistema |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CHECKED | `checked` | Regular | ASC |  |
| FILEID | `fileid` | Regular | ASC |  |
| INACTIVE | `inactive` | Regular | ASC |  |
| REFID | `refid` | Regular | ASC |  |
| REFTYPE | `reftype` | Regular | ASC |  |
| SETID | `setid` | Regular | ASC |  |
| UNIQUEID | `uniqueid` | Regular | ASC |  |

## fe_Actualizacion.dbf

Formato: Visual FoxPro · 6 campos · largo de registro: 391 bytes · registros: 6 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | TABLA | C (Character) | 50 |  |  |
| 2 | camponumero (DBF: CAMPONUMER) | C (Character) | 20 |  |  |
| 3 | CAMPOFECHA | C (Character) | 20 |  |  |
| 4 | CLAVETABLA | C (Character) | 100 |  |  |
| 5 | CLAVESUBDI | C (Character) | 100 |  |  |
| 6 | BASE | C (Character) | 100 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| TABLA | `tabla` | Candidate | ASC | `.NOT.DELETED()` |

## fe_Configuracion.DBF

Formato: Visual FoxPro (Varchar/Varbinary/Blob) · 4 campos · largo de registro: 206 bytes · registros: 1 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CON_IID | I (Integer) | 4 |  | binario (NOCPTRANS), autoincremental |
| 2 | CON_CEMPRE | C (Character) | 100 |  |  |
| 3 | CON_CCUIT | V (Varchar) | 100 |  | admite NULL |
| 4 | _NullFlags | 0 ((sistema: _NullFlags)) | 1 |  | binario (NOCPTRANS), columna de sistema |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CON_IID | `con_iid` | Candidate | ASC |  |

## fe_Parametros.dbf

Formato: Visual FoxPro (Varchar/Varbinary/Blob) · 5 campos · largo de registro: 406 bytes · registros: 74 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | PAR_IID | I (Integer) | 4 |  | binario (NOCPTRANS), autoincremental |
| 2 | PAR_CCODIG | C (Character) | 100 |  |  |
| 3 | PAR_CCAMPO | V (Varchar) | 100 |  | admite NULL |
| 4 | PAR_CCAMP2 | V (Varchar) | 200 |  | admite NULL |
| 5 | _NullFlags | 0 ((sistema: _NullFlags)) | 1 |  | binario (NOCPTRANS), columna de sistema |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| PAR_IID | `par_iid` | Candidate | ASC |  |

## forma_de_pagos.dbf

Formato: Visual FoxPro · 6 campos · largo de registro: 52 bytes · registros: 28 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | C (Character) | 2 |  |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 30 |  |  |
| 3 | TIPO | N (Numeric) | 1 | 0 |  |
| 4 | RECARGO | N (Numeric) | 6 | 2 |  |
| 5 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 6 | cta_contable (DBF: CTA_CONTAB) | N (Numeric) | 4 | 0 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Candidate | ASC | `.NOT.DELETED()` |

## formulas_articulo.dbf

Formato: Visual FoxPro · 6 campos · largo de registro: 42 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | ARTICULO | C (Character) | 15 |  |  |
| 2 | TIPO_MP | C (Character) | 2 |  |  |
| 3 | RUBRO_MP | C (Character) | 3 |  |  |
| 4 | CODIGO | C (Character) | 4 |  |  |
| 5 | COLOR | C (Character) | 3 |  |  |
| 6 | CANTIDAD | N (Numeric) | 14 | 4 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| ARTICULO | `articulo` | Regular | ASC | `.NOT.DELETED()` |
| CLAVE | `articulo+tipo_mp+rubro_mp+codigo+color` | Regular | ASC | `.NOT.DELETED()` |

## formulas_base.DBF

Formato: Visual FoxPro · 6 campos · largo de registro: 31 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | MODELO | C (Character) | 4 |  |  |
| 2 | TIPO_MP | C (Character) | 2 |  |  |
| 3 | RUBRO_MP | C (Character) | 3 |  |  |
| 4 | CODIGO | C (Character) | 4 |  |  |
| 5 | COLOR | C (Character) | 3 |  |  |
| 6 | CANTIDAD | N (Numeric) | 14 | 4 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `modelo+tipo_mp+rubro_mp+codigo+color` | Candidate | ASC | `.NOT.DELETED()` |
| MODELO | `modelo` | Regular | ASC | `.NOT.DELETED()` |

## FOXUSER.DBF

Formato: Visual FoxPro · 7 campos · largo de registro: 48 bytes · registros: 4 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | TYPE | C (Character) | 12 |  |  |
| 2 | ID | C (Character) | 12 |  |  |
| 3 | NAME | M (Memo) | 4 |  |  |
| 4 | READONLY | L (Logical) | 1 |  |  |
| 5 | CKVAL | N (Numeric) | 6 | 0 |  |
| 6 | DATA | M (Memo) | 4 |  |  |
| 7 | UPDATED | D (Date) | 8 |  |  |

Índices: sin CDX estructural.

## gig.DBF

Formato: Visual FoxPro · 24 campos · largo de registro: 347 bytes · registros: 2471 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | N (Numeric) | 5 | 0 |  |
| 2 | RAZON_SOCI | C (Character) | 40 |  |  |
| 3 | DOMICILIO | C (Character) | 30 |  |  |
| 4 | LOCALIDAD | C (Character) | 20 |  |  |
| 5 | CODIGO_POS | N (Numeric) | 5 | 0 |  |
| 6 | PROVINCIA | C (Character) | 2 |  |  |
| 7 | IVA | N (Numeric) | 1 | 0 |  |
| 8 | CUIT | N (Numeric) | 11 | 0 |  |
| 9 | CREDITO | N (Numeric) | 12 | 2 |  |
| 10 | TIPO_CLIEN | C (Character) | 2 |  |  |
| 11 | TELEFONO | C (Character) | 40 |  |  |
| 12 | FAX | C (Character) | 40 |  |  |
| 13 | FECHA_ALTA | D (Date) | 8 |  |  |
| 14 | ALTA_X_SIS | L (Logical) | 1 |  |  |
| 15 | OBSERVACIO | C (Character) | 60 |  |  |
| 16 | ULTIMA_COM | D (Date) | 8 |  |  |
| 17 | CALCULA_IM | L (Logical) | 1 |  |  |
| 18 | VENDEDOR | C (Character) | 2 |  |  |
| 19 | LISTA_2_FI | L (Logical) | 1 |  |  |
| 20 | DESCUENTO | N (Numeric) | 5 | 2 |  |
| 21 | LISTA_HABI | C (Character) | 3 |  |  |
| 22 | FORMA_PAGO | N (Numeric) | 1 | 0 |  |
| 23 | NOMBRE_FAN | C (Character) | 40 |  |  |
| 24 | ACTUALIZAC | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices: sin CDX estructural.

## h_caja.DBF

Formato: Visual FoxPro · 19 campos · largo de registro: 109 bytes · registros: 0 · base de datos: `..\datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FECHA | D (Date) | 8 |  |  |
| 2 | TIPO | C (Character) | 2 |  |  |
| 3 | LETRA | C (Character) | 1 |  |  |
| 4 | CANAL | C (Character) | 1 |  |  |
| 5 | TERMINAL | N (Numeric) | 2 | 0 |  |
| 6 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 7 | PREFIJO | N (Numeric) | 4 | 0 |  |
| 8 | COMPROBANT | N (Numeric) | 7 | 0 |  |
| 9 | CLIENTE | N (Numeric) | 5 | 0 |  |
| 10 | P_FPAGO | C (Character) | 2 |  |  |
| 11 | P_CUOTAS | N (Numeric) | 2 | 0 |  |
| 12 | P_NRO | C (Character) | 20 |  |  |
| 13 | P_FECVTO | D (Date) | 8 |  |  |
| 14 | P_BANCO | N (Numeric) | 3 | 0 |  |
| 15 | P_COTIZ | N (Numeric) | 5 | 2 |  |
| 16 | P_IMPORTE | N (Numeric) | 9 | 2 |  |
| 17 | A_PAGAR | N (Numeric) | 9 | 2 |  |
| 18 | CIERRE | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 19 | NUMERO_CIE | N (Numeric) | 10 | 0 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CHEQUES | `p_fpago+STR(cliente,5)+p_nro+STR(p_importe,9,2)` | Regular | ASC | `p_fpago="CH"` |
| CLAVE | `tipo+letra+STR(sucursal,2)+STR(comprobante,7)` | Regular | ASC |  |

## h_estadistica.DBF

Formato: Visual FoxPro · 16 campos · largo de registro: 104 bytes · registros: 0 · base de datos: `..\datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FECHA | D (Date) | 8 |  |  |
| 2 | TIPO | C (Character) | 2 |  |  |
| 3 | LETRA | C (Character) | 1 |  |  |
| 4 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 5 | PREFIJO | N (Numeric) | 4 | 0 |  |
| 6 | COMPROBANT | N (Numeric) | 7 | 0 |  |
| 7 | ARTICULO | N (Numeric) | 7 | 0 |  |
| 8 | CODIGO_DE_ | N (Numeric) | 13 | 0 |  |
| 9 | BULTOS | N (Numeric) | 3 | 0 |  |
| 10 | UNIDADES | N (Numeric) | 11 | 2 |  |
| 11 | KILOS | N (Numeric) | 11 | 3 |  |
| 12 | LISTA | C (Character) | 1 |  |  |
| 13 | PRECIO | N (Numeric) | 11 | 2 |  |
| 14 | PORCENTAJE | N (Numeric) | 6 | 2 |  |
| 15 | TOTAL_ITEM | N (Numeric) | 11 | 2 |  |
| 16 | CLIENTE | N (Numeric) | 5 | 0 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `tipo+letra+STR(sucursal,2)+STR(comprobante,7)` | Regular | ASC |  |

## h_subdiario.DBF

Formato: Visual FoxPro · 30 campos · largo de registro: 264 bytes · registros: 0 · base de datos: `..\datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FECHA | D (Date) | 8 |  |  |
| 2 | TIPO | C (Character) | 2 |  |  |
| 3 | LETRA | C (Character) | 1 |  |  |
| 4 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 5 | PREFIJO | N (Numeric) | 4 | 0 |  |
| 6 | COMPROBANT | N (Numeric) | 7 | 0 |  |
| 7 | CLIENTE | N (Numeric) | 5 | 0 |  |
| 8 | BRUTO | N (Numeric) | 11 | 2 |  |
| 9 | NETO_GRABA | N (Numeric) | 11 | 2 |  |
| 10 | NETO_EXENT | N (Numeric) | 11 | 2 |  |
| 11 | IVA_1 | N (Numeric) | 11 | 2 |  |
| 12 | IVA_2 | N (Numeric) | 11 | 2 |  |
| 13 | LIBERADO | N (Numeric) | 11 | 2 |  |
| 14 | BONIFICACI | N (Numeric) | 11 | 2 |  |
| 15 | NETO | N (Numeric) | 11 | 2 |  |
| 16 | PORCENTAJE | N (Numeric) | 6 | 2 |  |
| 17 | ESTADO | C (Character) | 1 |  |  |
| 18 | OBSERVACIO | C (Character) | 60 |  |  |
| 19 | CANAL | C (Character) | 1 |  |  |
| 20 | TERMINAL | N (Numeric) | 2 | 0 |  |
| 21 | IVA_CLIENT | N (Numeric) | 1 | 0 |  |
| 22 | CUIT_CLIEN | N (Numeric) | 11 | 0 |  |
| 23 | RECARGO_F_ | N (Numeric) | 11 | 2 |  |
| 24 | IMPUESTO | N (Numeric) | 5 | 2 |  |
| 25 | RECIBO | N (Numeric) | 10 | 0 |  |
| 26 | MVSTK | N (Numeric) | 10 | 0 |  |
| 27 | COBRO | D (Date) | 8 |  |  |
| 28 | CIERRE | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 29 | NUMERO_CIE | N (Numeric) | 10 | 0 |  |
| 30 | VENDEDOR | C (Character) | 2 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `tipo+letra+STR(sucursal,2)+STR(comprobante,7)` | Regular | ASC |  |

## Historia/h_caja.DBF

Formato: Visual FoxPro · 20 campos · largo de registro: 117 bytes · registros: 0 · base de datos: `..\datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FECHA | D (Date) | 8 |  |  |
| 2 | TIPO | C (Character) | 2 |  |  |
| 3 | LETRA | C (Character) | 1 |  |  |
| 4 | CANAL | C (Character) | 1 |  |  |
| 5 | TERMINAL | N (Numeric) | 2 | 0 |  |
| 6 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 7 | PREFIJO | N (Numeric) | 4 | 0 |  |
| 8 | COMPROBANT | N (Numeric) | 7 | 0 |  |
| 9 | CLIENTE | N (Numeric) | 5 | 0 |  |
| 10 | P_FPAGO | C (Character) | 2 |  |  |
| 11 | P_CUOTAS | N (Numeric) | 2 | 0 |  |
| 12 | P_NRO | C (Character) | 20 |  |  |
| 13 | P_FECVTO | D (Date) | 8 |  |  |
| 14 | P_BANCO | N (Numeric) | 3 | 0 |  |
| 15 | P_COTIZ | N (Numeric) | 5 | 2 |  |
| 16 | P_IMPORTE | N (Numeric) | 9 | 2 |  |
| 17 | A_PAGAR | N (Numeric) | 9 | 2 |  |
| 18 | CIERRE | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 19 | NUMERO_CIE | N (Numeric) | 10 | 0 |  |
| 20 | ACTUALIZAC | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CHEQUES | `p_fpago+STR(cliente,5)+p_nro+STR(p_importe,9,2)` | Regular | ASC | `p_fpago="CH"` |
| CLAVE | `tipo+letra+STR(sucursal,2)+STR(comprobante,7)` | Regular | ASC |  |

## Historia/h_estadistica.DBF

Formato: Visual FoxPro · 17 campos · largo de registro: 120 bytes · registros: 0 · base de datos: `..\datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FECHA | D (Date) | 8 |  |  |
| 2 | TIPO | C (Character) | 2 |  |  |
| 3 | LETRA | C (Character) | 1 |  |  |
| 4 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 5 | PREFIJO | N (Numeric) | 4 | 0 |  |
| 6 | COMPROBANT | N (Numeric) | 7 | 0 |  |
| 7 | ARTICULO | C (Character) | 15 |  |  |
| 8 | CODIGO_DE_ | N (Numeric) | 13 | 0 |  |
| 9 | BULTOS | N (Numeric) | 3 | 0 |  |
| 10 | UNIDADES | N (Numeric) | 11 | 2 |  |
| 11 | KILOS | N (Numeric) | 11 | 3 |  |
| 12 | LISTA | C (Character) | 1 |  |  |
| 13 | PRECIO | N (Numeric) | 11 | 4 |  |
| 14 | PORCENTAJE | N (Numeric) | 6 | 2 |  |
| 15 | TOTAL_ITEM | N (Numeric) | 11 | 4 |  |
| 16 | CLIENTE | N (Numeric) | 5 | 0 |  |
| 17 | ACTUALIZAC | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `tipo+letra+STR(sucursal,2)+STR(comprobante,7)` | Regular | ASC |  |

## Historia/h_subdiario.DBF

Formato: Visual FoxPro · 31 campos · largo de registro: 272 bytes · registros: 0 · base de datos: `..\datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FECHA | D (Date) | 8 |  |  |
| 2 | TIPO | C (Character) | 2 |  |  |
| 3 | LETRA | C (Character) | 1 |  |  |
| 4 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 5 | PREFIJO | N (Numeric) | 4 | 0 |  |
| 6 | COMPROBANT | N (Numeric) | 7 | 0 |  |
| 7 | CLIENTE | N (Numeric) | 5 | 0 |  |
| 8 | BRUTO | N (Numeric) | 11 | 2 |  |
| 9 | NETO_GRABA | N (Numeric) | 11 | 2 |  |
| 10 | NETO_EXENT | N (Numeric) | 11 | 2 |  |
| 11 | IVA_1 | N (Numeric) | 11 | 2 |  |
| 12 | IVA_2 | N (Numeric) | 11 | 2 |  |
| 13 | LIBERADO | N (Numeric) | 11 | 2 |  |
| 14 | BONIFICACI | N (Numeric) | 11 | 2 |  |
| 15 | NETO | N (Numeric) | 11 | 2 |  |
| 16 | PORCENTAJE | N (Numeric) | 6 | 2 |  |
| 17 | ESTADO | C (Character) | 1 |  |  |
| 18 | OBSERVACIO | C (Character) | 60 |  |  |
| 19 | CANAL | C (Character) | 1 |  |  |
| 20 | TERMINAL | N (Numeric) | 2 | 0 |  |
| 21 | IVA_CLIENT | N (Numeric) | 1 | 0 |  |
| 22 | CUIT_CLIEN | N (Numeric) | 11 | 0 |  |
| 23 | RECARGO_F_ | N (Numeric) | 11 | 2 |  |
| 24 | IMPUESTO | N (Numeric) | 5 | 2 |  |
| 25 | RECIBO | N (Numeric) | 10 | 0 |  |
| 26 | MVSTK | N (Numeric) | 10 | 0 |  |
| 27 | COBRO | D (Date) | 8 |  |  |
| 28 | CIERRE | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 29 | NUMERO_CIE | N (Numeric) | 10 | 0 |  |
| 30 | VENDEDOR | C (Character) | 2 |  |  |
| 31 | ACTUALIZAC | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `tipo+letra+STR(sucursal,2)+STR(comprobante,7)` | Regular | ASC |  |

## IMPORTES_POR_MODELOS_POR_TALLER.DBF

Formato: Visual FoxPro (autoincremental) · 4 campos · largo de registro: 22 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | N_ID | I (Integer) | 4 |  | binario (NOCPTRANS), autoincremental |
| 2 | MODELO | C (Character) | 4 |  |  |
| 3 | TALLER | C (Character) | 3 |  |  |
| 4 | IMPORTE | N (Numeric) | 10 | 2 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `modelo+taller` | Regular | ASC | `.NOT.DELETED()` |
| MODELO | `modelo` | Regular | ASC | `.NOT.DELETED()` |
| N_ID | `n_id` | Candidate | ASC |  |

## imputacion_remitos.dbf

Formato: Visual FoxPro · 11 campos · largo de registro: 44 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FECHA | D (Date) | 8 |  |  |
| 2 | TIPO | C (Character) | 2 |  |  |
| 3 | LETRA | C (Character) | 1 |  |  |
| 4 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 5 | PREFIJO | N (Numeric) | 4 | 0 |  |
| 6 | comprobante (DBF: COMPROBANT) | N (Numeric) | 7 | 0 |  |
| 7 | sucursal_rto (DBF: SUCURSAL_R) | N (Numeric) | 1 | 0 |  |
| 8 | NUMERO_RTO | N (Numeric) | 7 | 0 |  |
| 9 | TIPO_RTO | C (Character) | 2 |  |  |
| 10 | LETRA_RTO | C (Character) | 1 |  |  |
| 11 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `tipo+letra+STR(sucursal,2)+STR(comprobante,7)` | Regular | ASC |  |
| REMITO | `tipo+letra+STR(sucursal,2)+STR(comprobante,7)+tipo_rto+letra_rto+STR(sucursal_rto,2)+STR(numero_rto,7)` | Regular | ASC |  |

## imputaciones.dbf

Formato: Visual FoxPro · 13 campos · largo de registro: 74 bytes · registros: 54 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CLIENTES | N (Numeric) | 5 | 0 |  |
| 2 | TIPODB | C (Character) | 2 |  |  |
| 3 | TICODB | N (Numeric) | 1 | 0 |  |
| 4 | NRCODB | N (Numeric) | 7 | 0 |  |
| 5 | TIPOCR | C (Character) | 2 |  |  |
| 6 | TICOCR | N (Numeric) | 1 | 0 |  |
| 7 | NRCOCR | N (Numeric) | 7 | 0 |  |
| 8 | IMPORTE | N (Numeric) | 16 | 2 |  |
| 9 | FECODB | D (Date) | 8 |  |  |
| 10 | FECOCR | D (Date) | 8 |  |  |
| 11 | RECIBO | N (Numeric) | 7 | 0 |  |
| 12 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 13 | LETRADB | C (Character) | 1 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CREDITO | `STR(clientes,5)+tipocr+STR(nrcocr,7)+DTOS(fecocr)` | Regular | ASC |  |
| DEBITO | `STR(clientes,5)+tipodb+STR(nrcodb,7)+DTOS(fecodb)` | Regular | ASC |  |
| IMPUT | `STR(ticodb,1)+STR(nrcodb,7)+DTOS(fecocr)+STR(ticocr,1)+STR(nrcocr,7)` | Regular | ASC |  |
| T_IMPUT | `tipocr+STR(nrcocr,7)+tipodb+STR(nrcodb,7)+STR(clientes,5)` | Regular | ASC |  |

## ITEMS.DBF

Formato: Visual FoxPro · 5 campos · largo de registro: 68 bytes · registros: 0 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NUMERO | N (Numeric) | 10 | 0 |  |
| 2 | ORDEN | N (Numeric) | 2 | 0 |  |
| 3 | TEMA | C (Character) | 30 |  |  |
| 4 | AUTOR | C (Character) | 20 |  |  |
| 5 | TIEMPO | C (Character) | 5 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| NUMERO | `numero` | Regular | ASC |  |

## items_pedidos.dbf

Formato: Visual FoxPro · 19 campos · largo de registro: 139 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FECHA | D (Date) | 8 |  |  |
| 2 | TIPO | C (Character) | 2 |  |  |
| 3 | LETRA | C (Character) | 1 |  |  |
| 4 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 5 | PREFIJO | N (Numeric) | 4 | 0 |  |
| 6 | comprobante (DBF: COMPROBANT) | N (Numeric) | 7 | 0 |  |
| 7 | ARTICULO | C (Character) | 15 |  |  |
| 8 | codigo_de_barra (DBF: CODIGO_DE_) | N (Numeric) | 13 | 0 |  |
| 9 | BULTOS | N (Numeric) | 3 | 0 |  |
| 10 | UNIDADES | N (Numeric) | 11 | 2 |  |
| 11 | KILOS | N (Numeric) | 11 | 3 |  |
| 12 | SALDO | N (Numeric) | 11 | 2 |  |
| 13 | LISTA | C (Character) | 1 |  |  |
| 14 | PRECIO | N (Numeric) | 11 | 4 |  |
| 15 | porcentaje_descuento (DBF: PORCENTAJE) | N (Numeric) | 6 | 2 |  |
| 16 | TOTAL_ITEM | N (Numeric) | 11 | 4 |  |
| 17 | CLIENTE | N (Numeric) | 5 | 0 |  |
| 18 | fecha_ult_factura (DBF: FECHA_ULT_) | D (Date) | 8 |  |  |
| 19 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `tipo+letra+STR(sucursal,2)+STR(comprobante,7)+articulo` | Regular | ASC |  |
| CLIENTE | `cliente` | Regular | ASC |  |
| FECHA | `fecha` | Regular | ASC |  |

## iva.dbf

Formato: Visual FoxPro · 10 campos · largo de registro: 70 bytes · registros: 3383 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | TIPO | C (Character) | 2 |  |  |
| 2 | LETRA | C (Character) | 1 |  |  |
| 3 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 4 | comprobante (DBF: COMPROBANT) | N (Numeric) | 7 | 0 |  |
| 5 | ALICUOTA | N (Numeric) | 5 | 2 |  |
| 6 | MONTO | N (Numeric) | 10 | 2 |  |
| 7 | IMPORTE | N (Numeric) | 10 | 2 |  |
| 8 | CIERRE_Z | N (Numeric) | 10 | 0 |  |
| 9 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 10 | FE_CLAVE | N (Numeric) | 14 | 0 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CIERRE_Z | `cierre_z` | Regular | ASC |  |
| CLAVE | `tipo+letra+STR(sucursal,2)+STR(comprobante,7)` | Regular | ASC |  |

## Liquidacion_taller_cab.DBF

Formato: Visual FoxPro · 4 campos · largo de registro: 30 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NUMERO | N (Numeric) | 10 | 0 |  |
| 2 | FECHA | D (Date) | 8 |  |  |
| 3 | TALLER | C (Character) | 3 |  |  |
| 4 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| NUMERO | `numero` | Candidate | ASC |  |

## liquidacion_taller_det.DBF

Formato: Visual FoxPro · 6 campos · largo de registro: 65 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NUMERO | N (Numeric) | 10 | 0 |  |
| 2 | ARTICULO | C (Character) | 15 |  |  |
| 3 | UNIDADES | N (Numeric) | 11 | 2 |  |
| 4 | COSTO | N (Numeric) | 10 | 2 |  |
| 5 | numero_de_recepcion (DBF: NUMERO_DE_) | N (Numeric) | 10 | 0 |  |
| 6 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `STR(numero)+articulo` | Regular | ASC |  |
| NUMERO | `numero` | Regular | ASC |  |

## LISTADO.DBF

Formato: Visual FoxPro · 21 campos · largo de registro: 197 bytes · registros: 1 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | C (Character) | 15 |  |  |
| 2 | DESCRIPCIO | C (Character) | 40 |  |  |
| 3 | RUBRO | C (Character) | 3 |  |  |
| 4 | COSTO1 | N (Numeric) | 10 | 4 |  |
| 5 | FC1 | D (Date) | 8 |  |  |
| 6 | NPRO1 | N (Numeric) | 5 | 0 |  |
| 7 | COSTO2 | N (Numeric) | 10 | 4 |  |
| 8 | FC2 | D (Date) | 8 |  |  |
| 9 | NPRO2 | N (Numeric) | 5 | 0 |  |
| 10 | COSTO3 | N (Numeric) | 10 | 4 |  |
| 11 | FC3 | D (Date) | 8 |  |  |
| 12 | NPRO3 | N (Numeric) | 5 | 0 |  |
| 13 | COSTO4 | N (Numeric) | 10 | 4 |  |
| 14 | FC4 | D (Date) | 8 |  |  |
| 15 | NPRO4 | N (Numeric) | 5 | 0 |  |
| 16 | COSTO5 | N (Numeric) | 10 | 4 |  |
| 17 | FC5 | D (Date) | 8 |  |  |
| 18 | NPRO5 | N (Numeric) | 5 | 0 |  |
| 19 | COSTO6 | N (Numeric) | 10 | 4 |  |
| 20 | FC6 | D (Date) | 8 |  |  |
| 21 | NPRO6 | N (Numeric) | 5 | 0 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| GEN | `rubro+codigo` | Regular | ASC |  |

## LISTAS.DBF

Formato: Visual FoxPro · 2 campos · largo de registro: 19 bytes · registros: 0 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | DESCRIP | C (Character) | 15 |  |  |
| 2 | NUM_LISTA | N (Numeric) | 3 | 0 |  |

Índices: sin CDX estructural.

## Mangas.DBF

Formato: Visual FoxPro · 2 campos · largo de registro: 33 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | C (Character) | 2 |  |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 30 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Regular | ASC |  |
| DESCRIP | `descripcion` | Regular | ASC |  |

## materias_primas.dbf

Formato: Visual FoxPro · 29 campos · largo de registro: 311 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | TIPO_MP | C (Character) | 2 |  |  |
| 2 | RUBRO_MP | C (Character) | 3 |  |  |
| 3 | CODIGO | C (Character) | 4 |  |  |
| 4 | COLOR | C (Character) | 3 |  |  |
| 5 | ANCHO | N (Numeric) | 5 | 3 |  |
| 6 | descripcion_propia (DBF: DESCRIPCIO) | C (Character) | 60 |  |  |
| 7 | codigo_proveedor (DBF: CODIGO_PRO) | C (Character) | 20 |  |  |
| 8 | descripcion_proveedor (DBF: DESCRIPCI2) | C (Character) | 40 |  |  |
| 9 | unidad_de_medida (DBF: UNIDAD_DE_) | C (Character) | 2 |  |  |
| 10 | stock_fisico (DBF: STOCK_FISI) | N (Numeric) | 20 | 4 |  |
| 11 | stock_minimo (DBF: STOCK_MINI) | N (Numeric) | 20 | 4 |  |
| 12 | comprometido_ingreso (DBF: COMPROMETI) | N (Numeric) | 20 | 4 |  |
| 13 | comprometido_egreso (DBF: COMPROMET2) | N (Numeric) | 20 | 4 |  |
| 14 | costo_de_compra (DBF: COSTO_DE_C) | N (Numeric) | 14 | 4 |  |
| 15 | fecha_ultima_compra (DBF: FECHA_ULTI) | D (Date) | 8 |  |  |
| 16 | proveedor_habitual (DBF: PROVEEDOR_) | N (Numeric) | 5 | 0 |  |
| 17 | proveedor_alternativo (DBF: PROVEEDOR2) | N (Numeric) | 5 | 0 |  |
| 18 | unidad_de_conversion (DBF: UNIDAD_DE2) | C (Character) | 2 |  |  |
| 19 | factor_de_conversion (DBF: FACTOR_DE_) | N (Numeric) | 10 | 2 |  |
| 20 | OPERACION | C (Character) | 1 |  |  |
| 21 | EN_ROLLO | L (Logical) | 1 |  |  |
| 22 | LARGO | N (Numeric) | 6 | 2 |  |
| 23 | TAMANIO | C (Character) | 10 |  |  |
| 24 | color_proveedor (DBF: COLOR_PROV) | C (Character) | 10 |  |  |
| 25 | tipo_de_cierre (DBF: TIPO_DE_CI) | C (Character) | 3 |  |  |
| 26 | proveedor_ultima_compra (DBF: PROVEEDOR3) | N (Numeric) | 5 | 0 |  |
| 27 | ESPESOR | N (Numeric) | 5 | 2 |  |
| 28 | alicuota_iva (DBF: ALICUOTA_I) | N (Numeric) | 5 | 2 |  |
| 29 | estadistica_de_cortes (DBF: ESTADISTIC) | L (Logical) | 1 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| TIPO_MP | `tipo_mp+rubro_mp+codigo+color` | Candidate | ASC | `.NOT.DELETED()` |

## MINIMOS.DBF

Formato: Visual FoxPro · 6 campos · largo de registro: 46 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 2 | ARTICULO | C (Character) | 15 |  |  |
| 3 | min_unidades (DBF: MIN_UNIDAD) | N (Numeric) | 6 | 0 |  |
| 4 | MIN_KILOS | N (Numeric) | 6 | 0 |  |
| 5 | MIN_FECHA | D (Date) | 8 |  |  |
| 6 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `STR(sucursal,2)+articulo` | Regular | ASC |  |

## modelos.dbf

Formato: Visual FoxPro · 9 campos · largo de registro: 80 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | C (Character) | 4 |  |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 40 |  |  |
| 3 | talle_minimo (DBF: TALLE_MINI) | N (Numeric) | 2 | 0 |  |
| 4 | talle_maximo (DBF: TALLE_MAXI) | N (Numeric) | 2 | 0 |  |
| 5 | talle_promedio (DBF: TALLE_PROM) | N (Numeric) | 2 | 0 |  |
| 6 | incremento_por_talle (DBF: INCREMENTO) | N (Numeric) | 1 | 0 |  |
| 7 | variacion_por_talle (DBF: VARIACION_) | N (Numeric) | 8 | 4 |  |
| 8 | promedio_valor_horario_hechura (DBF: PROMEDIO_V) | N (Numeric) | 10 | 4 |  |
| 9 | promedio_tiempo_por_prenda_hechura (DBF: PROMEDIO_T) | N (Numeric) | 10 | 4 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Candidate | ASC | `.NOT.DELETED()` |
| DESCRIP | `descripcion` | Regular | ASC | `.NOT.DELETED()` |

## motivos.dbf

Formato: Visual FoxPro · 9 campos · largo de registro: 49 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | C (Character) | 2 |  |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 30 |  |  |
| 3 | calcula_iva (DBF: CALCULA_IV) | L (Logical) | 1 |  |  |
| 4 | graba_ctact (DBF: GRABA_CTAC) | L (Logical) | 1 |  |  |
| 5 | graba_subdiario (DBF: GRABA_SUBD) | L (Logical) | 1 |  |  |
| 6 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 7 | alicuota_gastos (DBF: ALICUOTA_G) | C (Character) | 2 |  |  |
| 8 | alicuota_importe (DBF: ALICUOTA_I) | C (Character) | 2 |  |  |
| 9 | calcula_iva_importe (DBF: CALCULA_I2) | L (Logical) | 1 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Candidate | ASC | `.NOT.DELETED()` |

## mp_pendiente_compras.dbf

Formato: Visual FoxPro · 7 campos · largo de registro: 85 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | nota_de_pedido (DBF: NOTA_DE_PE) | N (Numeric) | 8 | 0 |  |
| 2 | FECHA | D (Date) | 8 |  |  |
| 3 | CODIGO_MP | C (Character) | 15 |  |  |
| 4 | PROVEEDOR | N (Numeric) | 5 | 0 |  |
| 5 | unidades_pedidas (DBF: UNIDADES_P) | N (Numeric) | 20 | 4 |  |
| 6 | SALDO | N (Numeric) | 20 | 4 |  |
| 7 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO_MP | `codigo_mp` | Regular | ASC |  |
| NOTA_PED | `STR(nota_de_pedido,8)+codigo_mp` | Regular | ASC |  |

## mp_por_proveedor.dbf

Formato: Visual FoxPro · 17 campos · largo de registro: 190 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | materia_prima (DBF: MATERIA_PR) | C (Character) | 15 |  |  |
| 2 | PROVEEDOR | N (Numeric) | 5 | 0 |  |
| 3 | num_interno (DBF: NUM_INTERN) | C (Character) | 15 |  |  |
| 4 | COSTO | N (Numeric) | 10 | 4 |  |
| 5 | DESC1 | N (Numeric) | 5 | 2 |  |
| 6 | DESC2 | N (Numeric) | 5 | 2 |  |
| 7 | DESC3 | N (Numeric) | 5 | 2 |  |
| 8 | unidad_venta (DBF: UNIDAD_VEN) | C (Character) | 2 |  |  |
| 9 | FECHA1 | D (Date) | 8 |  |  |
| 10 | CANTIDAD1 | N (Numeric) | 10 | 2 |  |
| 11 | FECHA2 | D (Date) | 8 |  |  |
| 12 | CANTIDAD2 | N (Numeric) | 10 | 2 |  |
| 13 | FECHA3 | D (Date) | 8 |  |  |
| 14 | CANTIDAD3 | N (Numeric) | 10 | 2 |  |
| 15 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 16 | color_del_prove (DBF: COLOR_DEL_) | C (Character) | 10 |  |  |
| 17 | descripcion_proveedor (DBF: DESCRIPCIO) | C (Character) | 55 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| MP | `materia_prima` | Regular | ASC |  |
| MP_PRO | `materia_prima+STR(proveedor,5)` | Regular | ASC |  |

## mvstk_cabecera.dbf

Formato: Visual FoxPro · 16 campos · largo de registro: 120 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | numero_mvstk (DBF: NUMERO_MVS) | N (Numeric) | 10 | 0 |  |
| 2 | FECHA | D (Date) | 8 |  |  |
| 3 | TIPO | C (Character) | 2 |  |  |
| 4 | SUCURSAL | N (Numeric) | 4 | 0 |  |
| 5 | NUMERO | N (Numeric) | 9 | 0 |  |
| 6 | PROVEEDOR | N (Numeric) | 5 | 0 |  |
| 7 | ORIGEN | N (Numeric) | 2 | 0 |  |
| 8 | signo_origen (DBF: SIGNO_ORIG) | C (Character) | 1 |  |  |
| 9 | DESTINO | N (Numeric) | 2 | 0 |  |
| 10 | signo_destino (DBF: SIGNO_DEST) | C (Character) | 1 |  |  |
| 11 | observaciones (DBF: OBSERVACIO) | C (Character) | 40 |  |  |
| 12 | NRO_OC | N (Numeric) | 8 | 0 |  |
| 13 | LETRA | C (Character) | 1 |  |  |
| 14 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 15 | RENGLONES | N (Numeric) | 4 | 0 |  |
| 16 | FE_CLAVE | N (Numeric) | 14 | 0 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `tipo+STR(proveedor,5)+STR(sucursal,4)+STR(numero,9)+DTOS(fecha)` | Regular | ASC |  |
| NUMERO | `numero_mvstk` | Regular | ASC |  |

## mvstk_items.dbf

Formato: Visual FoxPro · 6 campos · largo de registro: 72 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | numero_mvstk (DBF: NUMERO_MVS) | N (Numeric) | 10 | 0 |  |
| 2 | ARTICULO | C (Character) | 15 |  |  |
| 3 | cantidad_unidades (DBF: CANTIDAD_U) | N (Numeric) | 12 | 2 |  |
| 4 | cantidad_kilos (DBF: CANTIDAD_K) | N (Numeric) | 12 | 3 |  |
| 5 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 6 | COSTO | N (Numeric) | 14 | 4 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| ARTICULO | `articulo` | Regular | ASC |  |
| CLAVE | `STR(numero_mvstk,10)+articulo` | Regular | ASC |  |
| NUMERO | `numero_mvstk` | Regular | ASC |  |

## MVSTKMP_CABECERA.DBF

Formato: Visual FoxPro · 15 campos · largo de registro: 106 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | numero_mvstk_mp (DBF: NUMERO_MVS) | N (Numeric) | 10 | 0 |  |
| 2 | FECHA | D (Date) | 8 |  |  |
| 3 | TIPO | C (Character) | 2 |  |  |
| 4 | SUCURSAL | N (Numeric) | 4 | 0 |  |
| 5 | NUMERO | N (Numeric) | 9 | 0 |  |
| 6 | PROVEEDOR | N (Numeric) | 5 | 0 |  |
| 7 | ORIGEN | N (Numeric) | 2 | 0 |  |
| 8 | signo_origen (DBF: SIGNO_ORIG) | C (Character) | 1 |  |  |
| 9 | DESTINO | N (Numeric) | 2 | 0 |  |
| 10 | signo_destino (DBF: SIGNO_DEST) | C (Character) | 1 |  |  |
| 11 | observaciones (DBF: OBSERVACIO) | C (Character) | 40 |  |  |
| 12 | NRO_OC | N (Numeric) | 8 | 0 |  |
| 13 | LETRA | C (Character) | 1 |  |  |
| 14 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 15 | RENGLONES | N (Numeric) | 4 | 0 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `tipo+STR(proveedor,5)+STR(sucursal,4)+STR(numero,9)+DTOS(fecha)` | Regular | ASC | `.NOT.DELETED()` |
| NUMERO | `numero_mvstk_mp` | Regular | ASC | `.NOT.DELETED()` |

## mvstkmp_items.DBF

Formato: Visual FoxPro · 6 campos · largo de registro: 80 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | numero_mvstk_mp (DBF: NUMERO_MVS) | N (Numeric) | 10 | 0 |  |
| 2 | CODIGO_MP | C (Character) | 15 |  |  |
| 3 | cantidad_unidades (DBF: CANTIDAD_U) | N (Numeric) | 20 | 4 |  |
| 4 | cantidad_kilos (DBF: CANTIDAD_K) | N (Numeric) | 12 | 3 |  |
| 5 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 6 | COSTO | N (Numeric) | 14 | 4 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `STR(numero_mvstk_mp,10)+codigo_mp` | Regular | ASC | `.NOT.DELETED()` |
| CODIGO_MP | `codigo_mp` | Regular | ASC | `.NOT.DELETED()` |
| NUMERO | `numero_mvstk_mp` | Regular | ASC | `.NOT.DELETED()` |

## notaped_cab.dbf

Formato: Visual FoxPro · 41 campos · largo de registro: 333 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FECHA | D (Date) | 8 |  |  |
| 2 | NUMERO | N (Numeric) | 10 | 0 |  |
| 3 | TIPO | C (Character) | 2 |  |  |
| 4 | LETRA | C (Character) | 1 |  |  |
| 5 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 6 | PREFIJO | N (Numeric) | 4 | 0 |  |
| 7 | CLIENTE | N (Numeric) | 5 | 0 |  |
| 8 | BRUTO | N (Numeric) | 11 | 2 |  |
| 9 | neto_grabado (DBF: NETO_GRABA) | N (Numeric) | 11 | 2 |  |
| 10 | neto_exento (DBF: NETO_EXENT) | N (Numeric) | 11 | 2 |  |
| 11 | IVA_1 | N (Numeric) | 11 | 2 |  |
| 12 | IVA_2 | N (Numeric) | 11 | 2 |  |
| 13 | LIBERADO | N (Numeric) | 11 | 2 |  |
| 14 | bonificacion (DBF: BONIFICACI) | N (Numeric) | 11 | 2 |  |
| 15 | NETO | N (Numeric) | 11 | 2 |  |
| 16 | porcentaje_descuento (DBF: PORCENTAJE) | N (Numeric) | 6 | 2 |  |
| 17 | ESTADO | C (Character) | 1 |  |  |
| 18 | observaciones (DBF: OBSERVACIO) | M (Memo) | 4 |  |  |
| 19 | CANAL | C (Character) | 1 |  |  |
| 20 | TERMINAL | N (Numeric) | 2 | 0 |  |
| 21 | iva_cliente (DBF: IVA_CLIENT) | N (Numeric) | 1 | 0 |  |
| 22 | cuit_cliente (DBF: CUIT_CLIEN) | N (Numeric) | 11 | 0 |  |
| 23 | recargo_f_pago (DBF: RECARGO_F_) | N (Numeric) | 11 | 2 |  |
| 24 | IMPUESTO | N (Numeric) | 5 | 2 |  |
| 25 | RECIBO | N (Numeric) | 10 | 0 |  |
| 26 | MVSTK | N (Numeric) | 10 | 0 |  |
| 27 | COBRO | D (Date) | 8 |  |  |
| 28 | CIERRE | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 29 | numero_cierre (DBF: NUMERO_CIE) | N (Numeric) | 10 | 0 |  |
| 30 | VENDEDOR | C (Character) | 2 |  |  |
| 31 | FACTURADO | D (Date) | 8 |  |  |
| 32 | VALIDES | D (Date) | 8 |  |  |
| 33 | FORMA_PAGO | N (Numeric) | 1 | 0 |  |
| 34 | UNIDADES | N (Numeric) | 11 | 2 |  |
| 35 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 36 | orden_compra (DBF: ORDEN_COMP) | C (Character) | 20 |  |  |
| 37 | REMITIDO | D (Date) | 8 |  |  |
| 38 | TRANSPORTE | N (Numeric) | 3 | 0 |  |
| 39 | BULTOS | N (Numeric) | 7 | 0 |  |
| 40 | DESTINO | C (Character) | 40 |  |  |
| 41 | fecha_entrega (DBF: FECHA_ENTR) | D (Date) | 8 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `tipo+letra+STR(sucursal,2)+STR(numero,8)` | Regular | ASC |  |
| NUMERO | `numero` | Regular | ASC |  |

## notaped_det.dbf

Formato: Visual FoxPro · 20 campos · largo de registro: 155 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FECHA | D (Date) | 8 |  |  |
| 2 | NUMERO | N (Numeric) | 10 | 0 |  |
| 3 | ARTICULO | C (Character) | 15 |  |  |
| 4 | codigo_de_barra (DBF: CODIGO_DE_) | N (Numeric) | 13 | 0 |  |
| 5 | BULTOS | N (Numeric) | 3 | 0 |  |
| 6 | UNIDADES | N (Numeric) | 11 | 3 |  |
| 7 | KILOS | N (Numeric) | 11 | 3 |  |
| 8 | SALDO | N (Numeric) | 11 | 2 |  |
| 9 | LISTA | C (Character) | 3 |  |  |
| 10 | PRECIO | N (Numeric) | 11 | 4 |  |
| 11 | porcentaje_descuento (DBF: PORCENTAJE) | N (Numeric) | 6 | 2 |  |
| 12 | TOTAL_ITEM | N (Numeric) | 11 | 4 |  |
| 13 | CLIENTE | N (Numeric) | 5 | 0 |  |
| 14 | COSTO | N (Numeric) | 11 | 4 |  |
| 15 | fecha_ult_factura (DBF: FECHA_ULT_) | D (Date) | 8 |  |  |
| 16 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 17 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 18 | TIPO | C (Character) | 2 |  |  |
| 19 | LETRA | C (Character) | 1 |  |  |
| 20 | PREFIJO | N (Numeric) | 4 | 0 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| ART | `tipo+letra+STR(sucursal,2)+STR(numero,8)+articulo` | Regular | ASC |  |
| CLAVE | `tipo+letra+STR(sucursal,2)+STR(numero,8)` | Regular | ASC |  |
| NUMERO | `numero` | Regular | ASC |  |
| NUM_ART | `STR(numero)+articulo` | Regular | ASC |  |

## ordcomp_detalle.dbf

Formato: Visual FoxPro · 7 campos · largo de registro: 72 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NUM | N (Numeric) | 8 | 0 |  |
| 2 | ARTICULO | C (Character) | 15 |  |  |
| 3 | PRECIO | N (Numeric) | 11 | 4 |  |
| 4 | CANTIDAD | N (Numeric) | 9 | 2 |  |
| 5 | SALDO | N (Numeric) | 9 | 2 |  |
| 6 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 7 | COSTO | N (Numeric) | 11 | 4 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| NUM | `num` | Regular | ASC |  |
| NUM_ART | `STR(num,8)+articulo` | Regular | ASC |  |

## ordcomp_detalle_mp.dbf

Formato: Visual FoxPro · 12 campos · largo de registro: 122 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NUM | N (Numeric) | 8 | 0 |  |
| 2 | materia_prima (DBF: MATERIA_PR) | C (Character) | 15 |  |  |
| 3 | PRECIO | N (Numeric) | 11 | 4 |  |
| 4 | CANTIDAD | N (Numeric) | 20 | 4 |  |
| 5 | SALDO | N (Numeric) | 20 | 4 |  |
| 6 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 7 | nota_de_pedido (DBF: NOTA_DE_PE) | N (Numeric) | 8 | 0 |  |
| 8 | PROVEEDOR | N (Numeric) | 5 | 0 |  |
| 9 | COSTO | N (Numeric) | 11 | 2 |  |
| 10 | DESC1 | N (Numeric) | 5 | 2 |  |
| 11 | DESC2 | N (Numeric) | 5 | 2 |  |
| 12 | DESC3 | N (Numeric) | 5 | 2 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| NUM | `num` | Regular | ASC |  |
| NUM_MP | `STR(num,8)+materia_prima` | Regular | ASC |  |

## ordcomp_encabezado.dbf

Formato: Visual FoxPro · 9 campos · largo de registro: 114 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NUMERO | N (Numeric) | 8 | 0 |  |
| 2 | FECHA | D (Date) | 8 |  |  |
| 3 | fecha_entrega (DBF: FECHA_ENTR) | D (Date) | 8 |  |  |
| 4 | PROVEEDOR | N (Numeric) | 5 | 0 |  |
| 5 | observaciones (DBF: OBSERVACIO) | C (Character) | 40 |  |  |
| 6 | SUBTOTAL | N (Numeric) | 12 | 2 |  |
| 7 | IVA | N (Numeric) | 12 | 2 |  |
| 8 | TOTAL | N (Numeric) | 12 | 2 |  |
| 9 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| NUMERO | `numero` | Regular | ASC |  |

## ordcomp_encabezado_mp.dbf

Formato: Visual FoxPro · 12 campos · largo de registro: 196 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NUMERO | N (Numeric) | 8 | 0 |  |
| 2 | FECHA | D (Date) | 8 |  |  |
| 3 | fecha_entrega (DBF: FECHA_ENTR) | D (Date) | 8 |  |  |
| 4 | PROVEEDOR | N (Numeric) | 5 | 0 |  |
| 5 | observaciones (DBF: OBSERVACIO) | C (Character) | 60 |  |  |
| 6 | SUBTOTAL | N (Numeric) | 12 | 2 |  |
| 7 | IVA | N (Numeric) | 12 | 2 |  |
| 8 | TOTAL | N (Numeric) | 12 | 2 |  |
| 9 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 10 | AUTORIZO | C (Character) | 30 |  |  |
| 11 | confecciono (DBF: CONFECCION) | C (Character) | 30 |  |  |
| 12 | condicion_de_pago (DBF: CONDICION_) | C (Character) | 2 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| NUMERO | `numero` | Regular | ASC |  |

## ordcomp_libre_cab.dbf

Formato: Visual FoxPro · 13 campos · largo de registro: 177 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NUMERO | N (Numeric) | 8 | 0 |  |
| 2 | FECHA | D (Date) | 8 |  |  |
| 3 | fecha_entrega (DBF: FECHA_ENTR) | D (Date) | 8 |  |  |
| 4 | PROVEEDOR | N (Numeric) | 5 | 0 |  |
| 5 | observaciones (DBF: OBSERVACIO) | C (Character) | 40 |  |  |
| 6 | SUBTOTAL | N (Numeric) | 12 | 2 |  |
| 7 | IVA | N (Numeric) | 12 | 2 |  |
| 8 | TOTAL | N (Numeric) | 12 | 2 |  |
| 9 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 10 | AUTORIZO | C (Character) | 30 |  |  |
| 11 | confecciono (DBF: CONFECCION) | C (Character) | 30 |  |  |
| 12 | condicion_de_pago (DBF: CONDICION_) | C (Character) | 2 |  |  |
| 13 | ESTADO | C (Character) | 1 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| NUMERO | `numero` | Regular | ASC |  |

## ordcomp_libre_det.DBF

Formato: Visual FoxPro · 7 campos · largo de registro: 117 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NUM | N (Numeric) | 8 | 0 |  |
| 2 | ARTICULO | C (Character) | 60 |  |  |
| 3 | PRECIO | N (Numeric) | 11 | 4 |  |
| 4 | CANTIDAD | N (Numeric) | 9 | 2 |  |
| 5 | SALDO | N (Numeric) | 9 | 2 |  |
| 6 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 7 | COSTO | N (Numeric) | 11 | 4 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| NUM | `num` | Regular | ASC |  |
| NUM_ART | `STR(num,8)+articulo` | Regular | ASC |  |

## orden_de_corte_cab.dbf

Formato: Visual FoxPro · 9 campos · largo de registro: 62 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NUMERO | N (Numeric) | 8 | 0 |  |
| 2 | FECHA_HORA | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 3 | promedio_estimado (DBF: PROMEDIO_E) | N (Numeric) | 8 | 2 |  |
| 4 | ESTADO | N (Numeric) | 1 | 0 |  |
| 5 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 6 | observaciones (DBF: OBSERVACIO) | M (Memo) | 4 |  |  |
| 7 | promedio_real (DBF: PROMEDIO_R) | N (Numeric) | 8 | 2 |  |
| 8 | cantidad_de_tiras (DBF: CANTIDAD_D) | N (Numeric) | 8 | 2 |  |
| 9 | metros_de_tela (DBF: METROS_DE_) | N (Numeric) | 8 | 2 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| NUMERO | `numero` | Regular | ASC |  |

## orden_de_corte_det.dbf

Formato: Visual FoxPro · 6 campos · largo de registro: 50 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NUMERO | N (Numeric) | 8 | 0 |  |
| 2 | ARTICULO | C (Character) | 15 |  |  |
| 3 | PEDIDAS | N (Numeric) | 4 | 0 |  |
| 4 | PENDIENTES | N (Numeric) | 4 | 0 |  |
| 5 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 6 | para_envio_taller (DBF: PARA_ENVIO) | N (Numeric) | 10 | 0 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| NUMERO | `numero` | Regular | ASC |  |
| ORD_ART | `STR(numero,8)+articulo` | Regular | ASC |  |

## orden_de_corte_promedios.dbf

Formato: Visual FoxPro · 7 campos · largo de registro: 57 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NUMERO | N (Numeric) | 8 | 0 |  |
| 2 | CODIGO_MP | C (Character) | 15 |  |  |
| 3 | promedio_real (DBF: PROMEDIO_R) | N (Numeric) | 8 | 2 |  |
| 4 | TIZADA | N (Numeric) | 8 | 2 |  |
| 5 | TIRAS | N (Numeric) | 8 | 2 |  |
| 6 | TIRASS_D | N (Numeric) | 1 | 0 |  |
| 7 | tela_utilizada (DBF: TELA_UTILI) | N (Numeric) | 8 | 2 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO_MP | `STR(numero,8)+codigo_mp` | Regular | ASC |  |
| NUMERO | `numero` | Regular | ASC |  |

## OYS_ref.DBF

Formato: Visual FoxPro · 21 campos · largo de registro: 112 bytes · registros: 416 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | UNIQUEID | C (Character) | 10 |  |  |
| 2 | SETID | C (Character) | 10 |  |  |
| 3 | REFID | C (Character) | 10 |  |  |
| 4 | REFTYPE | C (Character) | 1 |  |  |
| 5 | FINDTYPE | C (Character) | 1 |  |  |
| 6 | FILEID | C (Character) | 10 |  |  |
| 7 | SYMBOL | M (Memo) | 4 |  |  |
| 8 | CLASSNAME | M (Memo) | 4 |  |  |
| 9 | PROCNAME | M (Memo) | 4 |  |  |
| 10 | PROCLINENO | I (Integer) | 4 |  | binario (NOCPTRANS) |
| 11 | LINENO | I (Integer) | 4 |  | binario (NOCPTRANS) |
| 12 | COLPOS | I (Integer) | 4 |  | binario (NOCPTRANS) |
| 13 | MATCHLEN | I (Integer) | 4 |  | binario (NOCPTRANS) |
| 14 | ABSTRACT | M (Memo) | 4 |  |  |
| 15 | RECORDID | C (Character) | 10 |  |  |
| 16 | UPDFIELD | C (Character) | 15 |  |  |
| 17 | CHECKED | L (Logical) | 1 |  | admite NULL |
| 18 | NOREPLACE | L (Logical) | 1 |  |  |
| 19 | TIMESTAMP | T (DateTime) | 8 |  | admite NULL, binario (NOCPTRANS) |
| 20 | INACTIVE | L (Logical) | 1 |  |  |
| 21 | _NullFlags | 0 ((sistema: _NullFlags)) | 1 |  | binario (NOCPTRANS), columna de sistema |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CHECKED | `checked` | Regular | ASC |  |
| FILEID | `fileid` | Regular | ASC |  |
| INACTIVE | `inactive` | Regular | ASC |  |
| REFID | `refid` | Regular | ASC |  |
| REFTYPE | `reftype` | Regular | ASC |  |
| SETID | `setid` | Regular | ASC |  |
| UNIQUEID | `uniqueid` | Regular | ASC |  |

## PAGOS.DBF

Formato: Visual FoxPro · 18 campos · largo de registro: 126 bytes · registros: 7 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | PAG_NROREC | N (Numeric) | 7 | 0 |  |
| 2 | PAG_FECHA | D (Date) | 8 |  |  |
| 3 | PAG_NROCLI | N (Numeric) | 5 | 0 |  |
| 4 | PAG_TICO | N (Numeric) | 1 | 0 |  |
| 5 | PAG_NRCO | N (Numeric) | 7 | 0 |  |
| 6 | PAG_IMPO | N (Numeric) | 16 | 2 |  |
| 7 | PAG_FEVTO | D (Date) | 8 |  |  |
| 8 | PAG_BCO | N (Numeric) | 3 | 0 |  |
| 9 | PAG_FECDEP | D (Date) | 8 |  |  |
| 10 | PAG_BCODEP | N (Numeric) | 3 | 0 |  |
| 11 | PAG_MARCA | N (Numeric) | 1 | 0 |  |
| 12 | PAG_MOTIVO | C (Character) | 2 |  |  |
| 13 | PAG_CORTE | N (Numeric) | 1 | 0 |  |
| 14 | PAG_FECEGR | D (Date) | 8 |  |  |
| 15 | PAG_PROVE | N (Numeric) | 5 | 0 |  |
| 16 | PAG_NOMBRE | C (Character) | 30 |  |  |
| 17 | PAG_NROEGR | N (Numeric) | 10 | 7 |  |
| 18 | PAG_TIPEGR | C (Character) | 2 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CHEQUES | `STR(pag_nrocli,5)+STR(pag_nrco,7)+STR(pag_impo,16,2)` | Regular | ASC |  |
| F_PAGOS | `STR(pag_nrocli,5)+DTOS(pag_fevto)+STR(pag_tico,1)+STR(pag_nrco,7)` | Regular | ASC |  |
| M_PAGOS | `DTOS(pag_fecha)+pag_motivo` | Regular | ASC |  |
| N_PAGOS | `STR(pag_nrocli,5)+DTOS(pag_fecha)+STR(pag_tico,1)+STR(pag_nrco,7)` | Regular | ASC |  |
| PAGOS | `STR(pag_nrorec,7)` | Regular | ASC |  |
| P_EGRE | `pag_tipegr+STR(pag_nroegr,7)` | Regular | ASC |  |
| T_PAGOS | `STR(pag_tico,1)+STR(pag_nrco,7)+DTOS(pag_fevto)` | Regular | ASC |  |
| X_PAGOS | `STR(pag_nrocli,5)+STR(pag_tico,1)` | Regular | ASC |  |

## PAGOS_C.DBF

Formato: FoxBASE+/dBase III · 4 campos · largo de registro: 18 bytes · registros: 20 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FIELD_NAME | C (Character) | 10 |  |  |
| 2 | FIELD_TYPE | C (Character) | 1 |  |  |
| 3 | FIELD_LEN | N (Numeric) | 3 | 0 |  |
| 4 | FIELD_DEC | N (Numeric) | 3 | 0 |  |

Índices: sin CDX estructural.

## parametros.dbf

Formato: Visual FoxPro · 45 campos · largo de registro: 778 bytes · registros: 1 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | EMPRESA | C (Character) | 40 |  |  |
| 2 | path_proveedores (DBF: PATH_PROVE) | C (Character) | 40 |  |  |
| 3 | ultimo_mvstk (DBF: ULTIMO_MVS) | N (Numeric) | 10 | 0 |  |
| 4 | ultimo_recibo (DBF: ULTIMO_REC) | N (Numeric) | 10 | 0 |  |
| 5 | mvstk_automatico (DBF: MVSTK_AUTO) | N (Numeric) | 10 | 0 |  |
| 6 | ultima_transformacion (DBF: ULTIMA_TRA) | N (Numeric) | 10 | 0 |  |
| 7 | ultimo_cierre_caja (DBF: ULTIMO_CIE) | N (Numeric) | 10 | 0 |  |
| 8 | ultimo_cliente (DBF: ULTIMO_CLI) | N (Numeric) | 5 | 0 |  |
| 9 | por_impuesto (DBF: POR_IMPUES) | N (Numeric) | 5 | 2 |  |
| 10 | tk_direccion (DBF: TK_DIRECCI) | C (Character) | 30 |  |  |
| 11 | tk_telefono (DBF: TK_TELEFON) | C (Character) | 20 |  |  |
| 12 | TK_CALL | C (Character) | 20 |  |  |
| 13 | TK_IVA | C (Character) | 25 |  |  |
| 14 | tk_i_brutos (DBF: TK_I_BRUTO) | C (Character) | 10 |  |  |
| 15 | TK_CUIT | N (Numeric) | 11 | 0 |  |
| 16 | TK_CUENTA | C (Character) | 10 |  |  |
| 17 | PATH_BUP | C (Character) | 40 |  |  |
| 18 | path_importar (DBF: PATH_IMPOR) | C (Character) | 40 |  |  |
| 19 | PAQCONTROL | C (Character) | 13 |  |  |
| 20 | arch_controlrec (DBF: ARCH_CONTR) | C (Character) | 30 |  |  |
| 21 | arch_controlenv (DBF: ARCH_CONT2) | C (Character) | 30 |  |  |
| 22 | PAQENVIAR | C (Character) | 13 |  |  |
| 23 | MAIL | C (Character) | 40 |  |  |
| 24 | ultimo_ajuste_automatico (DBF: ULTIMO_AJU) | N (Numeric) | 10 | 0 |  |
| 25 | contracuenta_recibo (DBF: CONTRACUEN) | I (Integer) | 4 |  | binario (NOCPTRANS) |
| 26 | path_seguridad (DBF: PATH_SEGUR) | C (Character) | 40 |  |  |
| 27 | ultimo_mvstk_mp (DBF: ULTIMO_MV2) | N (Numeric) | 10 | 0 |  |
| 28 | ultima_orden_de_corte (DBF: ULTIMA_ORD) | N (Numeric) | 8 | 0 |  |
| 29 | ultimo_det_corte (DBF: ULTIMO_DET) | N (Numeric) | 8 | 0 |  |
| 30 | porciento_gastos_generales (DBF: PORCIENTO_) | N (Numeric) | 8 | 2 |  |
| 31 | promedio_valor_horario_tizadoycortes (DBF: PROMEDIO_V) | N (Numeric) | 10 | 4 |  |
| 32 | promedio_tiempo_por_prenda_tizadoycortes (DBF: PROMEDIO_T) | N (Numeric) | 10 | 4 |  |
| 33 | IVA | N (Numeric) | 1 | 0 |  |
| 34 | CUIT | N (Numeric) | 11 | 0 |  |
| 35 | cta_cobr_ret1 (DBF: CTA_COBR_R) | N (Numeric) | 4 | 0 |  |
| 36 | cta_cobr_ret2 (DBF: CTA_COBR_2) | N (Numeric) | 4 | 0 |  |
| 37 | cta_cobr_ret3 (DBF: CTA_COBR_3) | N (Numeric) | 4 | 0 |  |
| 38 | cta_cobr_ret4 (DBF: CTA_COBR_4) | N (Numeric) | 4 | 0 |  |
| 39 | fecha_inicio (DBF: FECHA_INIC) | D (Date) | 8 |  |  |
| 40 | FECHA_FIN | D (Date) | 8 |  |  |
| 41 | control_005 (DBF: CONTROL_00) | N (Numeric) | 1 | 0 |  |
| 42 | emailbackup (DBF: EMAILBACKU) | C (Character) | 50 |  |  |
| 43 | path_backup (DBF: PATH_BACKU) | C (Character) | 100 |  |  |
| 44 | envio_email_error (DBF: ENVIO_EMAI) | L (Logical) | 1 |  |  |
| 45 | envio_pantalla (DBF: ENVIO_PANT) | L (Logical) | 1 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Candidate | ASC | `.NOT.DELETED()` |

## pasaje_asientos.DBF

Formato: Visual FoxPro · 7 campos · largo de registro: 89 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | ID | N (Numeric) | 5 | 0 |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 50 |  |  |
| 3 | TIPO | C (Character) | 1 |  |  |
| 4 | fecha_desde (DBF: FECHA_DESD) | D (Date) | 8 |  |  |
| 5 | fecha_hasta (DBF: FECHA_HAST) | D (Date) | 8 |  |  |
| 6 | fecha_pasaje (DBF: FECHA_PASA) | D (Date) | 8 |  |  |
| 7 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| ID | `id` | Candidate | ASC | `.NOT.DELETED()` |
| PASAJE | `fecha_pasaje` | Regular | ASC | `.NOT.DELETED()` |

## pendientes.dbf

Formato: Visual FoxPro · 16 campos · largo de registro: 131 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FECHA | D (Date) | 8 |  |  |
| 2 | TIPO | C (Character) | 2 |  |  |
| 3 | LETRA | C (Character) | 1 |  |  |
| 4 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 5 | PREFIJO | N (Numeric) | 4 | 0 |  |
| 6 | comprobante (DBF: COMPROBANT) | N (Numeric) | 7 | 0 |  |
| 7 | CLIENTE | N (Numeric) | 5 | 0 |  |
| 8 | NETO | N (Numeric) | 11 | 2 |  |
| 9 | ESTADO | C (Character) | 1 |  |  |
| 10 | observaciones (DBF: OBSERVACIO) | C (Character) | 60 |  |  |
| 11 | CANAL | C (Character) | 1 |  |  |
| 12 | TERMINAL | N (Numeric) | 2 | 0 |  |
| 13 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 14 | sucursal_cbte (DBF: SUCURSAL_C) | N (Numeric) | 2 | 0 |  |
| 15 | numero_cbte (DBF: NUMERO_CBT) | N (Numeric) | 8 | 0 |  |
| 16 | FECHA_CBTE | D (Date) | 8 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `tipo+letra+STR(sucursal,2)+STR(comprobante,7)` | Regular | ASC |  |
| RECIBO | `STR(sucursal,2)+STR(comprobante,7)` | Regular | ASC |  |

## pp.DBF

Formato: Visual FoxPro · 16 campos · largo de registro: 302 bytes · registros: 31 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FIELD_NAME | C (Character) | 128 |  |  |
| 2 | FIELD_TYPE | C (Character) | 1 |  |  |
| 3 | FIELD_LEN | N (Numeric) | 3 | 0 |  |
| 4 | FIELD_DEC | N (Numeric) | 3 | 0 |  |
| 5 | FIELD_NULL | L (Logical) | 1 |  |  |
| 6 | FIELD_NOCP | L (Logical) | 1 |  |  |
| 7 | FIELD_DEFA | M (Memo) | 4 |  |  |
| 8 | FIELD_RULE | M (Memo) | 4 |  |  |
| 9 | FIELD_ERR | M (Memo) | 4 |  |  |
| 10 | TABLE_RULE | M (Memo) | 4 |  |  |
| 11 | TABLE_ERR | M (Memo) | 4 |  |  |
| 12 | TABLE_NAME | C (Character) | 128 |  |  |
| 13 | INS_TRIG | M (Memo) | 4 |  |  |
| 14 | UPD_TRIG | M (Memo) | 4 |  |  |
| 15 | DEL_TRIG | M (Memo) | 4 |  |  |
| 16 | TABLE_CMT | M (Memo) | 4 |  |  |

Índices: sin CDX estructural.

## precios_personales.dbf

Formato: Visual FoxPro · 5 campos · largo de registro: 48 bytes · registros: 594 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CLIENTE | N (Numeric) | 5 | 0 |  |
| 2 | FECHA | D (Date) | 8 |  |  |
| 3 | ARTICULO | C (Character) | 15 |  |  |
| 4 | PRECIO | N (Numeric) | 11 | 4 |  |
| 5 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| ORD | `STR(cliente,5)+articulo` | Regular | ASC |  |

## presu_cab.dbf

Formato: Visual FoxPro · 19 campos · largo de registro: 189 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FECHA | D (Date) | 8 |  |  |
| 2 | TIPO | C (Character) | 2 |  |  |
| 3 | LETRA | C (Character) | 1 |  |  |
| 4 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 5 | PREFIJO | N (Numeric) | 4 | 0 |  |
| 6 | comprobante (DBF: COMPROBANT) | N (Numeric) | 7 | 0 |  |
| 7 | CLIENTE | N (Numeric) | 5 | 0 |  |
| 8 | TOTAL | N (Numeric) | 11 | 2 |  |
| 9 | ESTADO | C (Character) | 1 |  |  |
| 10 | observaciones (DBF: OBSERVACIO) | C (Character) | 60 |  |  |
| 11 | observaciones2 (DBF: OBSERVACI2) | C (Character) | 60 |  |  |
| 12 | CONTOTAL | C (Character) | 1 |  |  |
| 13 | CANAL | C (Character) | 1 |  |  |
| 14 | TERMINAL | N (Numeric) | 2 | 0 |  |
| 15 | iva_cliente (DBF: IVA_CLIENT) | N (Numeric) | 1 | 0 |  |
| 16 | cuit_cliente (DBF: CUIT_CLIEN) | N (Numeric) | 11 | 0 |  |
| 17 | VENDEDOR | C (Character) | 2 |  |  |
| 18 | LISTA_CLI | C (Character) | 1 |  |  |
| 19 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `STR(comprobante,7)` | Regular | ASC |  |

## presu_item.dbf

Formato: Visual FoxPro · 19 campos · largo de registro: 128 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FECHA | D (Date) | 8 |  |  |
| 2 | TIPO | C (Character) | 2 |  |  |
| 3 | LETRA | C (Character) | 1 |  |  |
| 4 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 5 | PREFIJO | N (Numeric) | 4 | 0 |  |
| 6 | comprobante (DBF: COMPROBANT) | N (Numeric) | 7 | 0 |  |
| 7 | PEDIDO | N (Numeric) | 7 | 0 |  |
| 8 | ARTICULO | C (Character) | 15 |  |  |
| 9 | codigo_de_barra (DBF: CODIGO_DE_) | N (Numeric) | 13 | 0 |  |
| 10 | BULTOS | N (Numeric) | 3 | 0 |  |
| 11 | UNIDADES | N (Numeric) | 11 | 2 |  |
| 12 | KILOS | N (Numeric) | 11 | 3 |  |
| 13 | LISTA | C (Character) | 1 |  |  |
| 14 | PRECIO | N (Numeric) | 11 | 4 |  |
| 15 | porcentaje_descuento (DBF: PORCENTAJE) | N (Numeric) | 6 | 2 |  |
| 16 | TOTAL_ITEM | N (Numeric) | 11 | 4 |  |
| 17 | CLIENTE | N (Numeric) | 5 | 0 |  |
| 18 | GRABADO | L (Logical) | 1 |  |  |
| 19 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `STR(comprobante,7)` | Regular | ASC |  |

## productos_en_procesos.DBF

Formato: Visual FoxPro · 7 campos · largo de registro: 74 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NUMERO | N (Numeric) | 10 | 0 |  |
| 2 | FECHA | D (Date) | 8 |  |  |
| 3 | ARTICULO | C (Character) | 15 |  |  |
| 4 | UNIDADES | N (Numeric) | 11 | 2 |  |
| 5 | SALDO | N (Numeric) | 11 | 2 |  |
| 6 | numero_de_recepcion (DBF: NUMERO_DE_) | N (Numeric) | 10 | 0 |  |
| 7 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `STR(numero,8)+articulo` | Regular | ASC |  |
| NRO_RECEP | `numero_de_recepcion` | Regular | ASC |  |
| NUMERO | `numero` | Regular | ASC |  |

## productos_relacionados.dbf

Formato: Visual FoxPro · 9 campos · largo de registro: 91 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 2 | ORIGINAL | C (Character) | 15 |  |  |
| 3 | transformado (DBF: TRANSFORMA) | C (Character) | 15 |  |  |
| 4 | PICADA | C (Character) | 15 |  |  |
| 5 | MERMA | C (Character) | 15 |  |  |
| 6 | porcen_picada (DBF: PORCEN_PIC) | N (Numeric) | 5 | 2 |  |
| 7 | porcen_merma (DBF: PORCEN_MER) | N (Numeric) | 5 | 2 |  |
| 8 | cant_a_convertir (DBF: CANT_A_CON) | N (Numeric) | 10 | 0 |  |
| 9 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `STR(sucursal,2)+original+transformado` | Candidate | ASC | `.NOT.DELETED()` |

## PROVE.DBF

Formato: FoxBASE+/dBase III · 23 campos · largo de registro: 407 bytes · registros: 0 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODPROVEED | N (Numeric) | 5 | 0 |  |
| 2 | RAZSOCIAL | C (Character) | 30 |  |  |
| 3 | DOMICILIO | C (Character) | 30 |  |  |
| 4 | LOCALIDAD | C (Character) | 20 |  |  |
| 5 | CODPROVIN | N (Numeric) | 2 | 0 |  |
| 6 | CODPOSTAL | C (Character) | 5 |  |  |
| 7 | TELEFONO | C (Character) | 30 |  |  |
| 8 | CATIVA | N (Numeric) | 1 | 0 |  |
| 9 | NROCUIT | C (Character) | 13 |  |  |
| 10 | FORMAPAGO | C (Character) | 3 |  |  |
| 11 | VENDEDOR | C (Character) | 30 |  |  |
| 12 | VEN_TELEFO | C (Character) | 30 |  |  |
| 13 | OBSERVACIO | C (Character) | 40 |  |  |
| 14 | AG_RETENC | N (Numeric) | 2 | 0 |  |
| 15 | TIPOPROV | C (Character) | 3 |  |  |
| 16 | ALAORDENDE | C (Character) | 45 |  |  |
| 17 | ANTCCT | N (Numeric) | 10 | 2 |  |
| 18 | SALCCT | N (Numeric) | 10 | 2 |  |
| 19 | IVA | N (Numeric) | 5 | 2 |  |
| 20 | LEY3125 | N (Numeric) | 2 | 0 |  |
| 21 | FAX | C (Character) | 30 |  |  |
| 22 | CELULAR | C (Character) | 30 |  |  |
| 23 | EMAIL | C (Character) | 30 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| A_RAZSOC | `razsocial` | Regular | ASC |  |
| A_TIPO | `tipoprov` | Regular | ASC |  |
| A_VENDED | `vendedor` | Regular | ASC |  |
| PROVE | `STR(codproveed,5)` | Regular | ASC |  |

## PROVE_C.DBF

Formato: FoxBASE+/dBase III · 4 campos · largo de registro: 18 bytes · registros: 23 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FIELD_NAME | C (Character) | 10 |  |  |
| 2 | FIELD_TYPE | C (Character) | 1 |  |  |
| 3 | FIELD_LEN | N (Numeric) | 3 | 0 |  |
| 4 | FIELD_DEC | N (Numeric) | 3 | 0 |  |

Índices: sin CDX estructural.

## proveedores.DBF

Formato: Visual FoxPro · 10 campos · largo de registro: 135 bytes · registros: 10 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | N (Numeric) | 5 | 0 |  |
| 2 | NOMBRE | C (Character) | 30 |  |  |
| 3 | DIRECCION | C (Character) | 30 |  |  |
| 4 | LOCALIDAD | C (Character) | 30 |  |  |
| 5 | PROVINCIA | C (Character) | 2 |  |  |
| 6 | CODIGO_POS | N (Numeric) | 5 | 0 |  |
| 7 | TELEFONO | N (Numeric) | 10 | 0 |  |
| 8 | FAX | N (Numeric) | 10 | 0 |  |
| 9 | IVA | N (Numeric) | 1 | 0 |  |
| 10 | CUIT | N (Numeric) | 11 | 0 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Candidate | ASC | `.NOT.DELETED()` |

## provincias.DBF

Formato: Visual FoxPro · 2 campos · largo de registro: 33 bytes · registros: 24 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | C (Character) | 2 |  |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 30 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Candidate | ASC | `.NOT.DELETED()` |

## recepcion_taller_cab.DBF

Formato: Visual FoxPro · 8 campos · largo de registro: 46 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NUMERO | N (Numeric) | 10 | 0 |  |
| 2 | FECHA | D (Date) | 8 |  |  |
| 3 | TALLER | C (Character) | 3 |  |  |
| 4 | ESTADO | C (Character) | 1 |  |  |
| 5 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 6 | observaciones (DBF: OBSERVACIO) | M (Memo) | 4 |  |  |
| 7 | NRO_MVSTK | N (Numeric) | 10 | 0 |  |
| 8 | prod_term_interm (DBF: PROD_TERM_) | N (Numeric) | 1 | 0 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| NUMERO | `numero` | Candidate | ASC | `.NOT.DELETED()` |

## recepcion_taller_det.dbf

Formato: Visual FoxPro · 7 campos · largo de registro: 65 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NUMERO | N (Numeric) | 10 | 0 |  |
| 2 | ESTADO | C (Character) | 1 |  |  |
| 3 | ARTICULO | C (Character) | 15 |  |  |
| 4 | UNIDADES | N (Numeric) | 11 | 2 |  |
| 5 | SALDO | N (Numeric) | 11 | 2 |  |
| 6 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 7 | nroliquidacion (DBF: NROLIQUIDA) | N (Numeric) | 8 | 0 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| ARTICULO | `STR(numero)+articulo` | Regular | ASC | `.NOT.DELETED()` |
| NUMERO | `numero` | Regular | ASC | `.NOT.DELETED()` |

## remitos_cab.dbf

Formato: Visual FoxPro · 43 campos · largo de registro: 492 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FECHA | D (Date) | 8 |  |  |
| 2 | TIPO | C (Character) | 2 |  |  |
| 3 | LETRA | C (Character) | 1 |  |  |
| 4 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 5 | PREFIJO | N (Numeric) | 4 | 0 |  |
| 6 | comprobante (DBF: COMPROBANT) | N (Numeric) | 7 | 0 |  |
| 7 | CLIENTE | N (Numeric) | 5 | 0 |  |
| 8 | BRUTO | N (Numeric) | 11 | 2 |  |
| 9 | neto_grabado (DBF: NETO_GRABA) | N (Numeric) | 11 | 2 |  |
| 10 | neto_exento (DBF: NETO_EXENT) | N (Numeric) | 11 | 2 |  |
| 11 | IVA_1 | N (Numeric) | 11 | 2 |  |
| 12 | IVA_2 | N (Numeric) | 11 | 2 |  |
| 13 | LIBERADO | N (Numeric) | 11 | 2 |  |
| 14 | bonificacion (DBF: BONIFICACI) | N (Numeric) | 11 | 2 |  |
| 15 | NETO | N (Numeric) | 11 | 2 |  |
| 16 | porcentaje_descuento (DBF: PORCENTAJE) | N (Numeric) | 6 | 2 |  |
| 17 | ESTADO | C (Character) | 1 |  |  |
| 18 | observaciones (DBF: OBSERVACIO) | C (Character) | 60 |  |  |
| 19 | CANAL | C (Character) | 1 |  |  |
| 20 | TERMINAL | N (Numeric) | 2 | 0 |  |
| 21 | iva_cliente (DBF: IVA_CLIENT) | N (Numeric) | 1 | 0 |  |
| 22 | cuit_cliente (DBF: CUIT_CLIEN) | N (Numeric) | 11 | 0 |  |
| 23 | recargo_f_pago (DBF: RECARGO_F_) | N (Numeric) | 11 | 2 |  |
| 24 | IMPUESTO | N (Numeric) | 5 | 2 |  |
| 25 | RECIBO | N (Numeric) | 10 | 0 |  |
| 26 | MVSTK | N (Numeric) | 10 | 0 |  |
| 27 | COBRO | D (Date) | 8 |  |  |
| 28 | CIERRE | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 29 | numero_cierre (DBF: NUMERO_CIE) | N (Numeric) | 10 | 0 |  |
| 30 | VENDEDOR | C (Character) | 2 |  |  |
| 31 | FACTURADO | D (Date) | 8 |  |  |
| 32 | VALIDES | D (Date) | 8 |  |  |
| 33 | FORMA_PAGO | N (Numeric) | 1 | 0 |  |
| 34 | UNIDADES | N (Numeric) | 11 | 2 |  |
| 35 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 36 | TRANSPORTE | N (Numeric) | 3 | 0 |  |
| 37 | FACTURA | C (Character) | 40 |  |  |
| 38 | BULTOS | N (Numeric) | 7 | 0 |  |
| 39 | VALOR | N (Numeric) | 12 | 2 |  |
| 40 | DESTINO | C (Character) | 60 |  |  |
| 41 | orden_compra (DBF: ORDEN_COMP) | C (Character) | 20 |  |  |
| 42 | CONDUCTOR | C (Character) | 40 |  |  |
| 43 | PATENTE | C (Character) | 10 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `tipo+letra+STR(sucursal,2)+STR(comprobante,7)` | Regular | ASC |  |
| COMPRO | `tipo+STR(sucursal,2)+STR(comprobante,7)` | Regular | ASC | `.NOT.DELETED()` |

## remitos_item.dbf

Formato: Visual FoxPro · 23 campos · largo de registro: 184 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FECHA | D (Date) | 8 |  |  |
| 2 | TIPO | C (Character) | 2 |  |  |
| 3 | LETRA | C (Character) | 1 |  |  |
| 4 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 5 | PREFIJO | N (Numeric) | 4 | 0 |  |
| 6 | comprobante (DBF: COMPROBANT) | N (Numeric) | 7 | 0 |  |
| 7 | ARTICULO | C (Character) | 15 |  |  |
| 8 | codigo_de_barra (DBF: CODIGO_DE_) | N (Numeric) | 13 | 0 |  |
| 9 | BULTOS | N (Numeric) | 3 | 0 |  |
| 10 | UNIDADES | N (Numeric) | 11 | 2 |  |
| 11 | KILOS | N (Numeric) | 11 | 3 |  |
| 12 | SALDO | N (Numeric) | 11 | 2 |  |
| 13 | LISTA | C (Character) | 3 |  |  |
| 14 | PRECIO | N (Numeric) | 11 | 4 |  |
| 15 | porcentaje_descuento (DBF: PORCENTAJE) | N (Numeric) | 6 | 2 |  |
| 16 | TOTAL_ITEM | N (Numeric) | 11 | 4 |  |
| 17 | CLIENTE | N (Numeric) | 5 | 0 |  |
| 18 | COSTO | N (Numeric) | 11 | 4 |  |
| 19 | fecha_ult_factura (DBF: FECHA_ULT_) | D (Date) | 8 |  |  |
| 20 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 21 | NOTAPED | N (Numeric) | 8 | 0 |  |
| 22 | orden_compra (DBF: ORDEN_COMP) | C (Character) | 20 |  |  |
| 23 | observaciones (DBF: OBSERVACIO) | M (Memo) | 4 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `tipo+letra+STR(sucursal,2)+STR(comprobante,7)+articulo` | Regular | ASC |  |

## REPORTS/OutputConfig.DBF

Formato: Visual FoxPro (Varchar/Varbinary/Blob) · 6 campos · largo de registro: 134 bytes · registros: 30 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | OBJTYPE | I (Integer) | 4 |  | binario (NOCPTRANS) |
| 2 | OBJCODE | I (Integer) | 4 |  | binario (NOCPTRANS) |
| 3 | OBJNAME | V (Varchar) | 60 |  |  |
| 4 | OBJVALUE | V (Varchar) | 60 |  |  |
| 5 | OBJINFO | M (Memo) | 4 |  |  |
| 6 | _NullFlags | 0 ((sistema: _NullFlags)) | 1 |  | binario (NOCPTRANS), columna de sistema |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| FRXNODES | `objtype+objcode+IIF(objtype=1109,500,0)` | Regular | ASC |  |
| OBJCODE | `objcode` | Regular | ASC |  |
| OBJNAME | `objname` | Regular | ASC |  |
| OBJTYPE | `objtype` | Regular | ASC |  |
| OBJVALUE | `objvalue` | Regular | ASC |  |
| ONDELETED | `DELETED()` | Regular | ASC |  |

## rollos_telas.dbf

Formato: Visual FoxPro · 7 campos · largo de registro: 78 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NUMERO | C (Character) | 20 |  |  |
| 2 | FECHA | D (Date) | 8 |  |  |
| 3 | CANTIDAD | N (Numeric) | 8 | 2 |  |
| 4 | SALDO | N (Numeric) | 8 | 2 |  |
| 5 | numero_mvstk (DBF: NUMERO_MVS) | N (Numeric) | 10 | 0 |  |
| 6 | CODIGO_MP | C (Character) | 15 |  |  |
| 7 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| NUMERO | `numero` | Regular | ASC | `.NOT.DELETED()` |

## ROLLOS_TELAS_DET.DBF

Formato: Visual FoxPro · 5 campos · largo de registro: 55 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NUMERO | C (Character) | 20 |  |  |
| 2 | FECHA | D (Date) | 8 |  |  |
| 3 | CANTIDAD | N (Numeric) | 8 | 2 |  |
| 4 | NRO_ORDEN | N (Numeric) | 10 | 0 |  |
| 5 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| NUMERO | `numero` | Regular | ASC | `.NOT.DELETED()` |

## ropateck_Prod_ref.DBF

Formato: Visual FoxPro · 21 campos · largo de registro: 112 bytes · registros: 33 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | UNIQUEID | C (Character) | 10 |  |  |
| 2 | SETID | C (Character) | 10 |  |  |
| 3 | REFID | C (Character) | 10 |  |  |
| 4 | REFTYPE | C (Character) | 1 |  |  |
| 5 | FINDTYPE | C (Character) | 1 |  |  |
| 6 | FILEID | C (Character) | 10 |  |  |
| 7 | SYMBOL | M (Memo) | 4 |  |  |
| 8 | CLASSNAME | M (Memo) | 4 |  |  |
| 9 | PROCNAME | M (Memo) | 4 |  |  |
| 10 | PROCLINENO | I (Integer) | 4 |  | binario (NOCPTRANS) |
| 11 | LINENO | I (Integer) | 4 |  | binario (NOCPTRANS) |
| 12 | COLPOS | I (Integer) | 4 |  | binario (NOCPTRANS) |
| 13 | MATCHLEN | I (Integer) | 4 |  | binario (NOCPTRANS) |
| 14 | ABSTRACT | M (Memo) | 4 |  |  |
| 15 | RECORDID | C (Character) | 10 |  |  |
| 16 | UPDFIELD | C (Character) | 15 |  |  |
| 17 | CHECKED | L (Logical) | 1 |  | admite NULL |
| 18 | NOREPLACE | L (Logical) | 1 |  |  |
| 19 | TIMESTAMP | T (DateTime) | 8 |  | admite NULL, binario (NOCPTRANS) |
| 20 | INACTIVE | L (Logical) | 1 |  |  |
| 21 | _NullFlags | 0 ((sistema: _NullFlags)) | 1 |  | binario (NOCPTRANS), columna de sistema |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CHECKED | `checked` | Regular | ASC |  |
| FILEID | `fileid` | Regular | ASC |  |
| INACTIVE | `inactive` | Regular | ASC |  |
| REFID | `refid` | Regular | ASC |  |
| REFTYPE | `reftype` | Regular | ASC |  |
| SETID | `setid` | Regular | ASC |  |
| UNIQUEID | `uniqueid` | Regular | ASC |  |

## Ropateck_ref.DBF

Formato: Visual FoxPro · 21 campos · largo de registro: 112 bytes · registros: 385 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | UNIQUEID | C (Character) | 10 |  |  |
| 2 | SETID | C (Character) | 10 |  |  |
| 3 | REFID | C (Character) | 10 |  |  |
| 4 | REFTYPE | C (Character) | 1 |  |  |
| 5 | FINDTYPE | C (Character) | 1 |  |  |
| 6 | FILEID | C (Character) | 10 |  |  |
| 7 | SYMBOL | M (Memo) | 4 |  |  |
| 8 | CLASSNAME | M (Memo) | 4 |  |  |
| 9 | PROCNAME | M (Memo) | 4 |  |  |
| 10 | PROCLINENO | I (Integer) | 4 |  | binario (NOCPTRANS) |
| 11 | LINENO | I (Integer) | 4 |  | binario (NOCPTRANS) |
| 12 | COLPOS | I (Integer) | 4 |  | binario (NOCPTRANS) |
| 13 | MATCHLEN | I (Integer) | 4 |  | binario (NOCPTRANS) |
| 14 | ABSTRACT | M (Memo) | 4 |  |  |
| 15 | RECORDID | C (Character) | 10 |  |  |
| 16 | UPDFIELD | C (Character) | 15 |  |  |
| 17 | CHECKED | L (Logical) | 1 |  | admite NULL |
| 18 | NOREPLACE | L (Logical) | 1 |  |  |
| 19 | TIMESTAMP | T (DateTime) | 8 |  | admite NULL, binario (NOCPTRANS) |
| 20 | INACTIVE | L (Logical) | 1 |  |  |
| 21 | _NullFlags | 0 ((sistema: _NullFlags)) | 1 |  | binario (NOCPTRANS), columna de sistema |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CHECKED | `checked` | Regular | ASC |  |
| FILEID | `fileid` | Regular | ASC |  |
| INACTIVE | `inactive` | Regular | ASC |  |
| REFID | `refid` | Regular | ASC |  |
| REFTYPE | `reftype` | Regular | ASC |  |
| SETID | `setid` | Regular | ASC |  |
| UNIQUEID | `uniqueid` | Regular | ASC |  |

## RUBROS.DBF

Formato: Visual FoxPro · 6 campos · largo de registro: 57 bytes · registros: 2 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | C (Character) | 3 |  |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 30 |  |  |
| 3 | porcentaje_rubro_l1 (DBF: PORCENTAJE) | N (Numeric) | 5 | 2 |  |
| 4 | porcentaje_rubro_l2 (DBF: PORCENTAJ2) | N (Numeric) | 5 | 2 |  |
| 5 | porcentaje_rubro_l3 (DBF: PORCENTAJ3) | N (Numeric) | 5 | 2 |  |
| 6 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Candidate | ASC | `.NOT.DELETED()` |

## rubros_materias_primas.dbf

Formato: Visual FoxPro · 3 campos · largo de registro: 38 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | C (Character) | 3 |  |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 30 |  |  |
| 3 | NUMERADOR | N (Numeric) | 4 | 0 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Regular | ASC |  |
| DESCRIP | `descripcion` | Regular | ASC |  |

## scaner.DBF

Formato: Visual FoxPro · 5 campos · largo de registro: 82 bytes · registros: 2834 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | BARRAS | N (Numeric) | 13 | 0 |  |
| 2 | DESCRIP | C (Character) | 40 |  |  |
| 3 | BULTOS | N (Numeric) | 6 | 0 |  |
| 4 | UNIDADES | N (Numeric) | 10 | 0 |  |
| 5 | KILOS | N (Numeric) | 12 | 3 |  |

Índices: sin CDX estructural.

## STOCK.DBF

Formato: Visual FoxPro · 5 campos · largo de registro: 50 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 2 | ARTICULO | C (Character) | 15 |  |  |
| 3 | UNIDADES | N (Numeric) | 12 | 2 |  |
| 4 | KILOS | N (Numeric) | 12 | 3 |  |
| 5 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| ARTICULO | `articulo` | Regular | ASC |  |
| SUCURSAL | `STR(sucursal,2)+articulo` | Regular | ASC |  |

## STOCK2.DBF

Formato: Visual FoxPro · 5 campos · largo de registro: 50 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 2 | ARTICULO | C (Character) | 15 |  |  |
| 3 | UNIDADES | N (Numeric) | 12 | 0 |  |
| 4 | KILOS | N (Numeric) | 12 | 2 |  |
| 5 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| ARTICULO | `articulo` | Regular | ASC |  |
| SUCURSAL | `STR(sucursal,2)+articulo` | Regular | ASC |  |

## subdiario.dbf

Formato: Visual FoxPro (autoincremental) · 57 campos · largo de registro: 554 bytes · registros: 3396 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FECHA | D (Date) | 8 |  |  |
| 2 | TIPO | C (Character) | 2 |  |  |
| 3 | LETRA | C (Character) | 1 |  |  |
| 4 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 5 | PREFIJO | N (Numeric) | 4 | 0 |  |
| 6 | COMPROBANT | N (Numeric) | 7 | 0 |  |
| 7 | CLIENTE | N (Numeric) | 5 | 0 |  |
| 8 | BRUTO | N (Numeric) | 11 | 2 |  |
| 9 | NETO_GRABA | N (Numeric) | 11 | 2 |  |
| 10 | NETO_EXENT | N (Numeric) | 11 | 2 |  |
| 11 | IVA_1 | N (Numeric) | 11 | 2 |  |
| 12 | IVA_2 | N (Numeric) | 11 | 2 |  |
| 13 | LIBERADO | N (Numeric) | 11 | 2 |  |
| 14 | BONIFICACI | N (Numeric) | 11 | 2 |  |
| 15 | NETO | N (Numeric) | 11 | 2 |  |
| 16 | PORCENTAJE | N (Numeric) | 6 | 2 |  |
| 17 | ESTADO | C (Character) | 1 |  |  |
| 18 | OBSERVACIO | C (Character) | 60 |  |  |
| 19 | CANAL | C (Character) | 1 |  |  |
| 20 | TERMINAL | N (Numeric) | 2 | 0 |  |
| 21 | IVA_CLIENT | N (Numeric) | 1 | 0 |  |
| 22 | CUIT_CLIEN | N (Numeric) | 11 | 0 |  |
| 23 | RECARGO_F_ | N (Numeric) | 11 | 2 |  |
| 24 | IMPUESTO | N (Numeric) | 5 | 2 |  |
| 25 | RECIBO | N (Numeric) | 10 | 0 |  |
| 26 | MVSTK | N (Numeric) | 10 | 0 |  |
| 27 | COBRO | D (Date) | 8 |  |  |
| 28 | CIERRE | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 29 | NUMERO_CIE | N (Numeric) | 10 | 0 |  |
| 30 | VENDEDOR | C (Character) | 2 |  |  |
| 31 | FORMA_PAGO | N (Numeric) | 1 | 0 |  |
| 32 | CIERRE_Z | N (Numeric) | 10 | 0 |  |
| 33 | ACTUALIZAC | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 34 | ORDEN_COMP | C (Character) | 20 |  |  |
| 35 | RG4059 | N (Numeric) | 11 | 2 |  |
| 36 | RG3661 | N (Numeric) | 11 | 2 |  |
| 37 | FE_CLAVE | I (Integer) | 4 |  | binario (NOCPTRANS), autoincremental |
| 38 | FE_NROCBTE | N (Numeric) | 9 | 0 | admite NULL |
| 39 | FE_IID | N (Numeric) | 13 | 0 |  |
| 40 | FE_CCAE | C (Character) | 30 |  |  |
| 41 | FE_DFECHA | D (Date) | 8 |  |  |
| 42 | FE_LIMPRIM | L (Logical) | 1 |  | admite NULL |
| 43 | FE_CEMAILE | C (Character) | 100 |  |  |
| 44 | FE_DFECHAE | D (Date) | 8 |  |  |
| 45 | FE_MOBSERV | M (Memo) | 4 |  |  |
| 46 | FE_LRECHAZ | L (Logical) | 1 |  | admite NULL |
| 47 | FE_NTIPOAF | N (Numeric) | 3 | 0 | admite NULL |
| 48 | COND_PAGO | C (Character) | 2 |  |  |
| 49 | FE_DVTOCAE | D (Date) | 8 |  |  |
| 50 | NROINTERNO | N (Numeric) | 7 | 0 |  |
| 51 | ELECTRONIC | L (Logical) | 1 |  |  |
| 52 | TIPOAFIPOY | N (Numeric) | 3 | 0 |  |
| 53 | FE_VENCIMI | D (Date) | 8 |  |  |
| 54 | FE_TIPOCBT | C (Character) | 3 |  |  |
| 55 | FE_NROCBT2 | N (Numeric) | 7 | 0 |  |
| 56 | FE_FECHA_A | D (Date) | 8 |  |  |
| 57 | _NullFlags | 0 ((sistema: _NullFlags)) | 1 |  | binario (NOCPTRANS), columna de sistema |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CIERRE_Z | `cierre_z` | Regular | ASC |  |
| CLAVE | `tipo+letra+STR(sucursal,2)+STR(comprobante,7)` | Regular | ASC |  |
| FE_CLAVE | `fe_clave` | Candidate | ASC |  |

## subrubros.dbf

Formato: Visual FoxPro · 7 campos · largo de registro: 60 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | RUBRO | C (Character) | 3 |  |  |
| 2 | CODIGO | C (Character) | 3 |  |  |
| 3 | descripcion (DBF: DESCRIPCIO) | C (Character) | 30 |  |  |
| 4 | porcentaje_subrubro_l1 (DBF: PORCENTAJE) | N (Numeric) | 5 | 2 |  |
| 5 | porcentaje_subrubro_l2 (DBF: PORCENTAJ2) | N (Numeric) | 5 | 2 |  |
| 6 | porcentaje_subrubro_l3 (DBF: PORCENTAJ3) | N (Numeric) | 5 | 2 |  |
| 7 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `rubro+codigo` | Candidate | ASC | `.NOT.DELETED()` |

## SUC_ART.DBF

Formato: Visual FoxPro · 30 campos · largo de registro: 215 bytes · registros: 2739 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | N (Numeric) | 7 | 0 |  |
| 2 | DESCRIPCIO | C (Character) | 40 |  |  |
| 3 | CODIGO_ASO | N (Numeric) | 7 | 0 |  |
| 4 | RUBRO | C (Character) | 3 |  |  |
| 5 | SUBRUBRO | C (Character) | 3 |  |  |
| 6 | PROVEEDOR | N (Numeric) | 5 | 0 |  |
| 7 | CODIGO_DE_ | N (Numeric) | 13 | 0 |  |
| 8 | UNIDAD_DE_ | C (Character) | 2 |  |  |
| 9 | UNIDADES_P | N (Numeric) | 3 | 0 |  |
| 10 | CANTIDAD_D | N (Numeric) | 6 | 0 |  |
| 11 | FORMA_DE_V | N (Numeric) | 1 | 0 |  |
| 12 | ESTADO | C (Character) | 2 |  |  |
| 13 | STOCK_MINI | N (Numeric) | 6 | 0 |  |
| 14 | STOCK_MIN2 | N (Numeric) | 9 | 3 |  |
| 15 | BONIFICACI | N (Numeric) | 6 | 2 |  |
| 16 | BONIFICAC2 | N (Numeric) | 6 | 2 |  |
| 17 | BONIFICAC3 | N (Numeric) | 3 | 0 |  |
| 18 | BONIFICAC4 | N (Numeric) | 3 | 0 |  |
| 19 | PRECIO_REP | N (Numeric) | 9 | 2 |  |
| 20 | COSTO_ULTI | N (Numeric) | 9 | 2 |  |
| 21 | COSTO_REPO | N (Numeric) | 9 | 2 |  |
| 22 | PRECIO_DE_ | N (Numeric) | 9 | 2 |  |
| 23 | PRECIO_DE2 | N (Numeric) | 9 | 2 |  |
| 24 | PRECIO_DE3 | N (Numeric) | 9 | 2 |  |
| 25 | PRECIO_DE4 | N (Numeric) | 9 | 2 |  |
| 26 | FECHA_ULTI | D (Date) | 8 |  |  |
| 27 | MARGEN | N (Numeric) | 5 | 2 |  |
| 28 | BASE_COSTO | N (Numeric) | 3 | 0 |  |
| 29 | PRECIO_UNI | N (Numeric) | 9 | 2 |  |
| 30 | LISTA_FORZ | L (Logical) | 1 |  |  |

Índices: sin CDX estructural.

## sucursales.dbf

Formato: Visual FoxPro · 71 campos · largo de registro: 804 bytes · registros: 1 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | N (Numeric) | 2 | 0 |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 30 |  |  |
| 3 | DIRECCION | C (Character) | 30 |  |  |
| 4 | LISTA_1_2 | L (Logical) | 1 |  |  |
| 5 | LISTA_2_3 | L (Logical) | 1 |  |  |
| 6 | lista_oferta (DBF: LISTA_OFER) | L (Logical) | 1 |  |  |
| 7 | PREFIJO | N (Numeric) | 4 | 0 |  |
| 8 | ultima_factura_a (DBF: ULTIMA_FAC) | N (Numeric) | 10 | 0 |  |
| 9 | ultima_factura_b (DBF: ULTIMA_FA2) | N (Numeric) | 10 | 0 |  |
| 10 | ultima_factura_a_fis (DBF: ULTIMA_FA3) | N (Numeric) | 10 | 0 |  |
| 11 | ultima_factura_b_fis (DBF: ULTIMA_FA4) | N (Numeric) | 10 | 0 |  |
| 12 | ultimo_tickets (DBF: ULTIMO_TIC) | N (Numeric) | 10 | 0 |  |
| 13 | ultimo_recibo (DBF: ULTIMO_REC) | N (Numeric) | 10 | 0 |  |
| 14 | ultimo_pedido (DBF: ULTIMO_PED) | N (Numeric) | 10 | 0 |  |
| 15 | ultimo_presupuesto (DBF: ULTIMO_PRE) | N (Numeric) | 10 | 0 |  |
| 16 | ultimo_preliq (DBF: ULTIMO_PR2) | N (Numeric) | 10 | 0 |  |
| 17 | IVA_1 | N (Numeric) | 5 | 2 |  |
| 18 | IVA_2 | N (Numeric) | 5 | 2 |  |
| 19 | can_mayorista (DBF: CAN_MAYORI) | N (Numeric) | 2 | 0 |  |
| 20 | kil_mayorista (DBF: KIL_MAYORI) | N (Numeric) | 6 | 3 |  |
| 21 | can_minorista (DBF: CAN_MINORI) | N (Numeric) | 2 | 0 |  |
| 22 | kil_minorista (DBF: KIL_MINORI) | N (Numeric) | 6 | 3 |  |
| 23 | MA_A | N (Numeric) | 1 | 0 |  |
| 24 | MA_B | N (Numeric) | 1 | 0 |  |
| 25 | MA_T | N (Numeric) | 1 | 0 |  |
| 26 | MI_A | N (Numeric) | 1 | 0 |  |
| 27 | MI_B | N (Numeric) | 1 | 0 |  |
| 28 | MI_T | N (Numeric) | 1 | 0 |  |
| 29 | comprobante_manual (DBF: COMPROBANT) | L (Logical) | 1 |  |  |
| 30 | ultimo_remito (DBF: ULTIMO_REM) | N (Numeric) | 10 | 0 |  |
| 31 | COM | I (Integer) | 4 |  | binario (NOCPTRANS) |
| 32 | controlador_fiscal (DBF: CONTROLADO) | L (Logical) | 1 |  |  |
| 33 | ultima_orden_compra (DBF: ULTIMA_ORD) | N (Numeric) | 10 | 0 |  |
| 34 | fecha_ult_transmision (DBF: FECHA_ULT_) | D (Date) | 8 |  |  |
| 35 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |
| 36 | LPT_QA | N (Numeric) | 1 | 0 |  |
| 37 | COPIAS_FAC | N (Numeric) | 1 | 0 |  |
| 38 | COPIAS_REM | N (Numeric) | 1 | 0 |  |
| 39 | ultima_factura_e (DBF: ULTIMA_FA5) | N (Numeric) | 10 | 0 |  |
| 40 | cantidad_items (DBF: CANTIDAD_I) | N (Numeric) | 4 | 0 |  |
| 41 | ultima_notped (DBF: ULTIMA_NOT) | N (Numeric) | 10 | 0 |  |
| 42 | copias_notped (DBF: COPIAS_NOT) | N (Numeric) | 1 | 0 |  |
| 43 | copias_oc_mp (DBF: COPIAS_OC_) | N (Numeric) | 1 | 0 |  |
| 44 | ultimo_envio_taller (DBF: ULTIMO_ENV) | N (Numeric) | 10 | 0 |  |
| 45 | ultimo_recepcion_taller (DBF: ULTIMO_RE2) | N (Numeric) | 10 | 0 |  |
| 46 | ultimo_nroliquidacion_a_talleres (DBF: ULTIMO_NRO) | N (Numeric) | 8 | 0 |  |
| 47 | ult_prod_en_proceso (DBF: ULT_PROD_E) | N (Numeric) | 10 | 0 |  |
| 48 | ultima_nd_a (DBF: ULTIMA_ND_) | N (Numeric) | 10 | 0 |  |
| 49 | ultima_nd_b (DBF: ULTIMA_ND2) | N (Numeric) | 10 | 0 |  |
| 50 | ultima_nc_a (DBF: ULTIMA_NC_) | N (Numeric) | 10 | 0 |  |
| 51 | ultima_nc_b (DBF: ULTIMA_NC2) | N (Numeric) | 10 | 0 |  |
| 52 | COPIAS_ND | N (Numeric) | 1 | 0 |  |
| 53 | COPIAS_NC | N (Numeric) | 1 | 0 |  |
| 54 | imprime_factura (DBF: IMPRIME_FA) | L (Logical) | 1 |  |  |
| 55 | imprime_nd_nc_vs (DBF: IMPRIME_ND) | L (Logical) | 1 |  |  |
| 56 | imprime_recibo (DBF: IMPRIME_RE) | L (Logical) | 1 |  |  |
| 57 | usa_lector_cheque (DBF: USA_LECTOR) | L (Logical) | 1 |  |  |
| 58 | pathelectronica (DBF: PATHELECTR) | C (Character) | 140 |  |  |
| 59 | smtpauthenticate (DBF: SMTPAUTHEN) | C (Character) | 40 |  |  |
| 60 | sendusername (DBF: SENDUSERNA) | C (Character) | 40 |  |  |
| 61 | sendpassword (DBF: SENDPASSWO) | C (Character) | 40 |  |  |
| 62 | SENDUSING | C (Character) | 40 |  |  |
| 63 | SMTPSERVER | C (Character) | 40 |  |  |
| 64 | smtpserverport (DBF: SMTPSERVE2) | C (Character) | 40 |  |  |
| 65 | smtpconnectiontimeour (DBF: SMTPCONNEC) | C (Character) | 10 |  |  |
| 66 | EMAILENVIO | C (Character) | 50 |  |  |
| 67 | mensajeenvio (DBF: MENSAJEENV) | M (Memo) | 4 |  |  |
| 68 | fac_electronica (DBF: FAC_ELECTR) | L (Logical) | 1 |  |  |
| 69 | ENVIAMAIL | L (Logical) | 1 |  |  |
| 70 | SMTPUSESSL | L (Logical) | 1 |  |  |
| 71 | smtpconnectiontimeout (DBF: SMTPCONNE2) | C (Character) | 10 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Candidate | ASC | `.NOT.DELETED()` |

## talleres.dbf

Formato: Visual FoxPro · 6 campos · largo de registro: 99 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | C (Character) | 3 |  |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 30 |  |  |
| 3 | observaciones (DBF: OBSERVACIO) | M (Memo) | 4 |  |  |
| 4 | DOMICILIO | C (Character) | 30 |  |  |
| 5 | TELEFONO | C (Character) | 30 |  |  |
| 6 | prod_term_interm (DBF: PROD_TERM_) | N (Numeric) | 1 | 0 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Regular | ASC | `.NOT.DELETED()` |
| DESCRIP | `descripcion` | Regular | ASC |  |

## talles_equivalentes.dbf

Formato: Visual FoxPro · 4 campos · largo de registro: 14 bytes · registros: 3 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | MODELO | C (Character) | 4 |  |  |
| 2 | TALLE_EQUI | C (Character) | 5 |  |  |
| 3 | TALLE_DESD | N (Numeric) | 2 | 0 |  |
| 4 | TALLE_HAST | N (Numeric) | 2 | 0 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `modelo+talle_equivalente` | Candidate | ASC | `.NOT.DELETED()` |
| MODELO | `modelo` | Regular | ASC | `.NOT.DELETED()` |

## tasas_iva.dbf

Formato: Visual FoxPro · 4 campos · largo de registro: 46 bytes · registros: 3 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | C (Character) | 2 |  |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 30 |  |  |
| 3 | PORCENTAJE | N (Numeric) | 5 | 2 |  |
| 4 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Candidate | ASC | `.NOT.DELETED()` |

## Telas.DBF

Formato: Visual FoxPro · 2 campos · largo de registro: 34 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | C (Character) | 3 |  |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 30 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Regular | ASC |  |
| DESCRIP | `descripcion` | Regular | ASC |  |

## temptext.DBF

Formato: Visual FoxPro · 1 campos · largo de registro: 201 bytes · registros: 11 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | TESTLINE | C (Character) | 200 |  |  |

Índices: sin CDX estructural.

## tempview.DBF

Formato: Visual FoxPro · 9 campos · largo de registro: 230 bytes · registros: 3 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | TESTFILE | C (Character) | 70 |  |  |
| 2 | TESTFIND | C (Character) | 80 |  |  |
| 3 | TESTOBJ | C (Character) | 25 |  |  |
| 4 | TESTPARENT | C (Character) | 25 |  |  |
| 5 | TESTMEMO | M (Memo) | 4 |  |  |
| 6 | TESTFIELD | C (Character) | 15 |  |  |
| 7 | TESTREC | N (Numeric) | 6 | 0 |  |
| 8 | LISTEXT | L (Logical) | 1 |  |  |
| 9 | FILETYPE | C (Character) | 3 |  |  |

Índices: sin CDX estructural.

## TICKETS.DBF

Formato: Visual FoxPro · 22 campos · largo de registro: 241 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CIERRE_Z | N (Numeric) | 10 | 0 |  |
| 2 | FECHA | D (Date) | 8 |  |  |
| 3 | tickets_desde (DBF: TICKETS_DE) | N (Numeric) | 7 | 0 |  |
| 4 | tickets_hasta (DBF: TICKETS_HA) | N (Numeric) | 7 | 0 |  |
| 5 | GRAVADO | N (Numeric) | 11 | 2 |  |
| 6 | IVA | N (Numeric) | 11 | 2 |  |
| 7 | NETO | N (Numeric) | 11 | 2 |  |
| 8 | observaciones (DBF: OBSERVACIO) | C (Character) | 60 |  |  |
| 9 | EXENTO | N (Numeric) | 11 | 2 |  |
| 10 | ALICUOTA1 | C (Character) | 2 |  |  |
| 11 | MONTO1 | N (Numeric) | 11 | 2 |  |
| 12 | IMPORTE1 | N (Numeric) | 11 | 2 |  |
| 13 | ALICUOTA2 | C (Character) | 2 |  |  |
| 14 | MONTO2 | N (Numeric) | 11 | 2 |  |
| 15 | IMPORTE2 | N (Numeric) | 11 | 2 |  |
| 16 | ALICUOTA3 | C (Character) | 2 |  |  |
| 17 | MONTO3 | N (Numeric) | 11 | 2 |  |
| 18 | IMPORTE3 | N (Numeric) | 11 | 2 |  |
| 19 | ALICUOTA4 | C (Character) | 2 |  |  |
| 20 | MONTO4 | N (Numeric) | 11 | 2 |  |
| 21 | IMPORTE4 | N (Numeric) | 11 | 2 |  |
| 22 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `cierre_z` | Candidate | ASC | `.NOT.DELETED()` |

## tipo_de_cierre.DBF

Formato: Visual FoxPro · 3 campos · largo de registro: 32 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | C (Character) | 3 |  |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 20 |  |  |
| 3 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Regular | ASC | `.NOT.DELETED()` |
| DESCRIP | `descripcion` | Regular | ASC | `.NOT.DELETED()` |

## tipo_de_clientes.DBF

Formato: Visual FoxPro · 3 campos · largo de registro: 41 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | C (Character) | 2 |  |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 30 |  |  |
| 3 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Candidate | ASC | `.NOT.DELETED()` |

## Tipos_de_materia_prima.DBF

Formato: Visual FoxPro · 2 campos · largo de registro: 33 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | C (Character) | 2 |  |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 30 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Regular | ASC |  |
| DESCRIP | `descripcion` | Regular | ASC |  |

## tipos_mvstk.dbf

Formato: Visual FoxPro · 7 campos · largo de registro: 45 bytes · registros: 23 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | C (Character) | 2 |  |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 30 |  |  |
| 3 | ORIGEN | C (Character) | 1 |  |  |
| 4 | DESTINO | C (Character) | 1 |  |  |
| 5 | numeracion_automatica (DBF: NUMERACION) | L (Logical) | 1 |  |  |
| 6 | actualiza_estadistica (DBF: ACTUALIZA_) | L (Logical) | 1 |  |  |
| 7 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Candidate | ASC | `.NOT.DELETED()` |

## tmp_cli.dbf

Formato: FoxBASE+/dBase III · 2 campos · largo de registro: 13 bytes · registros: 65535 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NUMCLI | N (Numeric) | 7 | 0 |  |
| 2 | CODI | N (Numeric) | 5 | 0 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODI | `codi` | Regular | ASC |  |
| NUMCLI | `numcli` | Regular | ASC |  |

## transformaciones.dbf

Formato: Visual FoxPro · 19 campos · largo de registro: 184 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | NUMERO | N (Numeric) | 10 | 0 |  |
| 2 | FECHA | D (Date) | 8 |  |  |
| 3 | SUCURSAL | N (Numeric) | 2 | 0 |  |
| 4 | prod_original (DBF: PROD_ORIGI) | N (Numeric) | 7 | 0 |  |
| 5 | original_unidades (DBF: ORIGINAL_U) | N (Numeric) | 12 | 0 |  |
| 6 | original_kilos (DBF: ORIGINAL_K) | N (Numeric) | 12 | 3 |  |
| 7 | prod_transformado (DBF: PROD_TRANS) | N (Numeric) | 7 | 0 |  |
| 8 | transformado_unidades (DBF: TRANSFORMA) | N (Numeric) | 12 | 0 |  |
| 9 | transformado_kilos (DBF: TRANSFORM2) | N (Numeric) | 12 | 3 |  |
| 10 | prod_picada (DBF: PROD_PICAD) | N (Numeric) | 7 | 0 |  |
| 11 | picada_unidades (DBF: PICADA_UNI) | N (Numeric) | 12 | 0 |  |
| 12 | picada_kilos (DBF: PICADA_KIL) | N (Numeric) | 12 | 3 |  |
| 13 | PROD_MERMA | N (Numeric) | 7 | 0 |  |
| 14 | merma_unidades (DBF: MERMA_UNID) | N (Numeric) | 12 | 0 |  |
| 15 | merma_kilos (DBF: MERMA_KILO) | N (Numeric) | 12 | 3 |  |
| 16 | prod_residual (DBF: PROD_RESID) | N (Numeric) | 7 | 0 |  |
| 17 | residual_unidades (DBF: RESIDUAL_U) | N (Numeric) | 12 | 0 |  |
| 18 | residual_kilos (DBF: RESIDUAL_K) | N (Numeric) | 12 | 3 |  |
| 19 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| NUMERO | `numero` | Candidate | ASC | `.NOT.DELETED()` |

## transportes.dbf

Formato: Visual FoxPro · 9 campos · largo de registro: 200 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | N (Numeric) | 3 | 0 |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 30 |  |  |
| 3 | DOMICILIO | C (Character) | 60 |  |  |
| 4 | LOCALIDAD | C (Character) | 20 |  |  |
| 5 | codigo_postal (DBF: CODIGO_POS) | N (Numeric) | 5 | 0 |  |
| 6 | PROVINCIA | C (Character) | 2 |  |  |
| 7 | TELEFONO | C (Character) | 60 |  |  |
| 8 | CUIT | N (Numeric) | 11 | 0 |  |
| 9 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Candidate | ASC | `.NOT.DELETED()` |

## tributos.dbf

Formato: Visual FoxPro · 6 campos · largo de registro: 143 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | FE_CLAVE | N (Numeric) | 15 | 0 |  |
| 2 | ID | N (Numeric) | 2 | 0 |  |
| 3 | descripcion (DBF: DESCRIPCIO) | C (Character) | 80 |  |  |
| 4 | baseimponible (DBF: BASEIMPONI) | N (Numeric) | 15 | 2 |  |
| 5 | ALICUOTA | N (Numeric) | 15 | 2 |  |
| 6 | IMPORTE | N (Numeric) | 15 | 2 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CLAVE | `fe_clave` | Regular | ASC | `.NOT.DELETED()` |

## unidad_de_medidas.DBF

Formato: Visual FoxPro · 3 campos · largo de registro: 41 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | C (Character) | 2 |  |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 30 |  |  |
| 3 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Candidate | ASC | `.NOT.DELETED()` |

## unidad_venta.dbf

Formato: Visual FoxPro · 3 campos · largo de registro: 51 bytes · registros: 2 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | C (Character) | 2 |  |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 40 |  |  |
| 3 | actualizacion (DBF: ACTUALIZAC) | T (DateTime) | 8 |  | binario (NOCPTRANS) |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Regular | ASC |  |
| DESCRIPCIO | `descripcion` | Regular | ASC |  |

## Unidades_de_medida.DBF

Formato: Visual FoxPro · 2 campos · largo de registro: 33 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | C (Character) | 2 |  |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 30 |  |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Regular | ASC |  |
| DESCRIP | `descripcion` | Regular | ASC |  |

## v_proveedores.DBF

Formato: Visual FoxPro · 2 campos · largo de registro: 56 bytes · registros: 429 · tabla libre

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | RAZSOCIAL | C (Character) | 50 |  |  |
| 2 | CODPROVEED | N (Numeric) | 5 | 0 |  |

Índices: sin CDX estructural.

## vendedores.dbf

Formato: Visual FoxPro · 4 campos · largo de registro: 43 bytes · registros: 0 · base de datos: `datos.dbc`

| # | Campo | Tipo | Largo | Decimales | Notas |
|---|---|---|---|---|---|
| 1 | CODIGO | C (Character) | 2 |  |  |
| 2 | descripcion (DBF: DESCRIPCIO) | C (Character) | 30 |  |  |
| 3 | COMISION_1 | N (Numeric) | 5 | 2 |  |
| 4 | COMISION_2 | N (Numeric) | 5 | 2 |  |

Índices (CDX estructural):

| Tag | Expresión | Tipo | Orden | Filtro (FOR) |
|---|---|---|---|---|
| CODIGO | `codigo` | Candidate | ASC | `.NOT.DELETED()` |
