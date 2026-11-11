&& Maximizo la ventana de VFP

_SCREEN.WINDOWSTATE=2
_SCREEN.VISIBLE=.F.

DO regiread

SET DEFAULT TO (_gruta)

SET PROCEDURE TO classlib.prg, errores.prg, seteos.prg
=seteos()
*-----------------------------------
* Borro los *.txt de FC-ND-NC Y TK
=ADIR(tabla, '*.TXT')
IF TYPE('tabla') # 'U'  && Indefinida
   FOR i = 1 TO ALEN(TABLA,1)
       IF tabla(i,3) < DATE()
          DELETE FILE eval('tabla(i,1)')
       ENDIF
   ENDFOR
ENDIF
*-----------------------------------

PUBLIC glError   && .t. o .f. dependiendo si hubo errores() 
glError=.F.
SET FUNCTION 4 TO *
SET FUNCTION 5 TO *
SET FUNCTION 8 TO *

ON ERROR DO errores WITH ERROR(), MESSAGE(), MESSAGE(1), LINENO(), PROGRAM()

SET HELP OFF
SET SYSMENU TO

SET SYSMENU TO DEFAULT
PUBLIC _claveOk,_usuario
_claveOk=.F.
_usuario=SPACE(20)

Public gaMenu
Dimension gaMenu(1,2)
Store "" To gaMenu

SELECT 0					&&Esta aca p/que lo use el menu
USE CLAVES!CLA_ACCESOS

DO FORM frm_principal_prod WITH 'MAYORISTA' LINKED
If _VFP.StartMode = 4	And !Between(_gterminal,20,30)	&&Ejecuta desde el EXE
	Declare Integer CreateEvent In WIN32API;
	    Integer lpEventAttributes,;
    	Short bManualReset,;
	    Short BInitialState,;
    	String @lpName
    	
	Declare Integer GetLastError In WIN32API
	Declare CloseHandle In WIN32API Integer hObject

	nEh = CreateEvent(0,0,1,Program(0)+'.EVENT')

	If GetLastError() = 183 Or nEh= 0
    	=CloseHandle(nEh)
	    =MessageBox("La aplicación ya está ejecutando."+Chr(13)+;
        	"Se cerrará esta instancia.",16,"ERROR de Ejecución")

	    Clear Events
    	On ShutDown
	    Release All Extended
    	Quit
	 EndIf
EndIf

DO FORM frm_login

IF _claveOk=.T.
&&  Activo el menu p/que funcionen los set skip
    DO array_menu  && Carga gaMenu con todas las opciones del menu
    KEYBOARD '{ALT+C}'		&& Todo el Sistema
	READ EVENTS
ENDIF	

goToolBar.Mostrar()
CLEAR ALL
RELEASE ALL
CLOSE ALL
ON ERROR
QUIT

_SCREEN.MINBUTTON=.T.
_SCREEN.MAXBUTTON=.T.
_SCREEN.CLOSABLE=.T.
SET HELP On
SET SYSMENU On
SET SYSMENU TO DEFA
SET EXCLUSIVE ON
RETURN
