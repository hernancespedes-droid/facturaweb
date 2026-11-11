_SCREEN.CLOSABLE		= .F.
_SCREEN.WINDOWSTATE	= 2

CLOSE DATABASES ALL
RELEASE ALL
CLEAR ALL
CLEAR DLLS

DEACTIVATE WINDOW ALL
SET CLOCK STATUS
SET PATH TO ("MENUS; PROGS; FORMS; LIBS; REPORT; MIGRADORES; GRAPHICS; GRAPHICS\LOGOS; TABLAS; DATOS; MOVIMIENTOS; CLAVES;include")

SET MARK TO '/'
SET MESS TO ' '
SET ODOMETER TO 32000 && Cantidad de Registro que procesa (Cuantos Trae de SQL por vez)
SET POINT TO '.'
SET PROCEDURE TO
SET REFRESH TO 0,0
SET REPROCESS TO 3

RELEASE oLogin, oEntorno, llHideTask, oFileVersion
ON ERROR ErrTrap( ERROR(), MESSAGE(), MESSAGE(1), PROGRAM(), LINENO())

PUBLIC _claveOk, _usuario, _supervisor, gaMenu
_claveOk		= .T.
_usuario		= "SUPERVISOR"
_empresa		= 0
_supervisor	= .t.
DO REGIREAD
SET PATH TO ("MENUS; PROGS; FORMS; LIBS; REPORT;	MIGRADORES;	GRAPHICS; GRAPHICS\LOGOS; TABLAS; DATOS; MOVIMIENTOS;include;")
IF TYPE('oLogin') <> "O"

	PUBLIC oLogin, oApp, oEntorno, oReporte

	oLogin	= NEWOBJECT('Login',"LIBS\LOGIN.VCX")
	oLogin.Seteos()	&& OJO que este Metodo Borra Variables

	ON ERROR ErrTrap( ERROR(), MESSAGE(), MESSAGE(1), PROGRAM(), LINENO())

      
	SET CLASSLIB TO "LIBS\OBJETOS_BASE.VCX"	ADDITIVE

	oEntorno	= NEWOBJECT('Entorno',		"LIBS\LOGIN.VCX")
	oEntorno.Iniciar()

	oApp		= NEWOBJECT("Aplicacion",	"LIBS\CONTROLES.VCX")
	oReporte	= NEWOBJECT("Reporte",		"LIBS\CONTROLES.VCX")

	oEntorno.SeteosForms()

ENDIF

Seteos()

* Agregado para este PRG
SET FIXED OFF
CLOSE DATABASES ALL

