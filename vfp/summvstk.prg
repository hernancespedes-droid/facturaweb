CLOSE ALL
SET EXCLU ON
OPEN DATA DATOS

numsum=2000000
USE mvstk_items IN 0
USE mvstk_cabecera IN 0
USE parametros IN 0

SELECT mvstk_items 
replace all numero_mvstk WITH numero_mvstk+numsum 

SELECT mvstk_cabecera 
replace ALL numero_mvstk WITH numero_mvstk+numsum 

SELECT parametros 
replace ALL ultimo_mvstk WITH ultimo_mvstk+numsum

WAIT 'PROCESO FINALIZADO' WINDOW timeout 2 
CLOSE TABLES ALL