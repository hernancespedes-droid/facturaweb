*-------------------------------------------------------
*  Este proceso es utilizado por el reindexador
*  y el form derecepcion de articulos y precios
*-------------------------------------------------------
DO seteos
OPEN DATABASE datos
SET ENGINEBEHAVIOR 70
USE parametros

Create VIEW v_articulos AS;
	SELECT DISTINCT Articulos.descripcion, Articulos.codigo;
	FROM datos!Articulos;
	ORDER BY Articulos.descripcion

vproveedor = 0
CREATE VIEW v_articulos2 AS;
	SELECT DISTINCT Articulos.descripcion, Articulos.codigo;
	FROM datos!Articulos INNER JOIN datos!articulos_por_proveedor APPR;
					ON articulos.codigo = APPR.articulo;
	WHERE APPR.proveedor = vproveedor;
	ORDER BY Articulos.descripcion

Create VIEW v_articulos3 AS;
	SELECT DISTINCT Articulos.codigo, Articulos.descripcion;
	FROM datos!Articulos;
	ORDER BY Articulos.codigo
	
*-------------------------------------------------------
Create VIEW v_bancos AS;
	SELECT DISTINCT Bancos.descripcion, Bancos.codigo;
	FROM datos!Bancos;
	ORDER BY Bancos.descripcion
*-------------------------------------------------------
Create VIEW v_caja AS;
	SELECT caja.p_fpago, SPACE(20) AS p_fpago_des, caja.p_cuotas,;
	caja.p_nro, caja.p_fecvto,;
	caja.p_banco, SPACE(30) AS p_banco_des, caja.p_cotiz,;
	caja.p_importe, caja.a_pagar FROM  datos!caja;
	WHERE .F.
*-------------------------------------------------------
Create VIEW v_clientes AS;
	SELECT DISTINCT Clientes.razon_social, Clientes.codigo;
	FROM datos!Clientes;
	ORDER BY Clientes.razon_social

Create VIEW v_clientes2 AS;
	SELECT DISTINCT Clientes.nombre_fantasia, Clientes.codigo;
	FROM datos!Clientes;
	ORDER BY Clientes.nombre_fantasia

Create VIEW v_clientesCuit AS;
	SELECT DISTINCT Clientes.cuit, Clientes.codigo;
	FROM datos!Clientes;
	ORDER BY Clientes.cuit

*-------------------------------------------------------
Create VIEW v_comprobantes AS;
	SELECT Subdiario.fecha, Subdiario.tipo, Subdiario.letra,;
	Subdiario.sucursal, Subdiario.comprobante;
	FROM datos!Subdiario;
	WHERE Subdiario.canal = ?_gcanal;
	AND Subdiario.sucursal = ?_gsucursal;
	AND Subdiario.terminal = ?_gterminal;
	AND Subdiario.fecha = DATE();
	AND EMPTY(Subdiario.estado) = .T.;
	ORDER BY Subdiario.comprobante DESC
*-------------------------------------------------------
Create VIEW v_cotizacion_dolar AS;
	SELECT DISTINCT Cotizacion_dolar.fecha, Cotizacion_dolar.importe;
	FROM datos!Cotizacion_dolar;
	ORDER BY Cotizacion_dolar.fecha DESC
*-------------------------------------------------------
Create VIEW v_creditos AS;
	SELECT *, ' ' AS marca, saldo AS saldo2 FROM datos!ctact;
	WHERE .F.
*-------------------------------------------------------
Create VIEW v_debitos AS;
	SELECT *, ' ' AS marca,;
	saldo AS saldo2,;
	0000000000.00 AS imputacion;
	FROM datos!ctact;
	WHERE .F.
*-------------------------------------------------------
Create VIEW v_devuelvo_mvstk AS;
	SELECT Mvstk_cabecera.numero_mvstk, Mvstk_cabecera.signo_origen,;
	Mvstk_cabecera.origen, Mvstk_cabecera.signo_destino,;
	Mvstk_cabecera.destino, SPACE(15) AS articulo,;
	000000000000 AS cantidad_unidades,;
	000000000.000 AS cantidad_kilos;
	FROM  datos!Mvstk_cabecera;
	WHERE .F.
*-------------------------------------------------------
CREATE VIEW v_estadistica AS ;
       SELECT codigo_de_barra, SPACE(40) AS descripcion,;
              bultos, unidades, kilos, lista, precio,;
              porcentaje_descuento, total_item,;
              cliente, grabado, pedido,por_iva, costo;
         FROM datos!estadistica;
         WHERE .F.

