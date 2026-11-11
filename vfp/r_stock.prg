SET DATE DMY
SET CENTURY ON
SET SAFE OFF
CLOSE ALL
Clear ALL
SET EXCLU On
OPEN DATA DATOS
SELECT 0
USE mvstk_items ORDER numero
pack
SELECT 0
USE stock ORDER SUCURSAL
SELECT 0
USE mvstk_cabecera ORDER numero
pack
SET DELETE ON
SELECT mvstk_cabecera
SET STEP ON
REPLACE ALL signo_origen WITH ' ' , origen WITH 0 FOR tipo='RS'

SELECT stock
ZAP

SELECT mvstk_items
SCAN
     IF SEEK(numero_mvstk,'MVSTK_CABECERA','NUMERO')
          IF !EMPTY(mvstk_cabecera.signo_origen)
               SIGNO=mvstk_cabecera.signo_origen
               XSUCURSAL=mvstk_cabecera.origen
          ELSE
               SIGNO=mvstk_cabecera.SIGNO_DESTINO
               XSUCURSAL=mvstk_cabecera.DESTINO
          ENDIF

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

     ELSE
          Delete
     ENDIF
ENDSCAN
PACK

SELECT mvstk_cabecera
SCAN
	IF !SEEK(numero_mvstk,'MVSTK_ITEMS','NUMERO')
		Delete
	ENDIF
ENDSCAN
PACK
