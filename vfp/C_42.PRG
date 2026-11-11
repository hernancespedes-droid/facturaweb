Do SETEOS
Set Dele On
Set Collate To "MACHINE"
Close Tables All
Close Data All
Open Data datos Exclusive

Use ENVIO_TALLER_det Exclu
Index On  Str(numero)+articulo+Str(nro_orden_de_corte)  For .Not.Deleted() Tag o_de_corte
Use
Close Tables
USE mp_pendiente_compras Exclusive 
Index On codigo_mp TAG codigo_mp 
Close Tables

Alter Table Mvstkmp_items ALTER cantidad_unidades       N(20,4) not null
Alter Table ordcomp_detalle_mp alter cantidad           N(20,4) not null
Alter Table ordcomp_detalle_mp alter saldo              N(20,4) not null
Alter Table materias_primas alter stock_fisico          N(20,4) not null
Alter Table materias_primas alter stock_minimo          N(20,4) not null
Alter Table materias_primas alter comprometido_ingreso  N(20,4) not null
Alter Table materias_primas alter comprometido_egreso   N(20,4) not null
Alter Table mp_pendiente_compras alter unidades_pedidas N(20,4) not null
Alter Table mp_pendiente_compras alter saldo            N(20,4) not null

Alter Table ORDEN_DE_CORTE_DET Drop NRO_ENVIO_TALLER
Alter Table ORDEN_DE_CORTE_DET Add PARA_ENVIO_TALLER N(10) Not Null
Alter Table ENVIO_TALLER_det   Add nro_orden_de_corte  N(10) Not Null
Alter Table notaped_cab		   Add destino c(40)   Not Null
*Alter Table ENVIO_TALLER_CAB   ADD OBSERVACIONES M NOT NULL
Alter Table ORDEN_DE_CORTE_CAB Add OBSERVACIONES M Not Null
Alter Table TALLERES           Add OBSERVACIONES M Not Null
Alter Table TALLERES           Add DOMICILIO c(30) Not Null
Alter Table TALLERES           Add TELEFONO  c(30) Not Null

Alter Table recepcion_TALLER_CAB add nro_mvstk n (10) not null

Close Tables

Create SQL View ;
	V_pedidos_pendientes_de_corte AS;
	select ;
		NOTAPED_DET.numero ,;
		NOTAPED_DET.fecha ,;
		NOTAPED_DET.articulo ,;
		NOTAPED_DET.saldo ,;
		NOTAPED_DET.cliente ,;
		articulos.descripcion ,;
		clientes.razon_social ;
		from NOTAPED_DET ;
		inner join articulos on articulos.codigo = NOTAPED_DET.articulo ;
		inner join clientes  on clientes.codigo  = NOTAPED_DET.cliente ;
		where NOTAPED_DET.saldo > 0 ;
		order by NOTAPED_DET.articulo, NOTAPED_DET.fecha
  
Create Sql View ;
	V_Mp_pendiente_compras_VARIOS As ;
	SELECT ;
	Mp_pendiente_compras.codigo_mp,;
	SUM(Mp_pendiente_compras.saldo) As saldo,;
	Mp.descripcion_propia ;
	FROM Mp_pendiente_compras ;
	;  && INNER Join mp_por_proveedor 
	;  && ON  Mp_pendiente_compras.codigo_mp = mp_por_proveedor.materia_prima 
	INNER Join materias_primas Mp ;
	ON  Mp_pendiente_compras.codigo_mp = ( Mp.tipo_mp+Mp.rubro_mp+Mp.codigo+Mp.Color );
	WHERE  Mp_pendiente_compras.saldo > ( 0 );
	ORDER By Mp_pendiente_compras.codigo_mp,;
	Mp_pendiente_compras.nota_de_pedido ;
	GROUP BY Mp_pendiente_compras.codigo_mp


Create Sql View ;
	v_envio_taller_pendientes As;
	SELECT ;
	ENVIO_TALLER_CAB.numero ,;
	ENVIO_TALLER_CAB.fecha ,;
	ENVIO_TALLER_CAB.TALLER ,;
	TALLERES.DESCRIPCION ;
	FROM ENVIO_TALLER_CAB;
	INNER Join ENVIO_TALLER_det On ENVIO_TALLER_CAB.numero = ENVIO_TALLER_det.numero ;
	INNER Join TALLERES On ENVIO_TALLER_CAB.TALLER = TALLERES.codigo ;
	where ENVIO_TALLER_det.saldo > 0 ;
	ORDER By ENVIO_TALLER_CAB.numero ;
	group By ENVIO_TALLER_CAB.numero

