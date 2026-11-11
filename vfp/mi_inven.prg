SET DATE DMY
SET CENTURY ON
SET SAFE OFF
CLOSE ALL
Clear ALL
SET EXCLU OFF
OPEN DATA DATOS
SELECT 0
USE mvstk_items ORDER numero
SELECT 0
USE stock ORDER SUCURSAL
SELECT 0
USE mvstk_cabecera ORDER numero

GO TOP
SELECT * FROM mvstk_cabecera WHERE;
     DTOS(FECHA)='19980608' AND;
     TIPO#'RP' AND ORIGEN=1 INTO CURSOR C_UNO
GO TOP
SCAN
     IF SEEK(numero_mvstk,'MVSTK_ITEMS','NUMERO')
        SELECT mvstk_items
        DO WHILE !EOF() AND numero_mvstk=C_UNO.numero_mvstk
          Delete 
          SKIP
        ENDDO  
     ENDIF
ENDSCAN
USE

SELECT mvstk_cabecera
Delete ALL FOR DTOS(FECHA)='19980608' AND;
     TIPO#'RP' AND ORIGEN=1

SELECT stock
Delete ALL FOR SUCURSAL=1

SET DELE ON
SELECT mvstk_items
SCAN
     IF SEEK(numero_mvstk,'MVSTK_CABECERA','NUMERO')
          IF !EMPTY(mvstk_cabecera.SIGNO_ORIGEN)
               SIGNO=mvstk_cabecera.SIGNO_ORIGEN
               XSUCURSAL=mvstk_cabecera.ORIGEN
          ELSE
               SIGNO=mvstk_cabecera.SIGNO_DESTINO
               XSUCURSAL=mvstk_cabecera.DESTINO
          ENDIF
          IF XSUCURSAL=1
               IF !SEEK(STR(XSUCURSAL,2)+STR(articulo,7),'STOCK','SUCURSAL')
                    IF SIGNO = '+'
                         INSERT INTO stock (SUCURSAL,articulo,unidades,kilos);
                              VALUES (XSUCURSAL,;
                              mvstk_items.articulo,;
                              mvstk_items.cantidad_unidades,;
                              mvstk_items.cantidad_kilos)
                    ELSE
                         INSERT INTO stock (SUCURSAL,articulo,unidades,kilos);
                              VALUES (XSUCURSAL,;
                              mvstk_items.articulo,;
                              -mvstk_items.cantidad_unidades,;
                              -mvstk_items.cantidad_kilos)
                    ENDIF
               ELSE
                    SELECT stock
                    IF SIGNO = '+'
                         REPLACE unidades WITH unidades+mvstk_items.cantidad_unidades
                         REPLACE kilos WITH kilos+mvstk_items.cantidad_kilos
                    ELSE
                         REPLACE unidades WITH unidades-mvstk_items.cantidad_unidades
                         REPLACE kilos WITH kilos-mvstk_items.cantidad_kilos
                    ENDIF
                    SELECT mvstk_items
               ENDIF
          ENDIF
     ELSE
          Delete
     ENDIF
ENDSCAN
