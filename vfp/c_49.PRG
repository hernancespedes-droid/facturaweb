Set Safety Off
Set ENGINEBEHAVIOR 70
Set Dele On
Set Collate To "MACHINE"
Close Tables All
Close Data All
Open Database DATOS Exclusive

Alter Table recepcion_taller_det add nroliquidacion n(8) not null

Alter Table sucursales add ultimo_nroliquidacion_A_talleres n(8) not null

lcTaller = Space(0)

Create SQL View v_recepcion_de_taller_pendiente_de_Liquidacion as;
	select ;
	recepcion_taller_det.numero		,;
	recepcion_taller_det.articulo 	,;
	articulos.descripcion 			,;
	recepcion_taller_det.saldo 		,;
	recepcion_taller_cab.taller 	,;
	talleres.descripcion as taller_desc,;
	NVL(IMPORTES_POR_MODELOS_POR_TALLER.importe,000000000.00) as importe ,;	
	0000000.00 as a_liquidar 		;
	from  recepcion_taller_det 		;
	inner join recepcion_taller_cab on recepcion_taller_cab.numero = recepcion_taller_det.numero ;
	inner join articulos on articulos.codigo = recepcion_taller_det.articulo ;
	inner join talleres  on talleres.codigo = recepcion_taller_cab.taller;
	LEFT JOIN IMPORTES_POR_MODELOS_POR_TALLER ON ;
		articulos.modelo+recepcion_taller_cab.taller = ;
		IMPORTES_POR_MODELOS_POR_TALLER.MODELO+IMPORTES_POR_MODELOS_POR_TALLER.TALLER ;
	where recepcion_taller_det.saldo > 0 and;
	recepcion_taller_cab.taller = ?lcTaller ;
	order by recepcion_taller_det.numero
	
Create Table Liquidacion_taller_cab name Liquidacion_taller_cab (;
	numero		n(10)	not null primary key ,;
	fecha		d		not null ,;
	taller		c(3)	not null ,;
	actualizacion t not null DEFAULT Datetime())
	
Create Table Liquidacion_taller_det name Liquidacion_taller_det (;
	numero		n(10)	not null ,;
	articulo	c(15)	not null ,;
	unidades	n(11,2) not null ,;
	costo		n(10,2) not null ,;
	numero_de_Recepcion n(10)	not null ,;
	actualizacion t not null DEFAULT Datetime())
	
Select Liquidacion_taller_det 
Index On numero tag numero
Index On Str(numero)+articulo tag clave	