Create Sql View ;
	v_recepcion_taller As;
	SELECT ;
	numero ,;
	fecha ,;
	TALLER ,;
	TALLERES.DESCRIPCION ;
	FROM recepcion_TALLER_CAB;
	INNER Join TALLERES On recepcion_TALLER_CAB.TALLER = TALLERES.codigo ;
	ORDER By numero


Local lCProve
lCProve = 0

Create Sql View v_materias_primas_PROVE2 As;
	SELECT ;
	materias_primas.descripcion_propia,;
	mp_por_proveedor.materia_prima	As codigo_mp ,;
	mp_por_proveedor.Proveedor ,;
	mp_por_proveedor.num_interno ,;
	mp_por_proveedor.color_del_prove ,;
	mp_por_proveedor.descripcion_proveedor ,;
	mp_por_proveedor.costo,;
	mp_por_proveedor.fecha1;
	;
	FROM materias_primas ;
	INNER Join mp_por_proveedor On  ( materias_primas.tipo_mp+materias_primas.rubro_mp+materias_primas.codigo+materias_primas.Color ) = Alltrim(mp_por_proveedor.materia_prima);
	WHERE  mp_por_proveedor.Proveedor = ( ?lCProve );
	ORDER By mp_por_proveedor.materia_prima

Local lCcodigo_mp
lCcodigo_mp = 0

Create Sql View v_materias_primas2 As;
	SELECT ;
	materias_primas.descripcion_propia,;
	mp_por_proveedor.materia_prima	As codigo_mp ,;
	mp_por_proveedor.Proveedor ,;
	Space(30) As DESPROVEE ,;
	mp_por_proveedor.num_interno ,;
	mp_por_proveedor.color_del_prove ,;
	mp_por_proveedor.descripcion_proveedor ,;
	mp_por_proveedor.costo,;
	mp_por_proveedor.fecha1;
	;
	FROM materias_primas ;
	INNER Join mp_por_proveedor On  ( materias_primas.tipo_mp+materias_primas.rubro_mp+materias_primas.codigo+materias_primas.Color ) = Alltrim(mp_por_proveedor.materia_prima);
	WHERE mp_por_proveedor.materia_prima = ( ?lCcodigo_mp );
	ORDER By mp_por_proveedor.materia_prima


lcTaller = Space(3)
Create Sql View v_orden_de_ENVIO_Taller As;
	SELECT ;
	ENVIO_TALLER_CAB.numero,;
	ENVIO_TALLER_CAB.fecha ,;
	ENVIO_TALLER_CAB.TALLER ,;
	TALLERES.DESCRIPCION ,;
	ENVIO_TALLER_det.articulo ,;
	sum(ENVIO_TALLER_det.saldo) As saldos ;
	from ENVIO_TALLER_CAB ;
	INNER Join ENVIO_TALLER_det On ENVIO_TALLER_CAB.numero = ENVIO_TALLER_det.numero ;
	INNER Join TALLERES On ENVIO_TALLER_CAB.TALLER = TALLERES.codigo ;
	WHERE  ENVIO_TALLER_CAB.TALLER = ?lcTaller And ENVIO_TALLER_CAB.estado # 'A' ;
	having Sum(ENVIO_TALLER_det.saldo) > 0;
	GROUP By ENVIO_TALLER_det.numero;
	ORDER By ENVIO_TALLER_det.numero


Create Sql View v_orden_de_corte_envio_Taller As;
	Select ;
	ORDEN_DE_CORTE_CAB.numero,;
	ORDEN_DE_CORTE_CAB.fecha_hora,;
	ORDEN_DE_CORTE_CAB.promedio,;
	Articulos.DESCRIPCION As Descrip;
	FROM ORDEN_DE_CORTE_CAB ;
	INNER Join ORDEN_DE_CORTE_DET On  ORDEN_DE_CORTE_DET.numero = ORDEN_DE_CORTE_CAB.numero ;
	INNER Join Articulos On  ORDEN_DE_CORTE_DET.articulo = Articulos.codigo;
	where !Empty(ORDEN_DE_CORTE_DET.PARA_ENVIO_TALLER) ;
	GROUP By ORDEN_DE_CORTE_DET.numero;
	ORDER By ORDEN_DE_CORTE_CAB.numero

