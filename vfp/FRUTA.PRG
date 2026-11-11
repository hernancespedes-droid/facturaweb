SET DATE DMY
SET CENTURY ON
SET SAFE OFF
CLOSE ALL
Clear ALL
SET EXCLU On
OPEN DATA DATOS
SELECT 0
USE mvstk_items ORDER numero
SELECT 0
USE mvstk_cabecera ORDER numero
SET DELETE ON
SELECT mvstk_cabecera

SELECT mvstk_items
SCAN
     IF !SEEK(numero_mvstk,'MVSTK_CABECERA','NUMERO')
          BROW
     ENDIF
ENDSCAN


SELECT mvstk_cabecera
SCAN
	IF !SEEK(numero_mvstk,'MVSTK_ITEMS','NUMERO')
		BROW
	ENDIF
ENDSCAN

