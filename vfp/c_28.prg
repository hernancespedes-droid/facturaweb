Set Safety Off
Set Talk Off
Set Dele On
Close Tables All
Close Data All
Open Data datos Exclusive

Create Table 'MVSTKMP_CABECERA.DBF' Name 'MVSTKMP_CABECERA' ( ;
	NUMERO_MVSTK_MP  N(10) Not Null,;
	FECHA		     D Not Null,;
	TIPO             C(2)  Not Null,;
	SUCURSAL         N(4) Not Null,;
	NUMERO           N(9) Not Null,;
	PROVEEDOR        N(5) Not Null,;
	ORIGEN           N(2) Not Null,;
	SIGNO_ORIGEN     C(1) Not Null,;
	DESTINO          N(2) Not Null,;
	SIGNO_DESTINO    C(1) Not Null,;
	OBSERVACIONES    C(40) Not Null,;
	NRO_OC           N(8) Not Null,;
	LETRA            C(1) Not Null,;
	ACTUALIZACION    T Not Null Default Datetime() ,;
	RENGLONES        N(4) Not Null;
	)
Set Collate To 'MACHINE'

Index On TIPO+Str(PROVEEDOR,5)+Str(SUCURSAL,4)+Str(NUMERO,9)+Dtos(FECHA) For .Not.Deleted() Tag CLAVE
Index On NUMERO_MVSTK_MP For .Not.Deleted() Tag NUMERO


Create Table 'MVSTKMP_ITEMS.DBF' Name 'MVSTKMP_ITEMS' ( ;
	NUMERO_MVSTK_MP    N(10) Not Null,;
	CODIGO_MP          C(15) Not Null,;
	CANTIDAD_UNIDADES  N(12,2) Not Null,;
	CANTIDAD_KILOS     N(12,3) Not Null,;
	ACTUALIZACION      T Not Null Default Datetime();
	)
Index On NUMERO_MVSTK_MP For .Not.Deleted() Tag NUMERO
Index On CODIGO_MP For .Not.Deleted() Tag CODIGO_MP
Index On Str(NUMERO_MVSTK_MP,10)+CODIGO_MP For .Not.Deleted() Tag CLAVE

Create View V_MVSTKMP_ITEMS As ;
	SELECT MVSTKMP_ITEMS.NUMERO_MVSTK_MP, Space(15) As CODIGO_MP,;
	SPACE(40) As descripcion, MVSTKMP_ITEMS.CANTIDAD_UNIDADES,;
	MVSTKMP_ITEMS.CANTIDAD_KILOS, 0.00 As costo;
	FROM ;
	datos!MVSTKMP_ITEMS;
	WHERE  ( .F. )

Create View V_devuelvo_MVSTK_MP As ;
	SELECT Mvstkmp_cabecera.NUMERO_MVSTK_MP, Mvstkmp_cabecera.SIGNO_ORIGEN,;
	Mvstkmp_cabecera.ORIGEN, Mvstkmp_cabecera.SIGNO_DESTINO,;
	Mvstkmp_cabecera.DESTINO, Space(15) As CODIGO_MP, 0 As CANTIDAD_UNIDADES,;
	0.000 As CANTIDAD_KILOS;
	FROM ;
	datos!Mvstkmp_cabecera;
	WHERE  ( .F. )

*- Tablas de Stock Pendiebnte de Compras de materias primas
Create Table 'mp_pendiente_compras.DBF' Name 'mp_pendiente_compras' ( ;
	Nota_de_pedido 	   N(8) Not Null,;
	FECHA		       D Not Null,;
	CODIGO_MP          C(15) Not Null,;
	PROVEEDOR		   N(5) NOT null,;
	UNIDADES_PEDIDAS   N(12,2) Not Null,;
	SALDO              N(12,2) Not Null,;
	ACTUALIZACION      T Not Null Default Datetime();
	)
Index On Str(Nota_de_pedido,8)+codigo_mp TAG Nota_ped

Local lcodprove
lcodprove = 0
Create View v_mp_pendiente_compras as select ;
	Nota_de_pedido 	   ,;
	FECHA		       ,;
	CODIGO_MP          ,;
	SALDO              ,;
	PROVEEDOR          ;
	from mp_pendiente_compras ;	
	where saldo > 0	;
	and PROVEEDOR = &lcodprove ;
	order by Nota_de_pedido
	

Alter Table PARAMETROS ADD ultimo_mvstk_MP   N(10) NOT NULL

*-cambio estructura de la tabla materias primas y su respectivo indice
USE MATERIAS_PRIMAS Exclusive IN 0 
Select MATERIAS_PRIMAS
Delete Tag TIPO_MP 
Alter Table MATERIAS_PRIMAS alter codigo     C(4) not null
Alter Table MATERIAS_PRIMAS add primary key tipo_mp+rubro_mp+codigo+color for .NOT.DELETED() TAG TIPO_MP 
Replace ALL CODIGO WITH Padl(Alltrim(CODIGO),4,'0')
*- Por si las moscas regenero la vista
If Indbc('V_materias_primas',"VIEW")
	Drop View V_materias_primas
Endif
Create View v_materias_primas As ;
	select descripcion_propia, codigo,tipo_mp,rubro_mp,Color	;
	from materias_primas ;
	order By tipo_mp,rubro_mp,codigo,Color

