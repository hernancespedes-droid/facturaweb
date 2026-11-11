Set Safety Off
Set ENGINEBEHAVIOR 70
Set Dele On
Set Collate To "MACHINE"
Close Tables All
Close Data All
Open Database DATOS Exclusive


Create Table Productos_en_procesos name Productos_en_procesos (;
	numero		N (10) not null ,;
	FECHA		D NOT NULL ,;
	articulo	c(15)	not null ,;
	unidades	n(11,2) not null ,;
	saldo		n(11,2) not null ,;
	numero_de_Recepcion n(10)	not null ,;
	actualizacion t not null DEFAULT Datetime())
	
Select productos_en_procesos
Index On numero tag numero
Index On Str(numero,8)+articulo tag clave
Index On numero_de_recepcion tag nro_recep

Alter Table sucursales add ult_Prod_en_Proceso n(10) not null

Alter Table ENVIO_TALLER_det   Add nro_de_Productos_en_procesos  N(10) Not Null	

Create Sql View v_Productos_en_procesos As;
	Select ;
	Productos_en_procesos.numero,;
	Productos_en_procesos.fecha,;
	Productos_en_procesos.ARTICULO ,;
	SUM(Productos_en_procesos.SALDO) AS SALDO ,;
	Productos_en_procesos.Numero_de_recepcion ,;	
	Articulos.DESCRIPCION As Descrip;
	FROM Productos_en_procesos ;
	INNER Join Articulos On  Productos_en_procesos.articulo = Articulos.codigo;
	where !Empty(Productos_en_procesos.SALDO) ;
	GROUP by Productos_en_procesos.numero ;
	ORDER By Productos_en_procesos.numero 

Alter Table Talleres add Prod_Term_interm n(1) DEFAULT 1  not null
Select TALLERES
Replace ALL Prod_Term_interm  WITH 1

Alter Table recepcion_taller_cab add Prod_Term_interm n(1) not null