CREATE VIEW v_estadistica2 AS ;
       SELECT SPACE(15) AS codigo_de_barra, SPACE(40) AS descripcion,;
              bultos, unidades,merma, kilos, lista, precio_siniva, precio,;
              porcentaje_descuento, total_item, total_siniva,;
              cliente, grabado, pedido, por_iva, costo, .f. as marca, observaciones;
         FROM datos!estadistica;
         WHERE .F. 
         
CREATE VIEW v_tasas_iva AS ;
SELECT Tasas_iva.descripcion, Tasas_iva.codigo;
 FROM datos!tasas_iva;
 ORDER BY Tasas_iva.descripcion
*-------------------------------------------------------
Create VIEW v_estado_de_Articulos AS;
	SELECT DISTINCT Estado_de_articulos.descripcion,;
	Estado_de_articulos.codigo;
	FROM datos!Estado_de_articulos;
	ORDER BY Estado_de_articulos.descripcion
*-------------------------------------------------------
Create VIEW v_forma_de_pagos AS;
	SELECT DISTINCT Forma_de_pagos.descripcion, Forma_de_pagos.codigo;
	FROM datos!Forma_de_pagos;
	ORDER BY Forma_de_pagos.descripcion
*-------------------------------------------------------
Create VIEW v_imput AS;
	SELECT *, saldo AS saldo2,;
	0000000000.00 AS imputacion;
	FROM datos!ctact;
	WHERE .F.
*-------------------------------------------------------
Create VIEW v_motivos AS;
	SELECT DISTINCT Motivos.descripcion, Motivos.codigo;
	FROM datos!Motivos;
	ORDER BY Motivos.descripcion
*-------------------------------------------------------
Create VIEW v_mvstk_items AS;
	SELECT Mvstk_items.numero_mvstk, SPACE(15) AS articulo,;
	SPACE(40) AS descripcion, Mvstk_items.cantidad_unidades,;
	Mvstk_items.cantidad_kilos, 0000000.00 AS costo;
	FROM  datos!Mvstk_items;
	WHERE .F.
*-------------------------------------------------------
Create VIEW v_pagos AS;
	SELECT *, saldo AS saldo2,;
	0000000000.00 AS imputacion,;
	000 AS banco;
	FROM datos!ctact;
	WHERE .F.
* La usa el sistema de costos de Pablo -----------------
Create VIEW v_productos AS;
	SELECT Articulos.descripcion, Articulos.codigo,;
	Articulos.codigo_asociado, Articulos.rubro, Articulos.subrubro,;
	Articulos.unidad_de_medida, Articulos.unidades_por_pack,;
	Articulos.cantidad_de_unidades,;
    Articulos.margen;
	FROM datos!Articulos;
	WHERE Articulos.rubro = "ROT";
	ORDER BY Articulos.descripcion, Articulos.codigo

DBSetProp('V_PRODUCTOS', 'View', 'SendUpdates', .T.)
*-----------------------------------------------------------------
Create VIEW v_productos_relacionados AS;
	SELECT DISTINCT Productos_relacionados.sucursal,;
	Productos_relacionados.original, Productos_relacionados.transformado;
	FROM datos!Productos_relacionados;
	ORDER BY Productos_relacionados.sucursal,;
	Productos_relacionados.original, Productos_relacionados.transformado
*-----------------------------------------------------------------
Create VIEW v_provincias AS;
	SELECT DISTINCT Provincias.descripcion, Provincias.codigo;
	FROM datos!Provincias;
	ORDER BY Provincias.descripcion
*-----------------------------------------------------------------
Create VIEW v_rubros AS;
	SELECT DISTINCT Rubros.descripcion, Rubros.codigo;
	FROM datos!Rubros;
	ORDER BY Rubros.descripcion
*-----------------------------------------------------------------
Create VIEW v_sin_imputar AS;
	SELECT ctact.fecha, ctact.tipo, ctact.letra,;
	ctact.sucursal, ctact.prefijo, ctact.numero;
	FROM datos!ctact;
	WHERE ctact.sucursal = ?_gsucursal;
	AND ctact.saldo > 0;
	AND ctact.tico < 4;
	AND ctact.tipo = ?_tipo;
	AND ctact.cliente = ?_cliente;
	ORDER BY ctact.fecha
