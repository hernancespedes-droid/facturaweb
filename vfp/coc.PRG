*******************************************************
* Agrega a la base de datos el tratamiento de Remitos *
* Remitos_cab, Remitos_item y la Vista V_Remitos      *
*******************************************************
Set SAFETY OFF
Set TALK OFF
Set DELE ON
Close TABLES ALL
Close DATA ALL
Open DATA datos EXCLUSIVE

set step on

Create TABLE 'REMITOS_CAB.DBF' NAME 'REMITOS_CAB' ( ;
	FECHA                D NOT NULL, ;
	TIPO                 C(2) NOT NULL, ;
	LETRA                C(1) NOT NULL, ;
	SUCURSAL             N(2, 0) NOT NULL, ;
	PREFIJO              N(4, 0) NOT NULL, ;
	COMPROBANTE          N(7, 0) NOT NULL, ;
	CLIENTE              N(5, 0) NOT NULL, ;
	BRUTO                N(11, 2) NOT NULL, ;
	NETO_GRABADO         N(11, 2) NOT NULL, ;
	NETO_EXENTO          N(11, 2) NOT NULL, ;
	IVA_1                N(11, 2) NOT NULL, ;
	IVA_2                N(11, 2) NOT NULL, ;
	LIBERADO             N(11, 2) NOT NULL, ;
	BONIFICACION         N(11, 2) NOT NULL, ;
	NETO                 N(11, 2) NOT NULL, ;
	PORCENTAJE_DESCUENTO N(6, 2) NOT NULL, ;
	ESTADO               C(1) NOT NULL, ;
	OBSERVACIONES        C(60) NOT NULL, ;
	CANAL                C(1) NOT NULL, ;
	TERMINAL             N(2, 0) NOT NULL, ;
	IVA_CLIENTE          N(1, 0) NOT NULL, ;
	CUIT_CLIENTE         N(11, 0) NOT NULL, ;
	RECARGO_F_PAGO       N(11, 2) NOT NULL, ;
	IMPUESTO             N(5, 2) NOT NULL, ;
	RECIBO               N(10, 0) NOT NULL, ;
	MVSTK                N(10, 0) NOT NULL, ;
	COBRO                D NOT NULL, ;
	CIERRE               T NOT NULL, ;
	NUMERO_CIERRE        N(10, 0) NOT NULL, ;
	VENDEDOR             C(2) NOT NULL, ;
	FACTURADO            D NOT NULL, ;
	VALIDES              D NOT NULL, ;
	FORMA_PAGO           N(1, 0) NOT NULL, ;
	UNIDADES             N(11, 2) NOT NULL ;
	)
***** Create each index for CABECERA_PEDIDOS *****
Set COLLATE TO 'MACHINE'
Index ON TIPO+LETRA+STR(SUCURSAL,2)+STR(COMPROBANTE,7) TAG CLAVE


Create TABLE 'REMITOS_ITEM.DBF' NAME 'REMITOS_ITEM' ( ;
	FECHA                D NOT NULL, ;
	TIPO                 C(2) NOT NULL, ;
	LETRA                C(1) NOT NULL, ;
	SUCURSAL             N(2, 0) NOT NULL, ;
	PREFIJO              N(4, 0) NOT NULL, ;
	COMPROBANTE          N(7, 0) NOT NULL, ;
	ARTICULO             N(7, 0) NOT NULL, ;
	CODIGO_DE_BARRA      N(13, 0) NOT NULL, ;
	BULTOS               N(3, 0) NOT NULL, ;
	UNIDADES             N(11, 2) NOT NULL, ;
	KILOS                N(11, 3) NOT NULL, ;
	SALDO                N(11, 2) NOT NULL, ;
	LISTA                C(1) NOT NULL, ;
	PRECIO               N(11, 2) NOT NULL, ;
	PORCENTAJE_DESCUENTO N(6, 2) NOT NULL, ;
	TOTAL_ITEM           N(11, 2) NOT NULL, ;
	CLIENTE              N(5, 0) NOT NULL, ;
	FECHA_ULT_FACTURA    D NOT NULL ;
	)
***** Create each index for ITEMS_PEDIDOS *****
Set COLLATE TO 'MACHINE'
Index ON TIPO+LETRA+STR(SUCURSAL,2)+STR(COMPROBANTE,7)+STR(ARTICULO,7) TAG CLAVE

