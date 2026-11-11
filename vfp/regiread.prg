*|*Ahora lo leo de un .ini porque quieren ejecutar mas de un exe en la misma PC (14-01-04)
PUBLIC _gruta, _gterminal, _gsucursal, _gcanal, nro_terminal

Local lcIni,axHdl
lcIni=JustStem(SYS(16,0))	&&"VENTAS1.EXE"
IF _VFP.StartMode = 0		&&Ejecuto desde el entorno
	lcIni='visom'
EndIf 	

axHdl = Fopen(lcIni+'.ini')
Do While !Feof(axHdl)
	lcReg=Fgets(axHdl)
	If Left(lcReg,12)='Num_Terminal'
		_gterminal = Val(Substr(lcReg,15,2))
	EndIf 
	If Left(lcReg,8)='Sucursal'
		_gsucursal = Val(Substr(lcReg,11,1))
	EndIf 
	If Left(lcReg,13)='Tipo_Sucursal'
		_gcanal = Substr(lcReg,16,1)
	EndIf 
	If Left(lcReg,4)='Ruta'
		_gruta = ALLTRIM(Substr(lcReg,7,100))
		_gruta = SUBSTR(_gruta,1,LEN(_gruta) - 1)
	EndIf 
EndDo   
Fclose(axHdl)

nro_terminal = _gterminal