*-----------------------------------------------------------------
Create VIEW v_subrubros AS;
	SELECT DISTINCT Subrubros.descripcion, Subrubros.codigo;
	FROM datos!Subrubros;
	WHERE Subrubros.rubro = ?_rubro;
	ORDER BY Subrubros.descripcion
*-----------------------------------------------------------------
Create VIEW v_subrubrosT AS;
	SELECT DISTINCT Subrubros.descripcion, Subrubros.codigo;
	FROM datos!Subrubros;
	ORDER BY Subrubros.descripcion
*-----------------------------------------------------------------
Create VIEW v_sucursales AS;
	SELECT DISTINCT Sucursales.descripcion, Sucursales.codigo;
	FROM datos!Sucursales;
	ORDER BY Sucursales.descripcion
*-----------------------------------------------------------------
Create VIEW v_tipos_mvstk AS;
	SELECT DISTINCT Tipos_mvstk.descripcion, Tipos_mvstk.codigo;
	FROM datos!Tipos_mvstk;
	ORDER BY Tipos_mvstk.descripcion
*-----------------------------------------------------------------
Create VIEW v_tipo_de_clientes AS;
	SELECT DISTINCT Tipo_de_clientes.descripcion,;
	Tipo_de_clientes.codigo;
	FROM datos!Tipo_de_clientes;
	ORDER BY Tipo_de_clientes.descripcion
*-----------------------------------------------------------------
Create VIEW v_transformaciones AS;
	SELECT DISTINCT Transformaciones.fecha, Transformaciones.numero,;
	Transformaciones.sucursal;
	FROM datos!Transformaciones;
	ORDER BY Transformaciones.fecha
*-----------------------------------------------------------------
Create VIEW v_unidad_de_medidas AS;
	SELECT DISTINCT Unidad_de_medidas.descripcion,;
	Unidad_de_medidas.codigo;
	FROM datos!Unidad_de_medidas;
	ORDER BY Unidad_de_medidas.descripcion
*-----------------------------------------------------------------
Create VIEW v_vendedores AS;
	SELECT DISTINCT Vendedores.descripcion, Vendedores.codigo;
	FROM datos!Vendedores;
	ORDER BY Vendedores.descripcion
*-----------------------------------------------------------------
Create VIEW v_cheques AS;
	SELECT caja.p_fpago, caja.cliente, Clientes.razon_social, caja.p_nro,;
	caja.p_importe;
	FROM  datos!caja INNER JOIN datos!Clientes ;
	ON  caja.cliente = Clientes.codigo;
	WHERE caja.p_fpago == "CH";
	ORDER BY caja.p_fpago, caja.cliente, caja.p_nro, caja.p_importe
*-----------------------------------------------------------------
Create VIEW v_descuentos AS ;
	SELECT Descuentos.articulo, Articulos.descripcion, Descuentos.descuento ;
	FROM datos!Descuentos, datos!Articulos ;
	WHERE Descuentos.cliente = ?_cliente ;
	AND Descuentos.articulo = Articulos.codigo ;
	ORDER BY Descuentos.articulo
*-----------------------------------------------------------------
CREATE VIEW v_costos as ;
SELECT Articulos.codigo, Articulos.descripcion, Articulos.proveedor;
 FROM datos!articulos;
 WHERE Articulos.proveedor = ?_PROVEEDOR;
 ORDER BY Articulos.codigo, Articulos.proveedor
*-----------------------------------------------------------------
Create VIEW v_pedidos AS ;
	SELECT Cabecera_pedidos.tipo, Cabecera_pedidos.letra,;
	Cabecera_pedidos.sucursal, Cabecera_pedidos.comprobante,;
	Cabecera_pedidos.fecha, SUM(Items_pedidos.saldo),;
	Cabecera_pedidos.cliente, Clientes.razon_social,;
	Cabecera_pedidos.prefijo, Cabecera_pedidos.valides,;
	Cabecera_pedidos.neto;
	FROM datos!Cabecera_pedidos, datos!Items_pedidos, datos!Clientes;
	WHERE Cabecera_pedidos.tipo = Items_pedidos.tipo;
	AND Cabecera_pedidos.letra = Items_pedidos.letra;
	AND Cabecera_pedidos.sucursal = Items_pedidos.sucursal;
	AND Cabecera_pedidos.comprobante = Items_pedidos.comprobante;
	AND Cabecera_pedidos.cliente = Clientes.codigo;
	AND (Cabecera_pedidos.facturado = {  /  /  };
	AND Cabecera_pedidos.valides >= DATE());
	GROUP BY Cabecera_pedidos.tipo, Cabecera_pedidos.letra,;
	Cabecera_pedidos.sucursal, Cabecera_pedidos.prefijo,;
	Cabecera_pedidos.comprobante;
	ORDER BY Clientes.razon_social
