*do seteos
SET EXCLUSIVE off
local lcArchivo, axvan
axvan=0
lcArchivo="veri_facturas.txt"
lnHandle=fcreate(lcArchivo)
if lnHandle > 0
	use subdiario Shared 
	set Order To clave
	COUNT TO axsonregistros

	use estadistica Shared in 0
	select estadistica
	set Order To clave

	select subdiario
	SCAN FOR YEAR(fecha) = 2007
		axvan=axvan+1
		wait windows alltrim(str(axvan,20))+" de "+alltrim(str(axsonregistros,20)) NOWAIT
		axtipo=subdiario.tipo
		axletra=subdiario.letra
		axsucursal=subdiario.sucursal
		axcomprobante=subdiario.comprobante
		axtotal=subdiario.bruto
		w_totalesta=0
		IF EMPTY(subdiario.estado)
			select estadistica
			scan for estadistica.tipo=axtipo AND ;
					 estadistica.letra=axletra AND ;
					 STR(estadistica.sucursal,2)=str(axsucursal,2) AND ;
					 str(estadistica.comprobante,7)=str(axcomprobante,7) 
					IF AXCOMPROBANTE=2518
						SET STEP ON 
					ENDIF		
	*					w_totalesta=w_totalesta+round(unidades*round(precio+(precio*por_iva/100),4),2)
						w_totalesta=w_totalesta+round(unidades*round(precio,4),2)

			
			endscan		
			if axtotal#w_totalesta AND w_totalesta # 0
				fputs(lnHandle,axtipo+" - "+axletra+" - "+alltrim(str(axsucursal,2))+" - "+alltrim(str(axcomprobante,7))+" - Diferencia: "+alltrim(str(axtotal-w_totalesta,20,2)))
			ENDIF
		endif	
		select subdiario
	endscan
	fclose(lnHandle)
	fflush(lnHandle)
else
	messagebox("No se puede crear el archivo log ",0+64,"Aviso del sistema")
endif

CLOSE DATABASES All