Create VIEW v_remitos AS ;
	SELECT Remitos_cab.TIPO, Remitos_cab.LETRA, Remitos_cab.SUCURSAL,;
	Remitos_cab.COMPROBANTE, Remitos_cab.FECHA, SUM(Remitos_item.SALDO),;
	Remitos_cab.CLIENTE, Clientes.razon_social, Remitos_cab.PREFIJO,;
	Remitos_cab.VALIDES, Remitos_cab.NETO;
	FROM datos!Remitos_cab, datos!Remitos_item, datos!Clientes;
	WHERE Remitos_cab.TIPO = Remitos_item.TIPO;
	AND Remitos_cab.LETRA = Remitos_item.LETRA;
	AND Remitos_cab.SUCURSAL = Remitos_item.SUCURSAL;
	AND Remitos_cab.COMPROBANTE = Remitos_item.COMPROBANTE;
	AND Remitos_cab.CLIENTE = Clientes.codigo;
	AND (Remitos_cab.FACTURADO = {  /  /  };
	AND Remitos_cab.CLIENTE = ?l_cliente);
	GROUP BY Remitos_cab.TIPO, Remitos_cab.LETRA, Remitos_cab.SUCURSAL,;
	Remitos_cab.PREFIJO, Remitos_cab.COMPROBANTE;
	ORDER BY Clientes.razon_social

=DBSetProp('V_REMITOS', 'View', 'UpdateType', 1)
=DBSetProp('V_REMITOS', 'View', 'WhereType', 3)
=DBSetProp('V_REMITOS', 'View', 'FetchMemo', .T.)
=DBSetProp('V_REMITOS', 'View', 'SendUpdates', .F.)
=DBSetProp('V_REMITOS', 'View', 'UseMemoSize', 255)
=DBSetProp('V_REMITOS', 'View', 'FetchSize', 100)
=DBSetProp('V_REMITOS', 'View', 'MaxRecords', -1)
=DBSetProp('V_REMITOS', 'View', 'Tables', 'datos!clientes,datos!cabecera_pedidos')
=DBSetProp('V_REMITOS', 'View', 'FetchSize', 100)
=DBSetProp('V_REMITOS', 'View', 'Comment', "")
=DBSetProp('V_REMITOS', 'View', 'BatchUpdateCount', 1)
=DBSetProp('V_REMITOS', 'View', 'ShareConnection', .F.)
=DBSetProp('V_REMITOS', 'View', 'Prepared', .F.)
=DBSetProp('V_REMITOS', 'View', 'CompareMemo', .T.)
=DBSetProp('V_REMITOS', 'View', 'FetchAsNeeded', .F.)

