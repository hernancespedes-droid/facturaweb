
_SCREEN.AddProperty("aTIVA(10)")
_SCREEN.aTIVA(1) = "Inscripto"
_SCREEN.aTIVA(2) = "No Inscripto"
_SCREEN.aTIVA(3) = "Consumidor Final"
_SCREEN.aTIVA(4) = "Exento"
_SCREEN.aTIVA(5) = "Exento"
_SCREEN.aTIVA(6) = "Liberado"
_SCREEN.aTIVA(7) = "Monotributo"
_SCREEN.aTIVA(8) = "Exento Especial"
_SCREEN.aTIVA(9) = "Exento Especial"

IF TYPE("_usuario")!="C"
	PUBLIC _usuario
	_usuario = "SUPERVISOR"
ENDIF
IF TYPE("_gCanal")!="C"
	PUBLIC _gCanal
	_gCanal = "m"
ENDIF
IF TYPE("_gsucursal")!="C"
	PUBLIC _gsucursal
	_gsucursal = 1
ENDIF
IF TYPE("_gTerminal")!="N"
	PUBLIC _gTerminal
	_gTerminal = 1
ENDIF
	
SET TALK OFF
IF ATC("XFRX",SET("PATH"))=0
	xPth = "SET PATH TO "+CURDIR()+"XFRX2;"+SET("Path")
	&xPth
ENDIF 	
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
Set Exact            OFF 
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

Do regiread

Do Case
Case Sysmetric(1) = 640 .And. Sysmetric(2) = 480
	Define Window frm_preview From 0,0 To 37,79 ;
		Title ' ' In Screen	Zoom Close System Color Scheme 10
Case Sysmetric(1) = 1024 .And. Sysmetric(2) = 768
	Define Window frm_preview From 0,0 To 61,128 ;
		Title ' ' In Screen	Zoom Close System Color Scheme 10
Otherwise    && 800 X 600
	Define Window frm_preview From 00,00 To 45,100 ;
		Title ' ' In Screen	Zoom Close System Color Scheme 10
Endcase

Define Windows frm_browse From 0,0 To 1,1;
	ZOOM Mdi Close System Color Scheme 10
