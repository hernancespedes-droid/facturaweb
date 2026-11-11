Set Exclusive On
Close tables All
Set EngineBehavior 70 
USE articulos Order codigo In 0
USE estadistica In 0

*****ACA PONER LA COTIZACION*****
lnCotiz=3.00
*****ACA PONER LA FECHA HASTA EN FORMATO AAAAMMDD*****
ldFecha='20040712'

If Empty(lnCotiz)
	MessageBox('Falta poner la cotizacion del U$S',64,'ERROR')
Else	
	Select estadistica
	Scan 
		If SEEK(articulo,"ARTICULOS","CODIGO")
		   Wait Window NoWait 'Actualizando....'	
		   If Upper(articulos.moneda)='U$S'
			   	cotizacion = lnCotiz
		   Else
				cotizacion = 1
		   EndIf 	
	      _Costo = ROUND(costo * cotizacion,4)
		   REPLACE costo WITH _Costo
		EndIf 
	EndScan 
EndIf 