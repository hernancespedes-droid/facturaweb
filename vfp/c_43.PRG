Do SETEOS
Set Dele On
Set Collate To "MACHINE"
Close Tables All
Close Data All
Open Data datos Exclusive

Alter Table ARTICULOS add promedio n(8,2) not null

Alter Table orden_de_corte_cab rename COLUMN promedio TO promedio_estimado
Alter Table orden_de_corte_cab add promedio_real n(8,2) not null
Alter Table orden_de_corte_cab add cantidad_de_tiras n(8,2) not null
Alter Table orden_de_corte_cab add metros_de_tela n(8,2) not null

Alter Table ordcomp_encabezado_mp add autorizo c(30) not null
Alter Table ordcomp_encabezado_mp add confecciono c(30) not null
Alter Table ordcomp_encabezado_mp add condicion_de_pago c(2) not null

Alter Table ORDCOMP_DETALLE_MP Add COSTO N(11,2) Not Null
Alter Table ORDCOMP_DETALLE_MP Add DESC1 N(5,2) Not Null
Alter Table ORDCOMP_DETALLE_MP Add DESC2 N(5,2) Not Null
Alter Table ORDCOMP_DETALLE_MP Add DESC3 N(5,2) Not Null
Close Tables 

Create Sql View ;
	V_NOTAS_DE_PEDIDO As;
	SELECT Notaped_cab.tipo, Notaped_cab.letra, Notaped_cab.sucursal,;
	Notaped_cab.numero, Notaped_cab.fecha,;
	SUM(Notaped_det.saldo) As sum_saldo, Notaped_cab.cliente,;
	Clientes.razon_social, Notaped_cab.prefijo, Notaped_cab.valides,;
	Notaped_cab.neto;
	FROM ;
	datos!Notaped_cab ;
	INNER Join datos!Notaped_det ;
	ON  Str(Notaped_cab.sucursal,2)+Str(Notaped_cab.numero,8) = Str(Notaped_det.sucursal,2)+Str(Notaped_det.numero,8) ;
	INNER Join datos!Clientes ;
	ON  Notaped_cab.cliente = Clientes.codigo;
	GROUP By Notaped_cab.sucursal, Notaped_cab.numero;
	ORDER By  Notaped_cab.numero


Create SQL View v_remitos AS;
SELECT Remitos_cab.tipo, Remitos_cab.letra, Remitos_cab.sucursal,;
  Remitos_cab.comprobante, Remitos_cab.fecha, SUM(Remitos_item.saldo),;
  Remitos_cab.cliente, Clientes.razon_social, Remitos_cab.prefijo,;
  Remitos_cab.valides, Remitos_cab.neto;
 FROM datos!Remitos_cab;
	INNER JOIN datos!Remitos_item ON Remitos_cab.tipo = Remitos_item.tipo AND Remitos_cab.letra = Remitos_item.letra AND  Remitos_cab.sucursal = Remitos_item.sucursal AND  Remitos_cab.comprobante = Remitos_item.comprobante ;
	INNER JOIN datos!Clientes ON Remitos_cab.cliente = Clientes.codigo ;
 WHERE Remitos_item.saldo > 0;
   AND  Remitos_cab.cliente =  ?l_cliente ;
 GROUP BY Remitos_cab.tipo, Remitos_cab.letra, Remitos_cab.sucursal,;
  Remitos_cab.prefijo, Remitos_cab.comprobante;
 ORDER BY Clientes.razon_social

DBSetProp('v_remitos',"View","SendUpdates",.F.)
DBSetProp('v_remitos',"View","BatchUpdateCount",1)
DBSetProp('v_remitos',"View","CompareMemo",.T.)
DBSetProp('v_remitos',"View","FetchAsNeeded",.F.)
DBSetProp('v_remitos',"View","FetchMemo",.T.)
DBSetProp('v_remitos',"View","FetchSize",100)
DBSetProp('v_remitos',"View","MaxRecords",-1)
DBSetProp('v_remitos',"View","Prepared",.F.)
DBSetProp('v_remitos',"View","UpdateType",1)
DBSetProp('v_remitos',"View","UseMemoSize",255)
DBSetProp('v_remitos',"View","Tables","datos!clientes,datos!remitos_cab")
DBSetProp('v_remitos',"View","WhereType",3)

