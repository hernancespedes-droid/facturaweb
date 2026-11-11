SET DATE DMY
SET CENTURY ON
SET SAFE OFF
SET TALK ON
CLOSE ALL
Clear ALL
SET EXCLU On
OPEN DATA DATOS
SELECT 0
USE mvstk_items ORDER NUMERO
SELECT 0
USE mvstk_cabecera ORDER NUMERO
SELECT 0
USE caja ORDER CLAVE
SELECT 0
USE estadistica ORDER CLAVE
SELECT 0
USE subdiario ORDER CLAVE
SELECT 0
USE ctact ORDER CLAVE

SELECT mvstk_items
*APPEND FROM c:\quesos\100698\mvstk_items
REINDEX
SELECT mvstk_cabecera
*APPEND FROM c:\quesos\100698\mvstk_cabecera
REINDEX
SELECT caja
*APPEND FROM c:\quesos\100698\caja
REINDEX
SELECT estadistica
*APPEND FROM c:\quesos\100698\estadistica 
REINDEX
SELECT subdiario
*APPEND FROM c:\quesos\100698\subdiario
REINDEX
SELECT ctact
*APPEND FROM c:\quesos\100698\ctact
CLOSE ALL
