SELECT iva
SET ORDER TO CLAVE   && TIPO+LETRA+STR(SUCURSAL,2)+STR(COMPROBANTE,7) 

primer  = 6
largo   = 56
i       = 0

DO WHILE .T. 
   ininio = primer + (largo * i)   
   
   vbus = SUBSTR(rec.memo,ininio,2) + SUBSTR(rec.memo,ininio + 2,1) + SUBSTR(rec.memo,ininio + 3,2) + SUBSTR(rec.memo,ininio + 5,7)
   IF !SEEK(vbus,"IVA","CLAVE")
      APPEND BLANK
   ENDIF
      
   REPLACE iva.tipo        WITH SUBSTR(rec.memo,ininio,2)             ,;
           iva.letra       WITH SUBSTR(rec.memo,ininio + 2,1)         ,;
           iva.sucursal    WITH VAL(SUBSTR(rec.memo,ininio + 3,2))    ,;
           iva.comprobante WITH VAL(SUBSTR(rec.memo,ininio + 5,7))    ,;
           iva.alicuota    WITH VAL(SUBSTR(rec.memo,ininio + 12,5))   ,;
           iva.monto       WITH VAL(SUBSTR(rec.memo,ininio + 17,10))  ,;
           iva.importe     WITH VAL(SUBSTR(rec.memo,ininio + 27,10))
   i = i + 1
   IF primer + (largo * i) >= LEN(rec.memo)       
      EXIT
   ENDIF
ENDDO           
