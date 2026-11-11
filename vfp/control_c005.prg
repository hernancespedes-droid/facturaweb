*------------- ARREGLO 005
LOCAL ln005
ln005 = 0
TRY
	USE parametros SHARED
	ln005 = parametros.control_005
	replace parametros.control_005 WITH 1
	USE
CATCH
	IF USED("PARAMETROS")
		USE IN PARAMETROS
	ENDIF 
	ln005 = 1
ENDTRY

IF ln005 = 0
	DO FORM movimientos_de_stock_c005 NAME V_BROWS NOSHOW
ENDIF

TRY
	USE parametros SHARED
	replace parametros.control_005 WITH 0
	USE
CATCH
	IF USED("PARAMETROS")
		USE IN PARAMETROS
	ENDIF
ENDTRY

RELEASE V_BROWS
RELEASE ln005
*--------------------------------