Local lcprove
lcprove = 0
Create View v_materias_primas_prove As ;
	select descripcion_propia, codigo,tipo_mp,rubro_mp,Color,	;
		proveedor_habitual, proveedor_alternativo;
	from materias_primas ;
	where InList(?lcprove,proveedor_habitual,proveedor_alternativo) ;
	order By tipo_mp,rubro_mp,codigo,Color



*-cambio estructura de la tabla FORMULAS BASE y su respectivo indice
USE FORMULAS_BASE Exclusive 
Delete Tag CLAVE OF FORMULAS_BASE 
Alter Table FORMULAS_BASE alter codigo     C(4) not null
Alter Table FORMULAS_BASE add primary key modelo+tipo_mp+rubro_mp+codigo+color for .NOT.DELETED() TAG CLAVE
Replace ALL CODIGO WITH Padl(Alltrim(CODIGO),4,'0')
If Indbc('V_FORMULAS_BASE',"VIEW")
	Drop View V_FORMULAS_BASE
Endif
Create View v_formulas_base As ;
	select formulas_base.modelo,;
	modelos.descripcion As modelosdescrip,;
	formulas_base.tipo_mp,;
	tipos_de_materia_prima.descripcion As tipos_de_materia_prima,;
	formulas_base.rubro_mp,;
	Rubros_materias_primas.descripcion As Rubros_materias_primas,;
	formulas_base.Color,;
	colores.descripcion As colordescrip,;
	formulas_base.codigo,;
	formulas_base.cantidad,;
	materias_primas.descripcion_propia ;
	from formulas_base ;
	inner Join modelos On modelos.codigo = formulas_base.modelo ;
	inner Join colores On colores.codigo = formulas_base.Color ;
	inner Join tipos_de_materia_prima On tipos_de_materia_prima.codigo = formulas_base.tipo_mp ;
	inner Join Rubros_materias_primas On Rubros_materias_primas.codigo = formulas_base.rubro_mp ;
	inner Join materias_primas On materias_primas.tipo_mp + materias_primas.rubro_mp + materias_primas.codigo + materias_primas.Color =;
	formulas_base.tipo_mp   + formulas_base.rubro_mp   + formulas_base.codigo + formulas_base.Color ;
	order By modelosdescrip,descripcion_propia,modelo,formulas_base.tipo_mp,;
	formulas_base.rubro_mp,formulas_base.codigo,formulas_base.Color



*-cambio estructura de la tabla FORMULAS BASE y su respectivo indice
USE FORMULAS_ARTICULO Exclusive 
Delete Tag CLAVE 
Alter Table FORMULAS_ARTICULO alter codigo     C(4) not null
Index On  articulo+tipo_mp+rubro_mp+codigo+color for .NOT.DELETED() TAG CLAVE
Replace ALL CODIGO WITH Padl(Alltrim(CODIGO),4,'0')


*- Tablas de Orden  de Compras de materias primas
Create Table 'ordcomp_encabezado_mp.DBF' Name 'ordcomp_encabezado_mp' ( ;
	NUMERO	  		 N(8) Not Null,;
	FECHA		     D Not Null,;
	FECHA_entrega    D Not Null,;	
	PROVEEDOR        N(5) Not Null,;
	OBSERVACIONES    C(40) Not Null,;
	subtotal         N(12,2) Not Null,;
	iva		         N(12,2) Not Null,;
	total	         N(12,2) Not Null,;
	ACTUALIZACION    T Not Null Default Datetime();
	)
Set Collate To 'MACHINE'

Index On NUMERO Tag NUMERO

Create Table 'ordcomp_detalle_mp.DBF' Name 'ordcomp_detalle_mp' ( ;
	NUM 	  		 N(8) Not Null,;
	materia_prima    c(15) Not Null,;
	precio           N(11,4) Not Null,;
	cantidad	     N(9,2) Not Null,;
	saldo	         N(9,2) Not Null,;
	ACTUALIZACION    T Not Null Default Datetime();
	)
Index On num tag num
Index On Str(num,8)+materia_prima tag num_mp

*- Creo la vista para usar en notas de pedido para almacener MP para O.Compras
Create View v_mp_pendiente_compras as;
	select;
	Space(15) as articulo,;
	mp_pendiente_compras.*;	
	from mp_pendiente_compras;
	where (.f.)

*- Creo la vista de mp pendientes de compras con saldos
Create View v_mp_pendiente_compras_saldo as;
	select;
	mp_pendiente_compras.*,;	
	materias_primas.descripcion_propia;
	from mp_pendiente_compras;
	inner join materias_primas on mp_pendiente_compras.codigo_mp = materias_primas.tipo_mp+materias_primas.rubro_mp+materias_primas.codigo+materias_primas.color;
	where saldo > 0

*- Actualizo los talles toamndo de la descripcion del articulo
USE articulos
Replace all talle with Val(Right(Alltrim(descripcion),2)) for Empty(talle)


*- Agrego cmpos a la tabla de Cabecera de nota de pedido (transporte y bultos)
Alter Table notaped_cab add transporte n(3) not null
Alter Table notaped_cab add bultos     n(7) not null

*- Agrego a la tabla de items de remitos el campo orden de compra
Alter Table REMITOS_ITEM add orden_compra C(20) not null

Close All