*-----------------------------------------------------------------
Create VIEW v_remitos AS ;
	SELECT Remitos_cab.tipo, Remitos_cab.letra, Remitos_cab.sucursal,;
	Remitos_cab.comprobante, Remitos_cab.fecha, SUM(Remitos_item.saldo),;
	Remitos_cab.cliente, Clientes.razon_social, Remitos_cab.prefijo,;
	Remitos_cab.valides, Remitos_cab.neto;
	FROM datos!Remitos_cab, datos!Remitos_item, datos!Clientes;
	WHERE Remitos_cab.tipo = Remitos_item.tipo;
	AND Remitos_cab.letra = Remitos_item.letra;
	AND Remitos_cab.sucursal = Remitos_item.sucursal;
	AND Remitos_cab.comprobante = Remitos_item.comprobante;
	AND Remitos_cab.cliente = Clientes.codigo;
	AND (Remitos_cab.facturado = {  /  /  };
	AND Remitos_cab.cliente = ?l_cliente);
	GROUP BY Remitos_cab.tipo, Remitos_cab.letra, Remitos_cab.sucursal,;
	Remitos_cab.prefijo, Remitos_cab.comprobante;
	ORDER BY Clientes.razon_social
*-----------------------------------------------------------------
CREATE VIEW v_articulo_por_proveedor AS;
	SELECT proveedor, SPACE(40) AS pro_nom, num_interno AS nro_interno, costo ,;
	       desc1, desc2, desc3, unidad_venta AS uni_ven, SPACE(40) AS uv_nom  ,;
	       fecha1 AS fec_ult_com1, fecha2 AS fec_ult_com2, fecha3 AS fec_ult_com3 ,;
	       cantidad1 AS can_ult_com1, cantidad2 AS can_ult_com2, cantidad3 AS can_ult_com3,;
	       SPACE(20) AS clave;
	FROM Articulos_por_proveedor;
	WHERE .F.
*-----------------------------------------------------------------
CREATE VIEW v_unidad_venta AS;
	SELECT DISTINCT descripcion, codigo;
	FROM datos!unidad_venta;
	GROUP BY descripcion
*-----------------------------------------------------------------
CREATE VIEW v_orden_detalle AS;
	SELECT articulo, SPACE(40) AS descrip, SPACE(3) AS moneda, 000000.0000 AS costo,;
  	       00.00 AS desc1, 00.00 AS desc2, 00.00 AS desc3, precio, cantidad, 00.00 AS iva,;
  	       SPACE(15) AS nro_int, articulo AS origen, SPACE(1) AS marca;
	FROM datos!ordcomp_detalle;
	WHERE .F.	
*-----------------------------------------------------------------
_path = ALLTRIM(parametros.path_proveedores)+'p_proveedores'
CREATE VIEW v_OCbrowse AS;
       SELECT OCE.fecha, OCE.numero, OCE.proveedor, PROVE.pro_razon_social AS razonsoc DISTINCT;
 	  	      FROM ordcomp_encabezado OCE;
 	 	           INNER JOIN ordcomp_detalle OCD ON OCE.numero = OCD.num AND OCD.saldo > 0;
 	 	           INNER JOIN (_path) PROVE ON PROVE.pro_icodigo = OCE.proveedor;
    	 	  ORDER BY razonsoc, numero
*-----------------------------------------------------------------
Create VIEW v_articulos_precios AS;
	SELECT num_lista, valor, num_lista AS nl;
	FROM datos!articulos_precios;
	WHERE .F.
*-----------------------------------------------------------------
CREATE VIEW v_listas AS SELECT STR(num_lista,3) AS nl, num_lista;
       FROM articulos_precios;
       GROUP BY num_lista

*-----------------------------------------------------------------
Create VIEW v_condicionpago AS;
	SELECT DISTINCT CondicionPago.descripcion,;
	CondicionPago.codigo;
	FROM datos!CondicionPago;
	ORDER BY CondicionPago.descripcion

VALIDATE DATABASE 
quit