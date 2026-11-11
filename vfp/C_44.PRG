Do SETEOS
Set Dele On
Set Collate To "MACHINE"
Close Tables All
Close Data All
Open Data datos Exclusive


Alter Table  mvstk_items   ADD  costo N(14,4) not null
Alter Table  mvstkmp_items ADD  costo N(14,4) not null
Close Tables

Create Table IMPORTES_POR_MODELOS_POR_TALLER (;
	N_ID			I AUTOINC PRIMARY KEY ,;
	MODELO			C(4) NOT NULL ,;
	TALLER			C(3) NOT NULL ,;
	IMPORTE			N(10,2) NOT NULL )

Select IMPORTES_POR_MODELOS_POR_TALLER	
Index On MODELO+TALLER TAG CLAVE FOR NOT Deleted()
Index On MODELO TAG modelo FOR NOT Deleted()

Local LCModeloTaller
LCModelo = Space(0)


Create SQL View V_IMPORTES_POR_MODELOS_POR_TALLER AS;
SELECT Importes_por_modelos_por_taller.n_id,;
  Importes_por_modelos_por_taller.modelo,;
  Importes_por_modelos_por_taller.taller, Talleres.descripcion,;
  Importes_por_modelos_por_taller.importe;
 FROM ;
     IMPORTES_POR_MODELOS_POR_TALLER ;
    INNER JOIN TALLERES ;
   ON  Importes_por_modelos_por_taller.taller = Talleres.codigo;
 WHERE  ( modelo ) = ( ?LCModelo )

ThisView = 'V_IMPORTES_POR_MODELOS_POR_TALLER'

DBSetProp(ThisView,"View","SendUpdates",.T.)
DBSetProp(ThisView,"View","BatchUpdateCount",1)
DBSetProp(ThisView,"View","CompareMemo",.T.)
DBSetProp(ThisView,"View","FetchAsNeeded",.F.)
DBSetProp(ThisView,"View","FetchMemo",.T.)
DBSetProp(ThisView,"View","FetchSize",100)
DBSetProp(ThisView,"View","MaxRecords",-1)
DBSetProp(ThisView,"View","Prepared",.F.)
DBSetProp(ThisView,"View","UpdateType",1)
DBSetProp(ThisView,"View","UseMemoSize",255)
DBSetProp(ThisView,"View","Tables","datos!importes_por_modelos_por_taller")
DBSetProp(ThisView,"View","WhereType",3)

DBSetProp(ThisView+".n_id","Field","DataType","I")
DBSetProp(ThisView+".n_id","Field","UpdateName","datos!importes_por_modelos_por_taller.n_id")
DBSetProp(ThisView+".n_id","Field","KeyField",.T.)
DBSetProp(ThisView+".n_id","Field","Updatable",.F.)

DBSetProp(ThisView+".modelo","Field","DataType","C(4)")
DBSetProp(ThisView+".modelo","Field","UpdateName","datos!importes_por_modelos_por_taller.modelo")
DBSetProp(ThisView+".modelo","Field","KeyField",.F.)
DBSetProp(ThisView+".modelo","Field","Updatable",.T.)

DBSetProp(ThisView+".taller","Field","DataType","C(3)")
DBSetProp(ThisView+".taller","Field","UpdateName","datos!importes_por_modelos_por_taller.taller")
DBSetProp(ThisView+".taller","Field","KeyField",.F.)
DBSetProp(ThisView+".taller","Field","Updatable",.T.)

DBSetProp(ThisView+".descripcion","Field","DataType","C(30)")
DBSetProp(ThisView+".descripcion","Field","UpdateName","datos!talleres.descripcion")
DBSetProp(ThisView+".descripcion","Field","KeyField",.F.)
DBSetProp(ThisView+".descripcion","Field","Updatable",.F.)

DBSetProp(ThisView+".importe","Field","DataType","N(10,2)")
DBSetProp(ThisView+".importe","Field","UpdateName","datos!importes_por_modelos_por_taller.importe")
DBSetProp(ThisView+".importe","Field","KeyField",.F.)
DBSetProp(ThisView+".importe","Field","Updatable",.T.)


Alter Table notaped_cab add fecha_entrega D not null

Alter Table PARAMETROS Add PORCIENTO_GASTOS_GENERALES N(8,2) Not Null
Alter Table PARAMETROS Add PROMEDIO_VALOR_HORARIO_TIZADOYCORTES N(10,4) Not Null
Alter Table PARAMETROS Add PROMEDIO_TIEMPO_POR_PRENDA_TIZADOYCORTES N(10,4) Not Null

Alter Table MODELOS Add PROMEDIO_VALOR_HORARIO_HECHURA  N(10,4) Not Null
Alter Table MODELOS Add PROMEDIO_TIEMPO_POR_PRENDA_HECHURA N(10,4) Not Null

Create Sql View V_MODELOS As ;
	SELECT *;
	FROM ;
	datos!MODELOS;
	ORDER By MODELOS.descripcion

Create Sql View v_orden_de_corte_envio_taller AS;
	Select ;
	Orden_de_corte_cab.numero,;
	Orden_de_corte_cab.fecha_hora,;
	Orden_de_corte_cab.promedio_real as promedio,;
	Articulos.descripcion As Descrip ;
	FROM Orden_de_corte_cab  ;
	INNER Join ORDEN_DE_CORTE_DET ON  ORDEN_DE_CORTE_DET.numero = Orden_de_corte_cab.numero ;
	INNER Join Articulos ON  ORDEN_DE_CORTE_DET.articulo = Articulos.codigo ;
	where !Empty(ORDEN_DE_CORTE_DET.PARA_ENVIO_TALLER) ;
	GROUP By Orden_de_corte_det.numero ;
	ORDER By Orden_de_corte_cab.numero