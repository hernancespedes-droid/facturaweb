********************************
* Cocinero de Casa Suarez      *
* Actualiza los archivos desde *
* clietes.txt y articulo.txt   *
********************************

SET EXCL ON
SET TALK OFF
SET SAFETY OFF
open data datos
SELECT 0
use articulos ALIAS art
zap

SELECT 0
USE cs_articulos ALIAS cs
reg = recc()

scan
   WAIT WINDOW "Registro:"+STR(RECNO())+"  de:"+STR(reg) NOWAIT
   SELECT art
   APPEND BLANK
   REPLACE codigo 							WITH cs.cod_art
   REPLACE codigo_asociado 				WITH cs.cod_art
   REPLACE codigo_de_barras				WITH cs.cod_art
   REPLACE descripcion 						WITH cs.descripcio
   REPLACE costo_reposicion_neto 			WITH cs.costo_prov
   REPLACE costo_ultima_entrada_unidad 		WITH cs.costo_prov
   REPLACE precio_de_venta_1 				WITH cs.lista_pr1
   REPLACE precio_de_venta_2 				WITH cs.lista_pr2
   REPLACE precio_de_venta_3 				WITH cs.lista_pr2
   REPLACE stock_minimo_unidades 			WITH cs.stock_min
   		   _fec = STR(cs.dia,2)+"/"+STR(cs.mes,2)+"/"+STR(cs.anio,2)
   REPLACE fecha_ultimo_cambio_de_precio 	WITH CTOD(_fec)
   REPLACE unidades_por_pack 			WITH 1
   REPLACE cantidad_de_unidades 		WITH 1
   REPLACE estado 						WITH "AC"
   REPLACE forma_de_venta 				WITH 2
   REPLACE stock_minimo_unidades 		WITH 1
   REPLACE bonificacion_mercaderia_cada	WITH 1
   REPLACE columnas						WITH 1
   REPLACE precio_de_oferta				WITH 0
   REPLACE precio_reposicion_caja		WITH 0
   REPLACE unidad_de_medida				WITH "01"
   REPLACE rubro						WITH "001"
   REPLACE proveedor					WITH 1
endscan
CLOSE TABLE ALL

*------------------------------------------------------------

SELECT 0
use clientes ALIAS cli
zap

SELECT 0
USE cs_clientes ALIAS cs
reg = recc()

scan
   WAIT WINDOW "Registro:"+STR(RECNO())+"  de:"+STR(reg) NOWAIT
   SELECT cli
   APPEND BLANK
   REPLACE codigo 			WITH cs.nro_client
   REPLACE razon_social    	WITH cs.razon_soci
   REPLACE domicilio       	WITH cs.direccion
   REPLACE codigo_postal	WITH cs.cod_post
   REPLACE localidad        WITH cs.localidad 
   REPLACE telefono         WITH cs.telefono
   REPLACE iva              WITH cs.sit_iva
   		   _cuit = LEFT(cs.cuit,2)+SUBSTR(cs.cuit,4,8)+SUBSTR(cs.cuit,13,1)
   REPLACE cuit             WITH VAL(_cuit)
   REPLACE descuento        WITH cs.descuento
   REPLACE credito          WITH cs.credito  
   		   _fec = STR(cs.dia_ultcpr,2)+"/"+STR(cs.mes_ultcpr,2)+"/"+STR(cs.anio_ultcp,2)
   REPLACE ultima_compra    WITH CTOD(_fec)
   REPLACE observaciones 	WITH "Otros Tel.: "+cs.telefono
   REPLACE provincia 		WITH "09"
endscan

CLOSE TABLE ALL

