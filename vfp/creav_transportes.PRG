create view v_transportes as;
SELECT DISTINCT transportes.descripcion, transportes.codigo;
 FROM ;
     datos!transportes;
 ORDER BY transportes.descripcion

DBSetProp("v_transportes","View","SendUpdates",.F.)
DBSetProp("v_transportes","View","BatchUpdateCount",1)
DBSetProp("v_transportes","View","CompareMemo",.T.)
DBSetProp("v_transportes","View","FetchAsNeeded",.F.)
DBSetProp("v_transportes","View","FetchMemo",.T.)
DBSetProp("v_transportes","View","FetchSize",100)
DBSetProp("v_transportes","View","MaxRecords",-1)
DBSetProp("v_transportes","View","Prepared",.F.)
DBSetProp("v_transportes","View","UpdateType",1)
DBSetProp("v_transportes","View","UseMemoSize",255)
DBSetProp("v_transportes","View","Tables","datos!transportes")
DBSetProp("v_transportes","View","WhereType",3)

DBSetProp("v_transportes"+".descripcion","Field","DataType","C(30)")
DBSetProp("v_transportes"+".descripcion","Field","UpdateName","datos!transportes.descripcion")
DBSetProp("v_transportes"+".descripcion","Field","KeyField",.F.)
DBSetProp("v_transportes"+".descripcion","Field","Updatable",.T.)

DBSetProp("v_transportes"+".codigo","Field","DataType","N(3)")
DBSetProp("v_transportes"+".codigo","Field","UpdateName","datos!transportes.codigo")
DBSetProp("v_transportes"+".codigo","Field","KeyField",.T.)
DBSetProp("v_transportes"+".codigo","Field","Updatable",.F.)

