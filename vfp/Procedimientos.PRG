PROCEDURE borro_subdiario
PARAMETER cz

w_area = SELECT()
IF !USED("SUBDIARIO")
	SELECT 0
	USE SUBDIARIO ORDER CIERRE_Z
ENDIF
IF SEEK(cz,"SUBDIARIO","CIERRE_Z")
	SELECT SUBDIARIO
	Delete
ENDIF
SELECT(w_area)
*----------------------------------------------------------
