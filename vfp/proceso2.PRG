* ACTUALIZO TABLA DE ARTICULOS
* SEGUN EL DKT DE SILVINA

SELECT 0
USE ARTICULOS
SELECT 0 
USE _PRODUCTOS
SCAN
   IF !EMPTY(producto)
   APPEND BLANK IN ARTICULOS
   REPLACE articulos.codigo          WITH producto
   REPLACE articulos.codigo_asociado WITH producto   
   REPLACE articulos.descripcion     WITH nombre
   REPLACE articulos.rubro           WITH ALLTRIM(STR(rubro,2))
   IF tipuni = 0
      REPLACE articulos.stock_minimo_unidades WITH stock_min
   ELSE
      REPLACE articulos.stock_minimo_kilos    WITH stock_min
   ENDIF
   REPLACE articulos.precio_de_venta_1 WITH lis_do1
   REPLACE articulos.precio_de_venta_2 WITH lis_do2
   REPLACE articulos.precio_de_venta_3 WITH lis_do3
   ENDIF
ENDSCAN
