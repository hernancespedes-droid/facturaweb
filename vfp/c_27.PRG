***********************************************
* Cocinero para alicuotas de iva por articulo *
***********************************************
Set SAFETY OFF
Set TALK OFF
Set DELE ON
Close TABLES ALL
Close DATA ALL
Open DATA datos EXCLUSIVE
CREATE CURSOR CFECHA ( CLIENTES N(5),  TIPODB C(2), TICODB N(1),  NRCODB N(7),  TIPOCR C(2), TICOCR N(1),;
                       NRCOCR N(7),IMPORTE N(16,2), FECODB D(8),FECOCR D(8), RECIBO  N(7),;
                      ACTUALIZACION T(8), LETRADB C(1), DIFER C(10))


USE CTACT IN  0 
USE imputaciones IN 0
SELECT imputaciones
GO TOP
SCAN
  IF !SEEK(STR(clientes,5)+DTOS(FECODB)+TIPODB+STR(1,2)+STR(NRCODB,7),'ctact', 'cliente')
    SCATTER memvar
    m.difer='D'
    INSERT INTO cfecha from memvar
 endif   
  IF !SEEK(STR(clientes,5)+DTOS(FECOCR)+TIPOCR+STR(1,2)+STR(NRCOCR,7),'ctact', 'cliente')
    SCATTER memvar
    m.difer='C'
    INSERT INTO cfecha from memvar
 endif   
    
ENDSCAN
SELECT CFECHA
BROWSE