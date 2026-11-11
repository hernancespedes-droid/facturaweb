PARAMETERS opMenu, _operacion

IF !USED("auditoria")
   USE auditoria IN 0
ENDIF
   
IF !EMPTY(_usuario)
   INSERT INTO auditoria (usuario,canal,sucursal,terminal,fechahora,programa,operacion);
          VALUES (_usuario,_gcanal,_gsucursal,_gterminal,DATETIME(),opMenu,_operacion)
ELSE
   INSERT INTO auditoria (usuario,canal,sucursal,terminal,fechahora,programa,operacion);
          VALUES ('Mostrador',_gcanal,_gsucursal,_gterminal,DATETIME(),opMenu,_operacion)
ENDIF
