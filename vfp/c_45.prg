Do SETEOS
Set Dele On
Set Collate To "MACHINE"
Close Tables All
Close Data All

*!*	USE PARAMETROS
*!*	lcData = Alltrim(parametros.path_bup) + 'datosbup'
*!*	use
*!*	Open Data (lcData) Exclusive
Alter Table  BUP\mvstk_items   ADD  costo N(14,4) not null

Close Data All
Open Data datos Exclusive


Alter Table  mvstk_items   ADD  costo N(14,4) not null
Alter Table  mvstkmp_items ADD  costo N(14,4) not null
Close Tables

Create SQL View  v_mvstkmp_items as ;
SELECT Mvstkmp_items.numero_mvstk_mp, SPACE(15) AS codigo_mp,;
  SPACE(40) AS descripcion, Mvstkmp_items.cantidad_unidades,;
  Mvstkmp_items.cantidad_kilos, 0000000.00000 AS costo;
 FROM ;
     datos!MVSTKMP_ITEMS;
 WHERE  ( .F. ) 

ThisView = 'v_mvstkmp_items'
DBSetProp(ThisView,"View","SendUpdates",.F.)
DBSetProp(ThisView,"View","BatchUpdateCount",1)
DBSetProp(ThisView,"View","CompareMemo",.T.)
DBSetProp(ThisView,"View","FetchAsNeeded",.F.)
DBSetProp(ThisView,"View","FetchMemo",.T.)
DBSetProp(ThisView,"View","FetchSize",100)
DBSetProp(ThisView,"View","MaxRecords",-1)
DBSetProp(ThisView,"View","Prepared",.F.)
DBSetProp(ThisView,"View","UpdateType",1)
DBSetProp(ThisView,"View","UseMemoSize",255)
DBSetProp(ThisView,"View","Tables","datos!mvstkmp_items")
DBSetProp(ThisView,"View","WhereType",3)

DBSetProp(ThisView+".numero_mvstk_mp","Field","DataType","N(10)")
DBSetProp(ThisView+".numero_mvstk_mp","Field","UpdateName","datos!mvstkmp_items.numero_mvstk_mp")
DBSetProp(ThisView+".numero_mvstk_mp","Field","KeyField",.F.)
DBSetProp(ThisView+".numero_mvstk_mp","Field","Updatable",.T.)

DBSetProp(ThisView+".codigo_mp","Field","DataType","C(15)")
DBSetProp(ThisView+".codigo_mp","Field","KeyField",.F.)
DBSetProp(ThisView+".codigo_mp","Field","Updatable",.F.)

DBSetProp(ThisView+".descripcion","Field","DataType","C(40)")
DBSetProp(ThisView+".descripcion","Field","KeyField",.F.)
DBSetProp(ThisView+".descripcion","Field","Updatable",.F.)

DBSetProp(ThisView+".cantidad_unidades","Field","DataType","N(12,2)")
DBSetProp(ThisView+".cantidad_unidades","Field","UpdateName","datos!mvstkmp_items.cantidad_unidades")
DBSetProp(ThisView+".cantidad_unidades","Field","KeyField",.F.)
DBSetProp(ThisView+".cantidad_unidades","Field","Updatable",.T.)

DBSetProp(ThisView+".cantidad_kilos","Field","DataType","N(12,3)")
DBSetProp(ThisView+".cantidad_kilos","Field","UpdateName","datos!mvstkmp_items.cantidad_kilos")
DBSetProp(ThisView+".cantidad_kilos","Field","KeyField",.F.)
DBSetProp(ThisView+".cantidad_kilos","Field","Updatable",.T.)

DBSetProp(ThisView+".costo","Field","DataType","N(4,2)")
DBSetProp(ThisView+".costo","Field","UpdateName","00")
DBSetProp(ThisView+".costo","Field","KeyField",.F.)
DBSetProp(ThisView+".costo","Field","Updatable",.F.)


Close All 
Quit
