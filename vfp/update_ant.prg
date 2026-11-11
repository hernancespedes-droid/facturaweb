mens = 'Este Proceso Actualizará los datos del sistema'+CHR(13)+;
   'desde la bandeja de entrada \Quesos\Ant '+CHR(13)+;
   'Confirma este Proceso?'
r=MESSAGEBOX(mens,4+32+0,'Actualización de Datos')
IF r # 6  && 6 = si
   RETURN
ENDIF

SET SAFETY OFF
SET TALK OFF
SET DELE ON
OPEN DATA DATOS EXCL
DIME tabla(28)
tabla(01)='articulos'
tabla(02)='art_transf'
tabla(03)='auditoria'
tabla(04)='bancos'
tabla(05)='caja'
tabla(06)='clientes'
tabla(07)='cobra'
tabla(08)='cotizacion_dolar'
tabla(09)='ctact'
tabla(10)='errores'
tabla(11)='estadistica'
tabla(12)='estado_de_articulos'
tabla(13)='forma_de_pagos'
tabla(14)='imputaciones'
tabla(15)='mvstk_cabecera'
tabla(16)='mvstk_items'
tabla(17)='parametros'
tabla(18)='productos_relacionados'
tabla(19)='provincias'
tabla(20)='rubros'
tabla(21)='stock'
tabla(22)='subdiario'
tabla(23)='subrubros'
tabla(24)='sucursales'
tabla(25)='tipos_mvstk'
tabla(26)='tipo_de_clientes'
tabla(27)='transformaciones'
tabla(28)='unidad_de_medidas'

FOR i = 1 TO 28
   SELECT 0
   USE (tabla(i)) EXCL
   ZAP
   _ant="ANT\"+tabla(i)+".DBF"
   IF FILE(_ant)
      _app="ant\"+tabla(i)
      APPEND FROM (_app)
   ENDIF
   REINDEX
   USE
ENDFOR


VALIDATE DATA
CLOSE ALL
mens = 'Proceso finalizado exitosamente !!!'
r=MESSAGEBOX(mens,0+48+0,'Actualización de Datos')

