****************************************************
* Necesito este indice para poder borrar un recibo *
* en la cuenta corriente                           *
****************************************************
SET SAFETY OFF
SET TALK OFF
SET DELE ON
CLOSE TABLES ALL
CLOSE DATA ALL
OPEN DATA datos EXCLUSIVE
*_________________________________________________________________*
  USE CTACT EXCLUSIVE
  INDEX ON STR(cliente,5)+DTOS(fecha)+tipo+STR(numero,7) TAG BAJA_REC ADDITIVE
  REINDEX
  USE
WAIT WINDOW 'Ordenando Integridad Referencial... Aguarde !!!' AT 28,20 NOWAIT
VALIDATE DATABASE NOCONSOLE
CLOSE DATA
WAIT CLEAR
WAIT WINDOW 'PROCESO FINALIZADO EXITOSAMENTE!!! <Enter>'