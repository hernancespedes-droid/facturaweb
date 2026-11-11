* ACTUALIZO TABLA DE CLIENTES
* SEGUN EL DKT DE SILVINA

SELECT 0
USE CLIENTES
SELECT 0 
USE _CLIENTES
SCAN
   APPEND BLANK IN CLIENTES
   IF codsuc = 2
      REPLACE clientes.codigo WITH VAL("2"+ALLTRIM(STR(codigo,7)))
   ELSE
      REPLACE clientes.codigo WITH VAL("3"+ALLTRIM(STR(codigo,7)))
   ENDIF
   REPLACE clientes.razon_social WITH nombre
   REPLACE clientes.domicilio    WITH direcc
   REPLACE clientes.localidad    WITH locali
   REPLACE clientes.codigo_postal WITH codpos
   IF coniva = 3
      REPLACE clientes.iva WITH 4
   ELSE
      REPLACE clientes.iva WITH coniva
   ENDIF   
   REPLACE clientes.cuit         WITH VAL(LEFT(cuittt,2)+SUBSTR(cuittt,4,8)+SUBSTR(cuittt,13,1))
   REPLACE clientes.tipo_cliente WITH ALLTRIM(STR(tipcli,2))
   REPLACE clientes.provincia    WITH ALLTRIM(STR(provin,2))
   REPLACE clientes.credito      WITH credito
ENDSCAN
