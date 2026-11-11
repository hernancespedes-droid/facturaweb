*-Este cocinero se origina de 4 cocineros hechos por maxi 
*-(crea_view_nuevos.prg,c_ordcomp_detalle_mp.prg,c_desc_artpro.prg,c_ancho.prg)
DO seteos
Set EngineBehavior 70
Set Safety Off
Set Talk Off
Set Dele On
Set Collate To "MACHINE"
Close Tables All
Close Data All
Open Data datos Exclusive


Alter Table ANCHOS ALTER ancho N(5,3) NOT NULL
Alter Table materias_primas ALTER ancho N(5,3) NOT NULL
Alter Table mp_por_proveedor ALTER descripcion_proveedor c(55) NOT NULL
Alter Table ordcomp_detalle_mp add proveedor N(5) 

CLOSE TABLES all
*-------------------------------------------------------
*  Modificar vistas ya existentes
*-------------------------------------------------------
*USE parametros

*v_articulos
Create VIEW v_articulos AS;
SELECT DISTINCT Articulos.descripcion, Articulos.codigo;
 FROM ;
     datos!Articulos;
 ORDER BY Articulos.descripcion
 
*v_clientes
Create VIEW v_clientes AS;
SELECT DISTINCT Clientes.razon_social, Clientes.codigo;
 FROM ;
     datos!Clientes;
 ORDER BY Clientes.razon_social
 
 *v_clientes2
Create VIEW v_clientes2 AS; 
 SELECT DISTINCT Clientes.nombre_fantasia, Clientes.codigo;
 FROM ;
     datos!Clientes;
 ORDER BY Clientes.nombre_fantasia

 *v_orden_detalle_mp
Create VIEW v_orden_detalle_mp AS; 
SELECT Ordcomp_detalle_mp.materia_prima AS articulo,;
  Ordcomp_detalle_mp.materia_prima AS articulo_mp,;
  SPACE(40) AS descrip, SPACE(3) AS moneda, 9999.9999 AS costo,;
  0.00 AS desc1, 0.00 AS desc2, 0.00 AS desc3, Ordcomp_detalle_mp.precio,;
  Ordcomp_detalle_mp.cantidad, 0.00 AS iva, SPACE(15) AS nro_int,;
  Ordcomp_detalle_mp.materia_prima AS origen, SPACE(1) AS marca,;
  0.00 AS cant_ant, Ordcomp_detalle_mp.proveedor;
 FROM ;
     datos!ordcomp_detalle_MP;
 WHERE  ( .F. ) 

 
VALIDATE DATABASE 
quit