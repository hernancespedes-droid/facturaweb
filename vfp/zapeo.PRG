mens = 'Este Proceso BORRARA los datos del sistema'+CHR(13)+;
       'Confirma este Proceso?'
r=MESSAGEBOX(mens,4+32+0,'Actualización de Datos')
IF r # 6  && 6 = si
   RETURN
ENDIF

SET SAFETY OFF
SET TALK OFF
SET DELE ON
*tabla(01)='art_transf'
*tabla(02)='articulos'
*tabla(05)='bancos'
*tabla(07)='clientes'
*tabla(09)='cotizacion_dolar'
*tabla(14)='forma_de_pagos'
*tabla(19)='productos_relacionados'
*tabla(20)='provincias'
*tabla(21)='rubros'
*tabla(24)='subrubros'
*tabla(28)='transformaciones'
*tabla(17)='sucursales'
*tabla(19)='tipo_de_clientes'
*tabla(20)='unidad_de_medidas'
*tabla(10)='estado_de_articulos'
*tabla(18)='tipos_mvstk'
*tabla(14)='parametros'

OPEN DATA DATOS EXCL
DIME tabla(11)
tabla(01)='auditoria'
tabla(02)='caja'
tabla(03)='cobra'
tabla(04)='ctact'
tabla(05)='errores'
tabla(06)='estadistica'
tabla(07)='imputaciones'
tabla(08)='mvstk_cabecera'
tabla(09)='mvstk_items'
tabla(10)='stock'
tabla(11)='subdiario'

FOR i = 1 TO 11
   SELECT 0
   USE (tabla(i)) EXCL
   ZAP
   REINDEX
   USE
ENDFOR

VALIDATE DATA
CLOSE ALL
mens = 'Proceso finalizado exitosamente !!!'
r=MESSAGEBOX(mens,0+48+0,'Actualización de Datos')

