SET SAFETY OFF
SET TALK OFF
SET DELE ON
OPEN DATA DATOS EXCL
*1лллллллллллллллллллллллллллллллллллллллллллллл
SELECT 0
USE articulos EXCL
ZAP
IF FILE("ANT\articulos.DBF")
   APPEND FROM ant\articulos
ENDIF   
REINDEX
USE
*2лллллллллллллллллллллллллллллллллллллллллллллл
SELECT 0
USE bancos EXCL
ZAP
IF FILE("ANT\bancos.DBF")
   APPEND FROM ant\bancos
ENDIF   
REINDEX
USE
*2лллллллллллллллллллллллллллллллллллллллллллллл
SELECT 0
USE caja EXCL
ZAP
IF FILE("ANT\caja.DBF")
   APPEND FROM ant\caja
ENDIF   
REINDEX
USE
*2лллллллллллллллллллллллллллллллллллллллллллллл
SELECT 0
USE clientes EXCL
ZAP
IF FILE("ANT\clientes.DBF")
   APPEND FROM ant\clientes
ENDIF   
REINDEX
USE
*2лллллллллллллллллллллллллллллллллллллллллллллл
SELECT 0
USE cotizacion_dolar EXCL
ZAP
IF FILE("ANT\cotizacion_dolar.DBF")
   APPEND FROM ant\cotizacion_dolar
ENDIF   
REINDEX
USE
*2лллллллллллллллллллллллллллллллллллллллллллллл
SELECT 0
USE ctact EXCL
ZAP
IF FILE("ANT\ctact.DBF")
   APPEND FROM ant\ctact
ENDIF   
REINDEX
USE
*2лллллллллллллллллллллллллллллллллллллллллллллл
SELECT 0
USE errores EXCL
ZAP
IF FILE("ANT\errores.DBF")
   APPEND FROM ant\errores
ENDIF   
REINDEX
USE
*2лллллллллллллллллллллллллллллллллллллллллллллл
SELECT 0
USE estadistica EXCL
ZAP
IF FILE("ANT\estadistica.DBF")
   APPEND FROM ant\estadistica
ENDIF   
REINDEX
USE
*2лллллллллллллллллллллллллллллллллллллллллллллл
SELECT 0
USE estado_de_articulos EXCL
ZAP
IF FILE("ANT\estado_de_articulos.DBF")
   APPEND FROM ant\estado_de_articulos
ENDIF   
REINDEX
USE
*2лллллллллллллллллллллллллллллллллллллллллллллл
SELECT 0
USE forma_de_pagos EXCL
ZAP
IF FILE("ANT\forma_de_pagos.DBF")
   APPEND FROM ant\forma_de_pagos
ENDIF   
REINDEX
USE
*2лллллллллллллллллллллллллллллллллллллллллллллл
SELECT 0
USE mvstk_items EXCL
ZAP
IF FILE("ANT\mvstk_items.DBF")
   APPEND FROM ant\mvstk_items
ENDIF   
REINDEX
USE
*2лллллллллллллллллллллллллллллллллллллллллллллл
SELECT 0
USE mvstk_cabecera EXCL
ZAP
IF FILE("ANT\mvstk_cabecera.DBF")
   APPEND FROM ant\mvstk_cabecera
ENDIF   
REINDEX
USE
*2лллллллллллллллллллллллллллллллллллллллллллллл
SELECT 0
USE parametros EXCL
ZAP
IF FILE("ANT\parametros.DBF")
   APPEND FROM ant\parametros
ENDIF   
REINDEX
USE
*2лллллллллллллллллллллллллллллллллллллллллллллл
SELECT 0
USE productos_relacionados EXCL
ZAP
IF FILE("ANT\productos_relacionados.DBF")
   APPEND FROM ant\productos_relacionados
ENDIF   
REINDEX
USE
*2лллллллллллллллллллллллллллллллллллллллллллллл
SELECT 0
USE provincias EXCL
ZAP
IF FILE("ANT\provincias.DBF")
   APPEND FROM ant\provincias
ENDIF   
REINDEX
USE
*2лллллллллллллллллллллллллллллллллллллллллллллл
SELECT 0
USE rubros EXCL
ZAP
IF FILE("ANT\rubros.DBF")
   APPEND FROM ant\rubros
ENDIF   
REINDEX
USE
*2лллллллллллллллллллллллллллллллллллллллллллллл
SELECT 0
USE stock EXCL
ZAP
IF FILE("ANT\stock.DBF")
   APPEND FROM ant\stock
ENDIF   
REINDEX
USE
*2лллллллллллллллллллллллллллллллллллллллллллллл
SELECT 0
USE subdiario EXCL
ZAP
IF FILE("ANT\subdiario.DBF")
   APPEND FROM ant\subdiario
ENDIF   
REINDEX
USE
*2лллллллллллллллллллллллллллллллллллллллллллллл
SELECT 0
USE subrubros EXCL
ZAP
IF FILE("ANT\subrubros.DBF")
   APPEND FROM ant\subrubros
ENDIF   
REINDEX
USE
*2лллллллллллллллллллллллллллллллллллллллллллллл
SELECT 0
USE sucursales EXCL
ZAP
IF FILE("ANT\sucursales.DBF")
   APPEND FROM ant\sucursales
ENDIF   
REINDEX
USE
*2лллллллллллллллллллллллллллллллллллллллллллллл
SELECT 0
USE tipos_mvstk EXCL
ZAP
IF FILE("ANT\tipos_mvstk.DBF")
   APPEND FROM ant\tipos_mvstk
ENDIF   
REINDEX
USE
*2лллллллллллллллллллллллллллллллллллллллллллллл
SELECT 0
USE tipo_de_clientes EXCL
ZAP
IF FILE("ANT\tipo_de_clientes.DBF")
   APPEND FROM ant\tipo_de_clientes
ENDIF   
REINDEX
USE
*2лллллллллллллллллллллллллллллллллллллллллллллл
SELECT 0
USE transformaciones EXCL
ZAP
IF FILE("ANT\transformaciones.DBF")
   APPEND FROM ant\transformaciones
ENDIF   
REINDEX
USE
*2лллллллллллллллллллллллллллллллллллллллллллллл
SELECT 0
USE unidad_de_medidas EXCL
ZAP
IF FILE("ANT\unidad_de_medidas.DBF")
   APPEND FROM ant\unidad_de_medidas
ENDIF   
REINDEX
USE
*2лллллллллллллллллллллллллллллллллллллллллллллл

VALIDATE DATA
CLOSE ALL