DBSetProp('v_remitos'+".tipo","Field","DataType","C(2)")
DBSetProp('v_remitos'+".tipo","Field","UpdateName","datos!remitos_cab.tipo")
DBSetProp('v_remitos'+".tipo","Field","KeyField",.F.)
DBSetProp('v_remitos'+".tipo","Field","Updatable",.F.)

DBSetProp('v_remitos'+".letra","Field","DataType","C(1)")
DBSetProp('v_remitos'+".letra","Field","UpdateName","datos!remitos_cab.letra")
DBSetProp('v_remitos'+".letra","Field","KeyField",.F.)
DBSetProp('v_remitos'+".letra","Field","Updatable",.F.)

DBSetProp('v_remitos'+".sucursal","Field","DataType","N(2)")
DBSetProp('v_remitos'+".sucursal","Field","UpdateName","datos!remitos_cab.sucursal")
DBSetProp('v_remitos'+".sucursal","Field","KeyField",.F.)
DBSetProp('v_remitos'+".sucursal","Field","Updatable",.F.)

DBSetProp('v_remitos'+".comprobante","Field","DataType","N(7)")
DBSetProp('v_remitos'+".comprobante","Field","UpdateName","datos!remitos_cab.comprobante")
DBSetProp('v_remitos'+".comprobante","Field","KeyField",.F.)
DBSetProp('v_remitos'+".comprobante","Field","Updatable",.F.)

DBSetProp('v_remitos'+".fecha","Field","DataType","D")
DBSetProp('v_remitos'+".fecha","Field","UpdateName","datos!remitos_cab.fecha")
DBSetProp('v_remitos'+".fecha","Field","KeyField",.F.)
DBSetProp('v_remitos'+".fecha","Field","Updatable",.F.)

DBSetProp('v_remitos'+".sum_saldo","Field","DataType","N(16,2)")
DBSetProp('v_remitos'+".sum_saldo","Field","KeyField",.F.)
DBSetProp('v_remitos'+".sum_saldo","Field","Updatable",.F.)

DBSetProp('v_remitos'+".cliente","Field","DataType","N(5)")
DBSetProp('v_remitos'+".cliente","Field","UpdateName","datos!remitos_cab.cliente")
DBSetProp('v_remitos'+".cliente","Field","KeyField",.F.)
DBSetProp('v_remitos'+".cliente","Field","Updatable",.F.)

DBSetProp('v_remitos'+".razon_social","Field","DataType","C(40)")
DBSetProp('v_remitos'+".razon_social","Field","UpdateName","datos!clientes.razon_social")
DBSetProp('v_remitos'+".razon_social","Field","KeyField",.F.)
DBSetProp('v_remitos'+".razon_social","Field","Updatable",.T.)

DBSetProp('v_remitos'+".prefijo","Field","DataType","N(4)")
DBSetProp('v_remitos'+".prefijo","Field","UpdateName","datos!remitos_cab.prefijo")
DBSetProp('v_remitos'+".prefijo","Field","KeyField",.F.)
DBSetProp('v_remitos'+".prefijo","Field","Updatable",.F.)

DBSetProp('v_remitos'+".valides","Field","DataType","D")
DBSetProp('v_remitos'+".valides","Field","UpdateName","datos!remitos_cab.valides")
DBSetProp('v_remitos'+".valides","Field","KeyField",.F.)
DBSetProp('v_remitos'+".valides","Field","Updatable",.T.)

DBSetProp('v_remitos'+".neto","Field","DataType","N(11,2)")
DBSetProp('v_remitos'+".neto","Field","UpdateName","datos!remitos_cab.neto")
DBSetProp('v_remitos'+".neto","Field","KeyField",.F.)
DBSetProp('v_remitos'+".neto","Field","Updatable",.T.)