*!* Field Level Properties for V_REMITOS
* Props for the V_REMITOS.tipo field.
=DBSetProp('V_REMITOS.tipo', 'Field', 'KeyField', .F.)
=DBSetProp('V_REMITOS.tipo', 'Field', 'Updatable', .F.)
=DBSetProp('V_REMITOS.tipo', 'Field', 'UpdateName', 'tipo')
=DBSetProp('V_REMITOS.tipo', 'Field', 'DataType', "C(2)")
* Props for the V_REMITOS.letra field.
=DBSetProp('V_REMITOS.letra', 'Field', 'KeyField', .F.)
=DBSetProp('V_REMITOS.letra', 'Field', 'Updatable', .F.)
=DBSetProp('V_REMITOS.letra', 'Field', 'UpdateName', 'letra')
=DBSetProp('V_REMITOS.letra', 'Field', 'DataType', "C(1)")
* Props for the V_REMITOS.sucursal field.
=DBSetProp('V_REMITOS.sucursal', 'Field', 'KeyField', .F.)
=DBSetProp('V_REMITOS.sucursal', 'Field', 'Updatable', .F.)
=DBSetProp('V_REMITOS.sucursal', 'Field', 'UpdateName', 'sucursal')
=DBSetProp('V_REMITOS.sucursal', 'Field', 'DataType', "N(2)")
* Props for the V_REMITOS.comprobante field.
=DBSetProp('V_REMITOS.comprobante', 'Field', 'KeyField', .F.)
=DBSetProp('V_REMITOS.comprobante', 'Field', 'Updatable', .F.)
=DBSetProp('V_REMITOS.comprobante', 'Field', 'UpdateName', 'comprobante')
=DBSetProp('V_REMITOS.comprobante', 'Field', 'DataType', "N(7)")
* Props for the V_REMITOS.fecha field.
=DBSetProp('V_REMITOS.fecha', 'Field', 'KeyField', .F.)
=DBSetProp('V_REMITOS.fecha', 'Field', 'Updatable', .F.)
=DBSetProp('V_REMITOS.fecha', 'Field', 'UpdateName', 'fecha')
=DBSetProp('V_REMITOS.fecha', 'Field', 'DataType', "D")
* Props for the V_REMITOS.sum_saldo field.
=DBSetProp('V_REMITOS.sum_saldo', 'Field', 'KeyField', .F.)
=DBSetProp('V_REMITOS.sum_saldo', 'Field', 'Updatable', .F.)
=DBSetProp('V_REMITOS.sum_saldo', 'Field', 'UpdateName', 'sum_saldo')
=DBSetProp('V_REMITOS.sum_saldo', 'Field', 'DataType', "N(16,2)")
* Props for the V_REMITOS.cliente field.
=DBSetProp('V_REMITOS.cliente', 'Field', 'KeyField', .F.)
=DBSetProp('V_REMITOS.cliente', 'Field', 'Updatable', .F.)
=DBSetProp('V_REMITOS.cliente', 'Field', 'UpdateName', 'cliente')
=DBSetProp('V_REMITOS.cliente', 'Field', 'DataType', "N(5)")
* Props for the V_REMITOS.razon_social field.
=DBSetProp('V_REMITOS.razon_social', 'Field', 'KeyField', .F.)
=DBSetProp('V_REMITOS.razon_social', 'Field', 'Updatable', .T.)
=DBSetProp('V_REMITOS.razon_social', 'Field', 'UpdateName', 'datos!clientes.razon_social')
=DBSetProp('V_REMITOS.razon_social', 'Field', 'DataType', "C(40)")
* Props for the V_REMITOS.prefijo field.
=DBSetProp('V_REMITOS.prefijo', 'Field', 'KeyField', .F.)
=DBSetProp('V_REMITOS.prefijo', 'Field', 'Updatable', .F.)
=DBSetProp('V_REMITOS.prefijo', 'Field', 'UpdateName', 'prefijo')
=DBSetProp('V_REMITOS.prefijo', 'Field', 'DataType', "N(4)")
* Props for the V_REMITOS.valides field.
=DBSetProp('V_REMITOS.valides', 'Field', 'KeyField', .F.)
=DBSetProp('V_REMITOS.valides', 'Field', 'Updatable', .T.)
=DBSetProp('V_REMITOS.valides', 'Field', 'UpdateName', 'datos!cabecera_pedidos.valides')
=DBSetProp('V_REMITOS.valides', 'Field', 'DataType', "D")
* Props for the V_REMITOS.neto field.
=DBSetProp('V_REMITOS.neto', 'Field', 'KeyField', .F.)
=DBSetProp('V_REMITOS.neto', 'Field', 'Updatable', .T.)
=DBSetProp('V_REMITOS.neto', 'Field', 'UpdateName', 'datos!cabecera_pedidos.neto')
=DBSetProp('V_REMITOS.neto', 'Field', 'DataType', "N(11,2)")

Alter TABLE sucursales ADD ultimo_remito N(10) NOT NULL
Alter TABLE sucursales ADD com I NOT NULL
Alter TABLE sucursales ADD controlador_fiscal L NOT NULL

Create VIEW v_estadistica AS;
	SELECT Estadistica.CODIGO_DE_BARRA, SPACE(40) AS descripcion,;
	Estadistica.BULTOS, Estadistica.UNIDADES, Estadistica.KILOS,;
	Estadistica.LISTA, Estadistica.PRECIO,;
	Estadistica.PORCENTAJE_DESCUENTO, Estadistica.TOTAL_ITEM,;
	Estadistica.CLIENTE, Estadistica.grabado, Estadistica.pedido;
	FROM datos!Estadistica;
	WHERE .F.

SELECT 0
select CLIENTES
replace all iva with 4 for iva =5
USE

Wait WINDOW 'Ordenando Integridad Referencial... Aguarde !!!' AT 28,20 NOWAIT
Validate DATABASE NOCONSOLE
Close DATA
Wait CLEAR
Wait WINDOW 'PROCESO FINALIZADO EXITOSAMENTE!!! <Enter>'
Wait WINDOW 'AGREGAR LAS OPCIONES CORRESPONDIENTES!!! <Enter>'
Wait WINDOW 'EN EL MAESTRO DE CLAVES!!! <Enter>'

