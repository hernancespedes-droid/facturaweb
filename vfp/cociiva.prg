Set Function 4 To *
Set Function 5 To *
Set Function 8 To *

Set Near             Off
Set Echo             Off
Set Talk             Off
Set Safety           Off
Set Escape           Off
Set Debug            Off
Set Step             Off
Set Resource         Off
Set Status Bar       Off
Set Dohistory        Off
Set Notify           Off
Set Century          Off
Set Delete            On
Set Exclusive        Off
Set Exact            Off
Set Cpdialog         Off
Set Autosave          On
Set Alternate        Off
Set Bell             Off
Set Escape           Off
Set Console          Off
Set Compatible       Off
Set Multilocks        On

Set Refresh To      1
Set StrictDate To   0
Set ENGINEBEHAVIOR 70
Set Blocksize To   33
Set Point To      "."
Set Date To       Dmy
Set Century To 19 ROLLOVER 90
Set Reproces To 3 Second

=Capslock(.T.)
CLOSE TABLES ALL
USE DATOS!IVA IN 0
USE BUP\datosbup!IVA IN 0 ALIAS IVAN
USE DATOS!SUBDIARIO IN 0
SELECT SUBDIARIO
SET ORDER TO CLAVE   && TIPO+LETRA+STR(SUCURSAL,2)+STR(COMPROBANTE,7)
SELECT IVA
SCAN
 IF !SEEK(TIPO+LETRA+STR(SUCURSAL,2)+STR(COMPROBANTE,7),'SUBDIARIO','CLAVE')
   SCATTER MEMVAR
   INSERT INTO IVAN FROM MEMVAR
   DELETE
 ENDIF  
ENDSCAN		

