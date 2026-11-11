set excl off
open data datos

SELECT 0
USE CAJA
REPLACE ALL comprobante WITH comprobante + 1000000;
   FOR comprobante < 1000000 AND;
   year(fecha) < 1999
   
SELECT 0
USE CTACT
REPLACE ALL numero WITH numero + 1000000;
   FOR numero < 1000000 AND;
   year(fecha) < 1999
   
SELECT 0
USE ESTADISTICA
REPLACE ALL comprobante WITH comprobante + 1000000;
   FOR comprobante < 1000000 AND;
   year(fecha) < 1999   
   
SELECT 0
USE MVSTK_CABECERA
REPLACE ALL numero WITH numero + 1000000;
   FOR numero < 1000000 AND;
   year(fecha) < 1999   
   
SELECT 0
USE SUBDIARIO
REPLACE ALL comprobante WITH comprobante + 1000000;
   FOR comprobante < 1000000 AND;
   year(fecha) < 1999

SELECT 0
USE H_CAJA
REPLACE ALL comprobante WITH comprobante + 1000000;
   FOR comprobante < 1000000 AND;
   year(fecha) < 1999

SELECT 0
USE H_ESTADISTICA
REPLACE ALL comprobante WITH comprobante + 1000000;
   FOR comprobante < 1000000 AND;
   year(fecha) < 1999

SELECT 0
USE H_SUBDIARIO
REPLACE ALL comprobante WITH comprobante + 1000000;
   FOR comprobante < 1000000 AND;
   year(fecha) < 1999

SELECT 0
USE imputaciones
REPLACE ALL nrcodb WITH nrcodb + 1000000;
   FOR nrcodb < 1000000 AND;
   year(fecodb) < 1999
   
CLOSE ALL
