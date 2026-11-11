IF !USED("parametros")
	USE parametros
ENDIF
WAIT WINDOW "Adaptando subdiario en Negro" NOWAIT 
vsub_dia_neg = "USE " + ALLTRIM(parametros.path_bup) + "SUBDIARIO IN 0 ALIAS sub_dia_neg"
&vsub_dia_neg

SELECT sub_dia_neg
replace neto_grabado WITH neto ALL FOR neto_grabado=0 AND neto_exento=0 AND iva_1=0
USE 

WAIT WINDOW "Adaptando iva  en Negro" NOWAIT 
vsub_dia_neg = "USE " + ALLTRIM(parametros.path_bup) + "IVA IN 0 ALIAS iva_neg"
&vsub_dia_neg
SELECT iva_neg
DELETE ALL
USE

WAIT WINDOW "Borrando iva de exportacion" NOWAIT 

USE datos!iva
DELETE ALL FOR letra="E" OR comprobante>=100000
USE 

WAIT WINDOW "Copiando datos erroneos a Negro" NOWAIT 

SELECT * from datos!mvstk_cabecera WHERE numero>=100000 INTO CURSOR mvstcab
SCAN
	SCATTER MEMVAR 
	INSERT INTO (ALLTRIM(parametros.path_bup)+"mvstk_cabecera") from memvar
	INSERT INTO (ALLTRIM(parametros.path_bup)+"mvstk_items")    SELECT * from datos!mvstk_items A WHERE A.numero_mvstk = m.numero_mvstk
	DELETE FROM datos!mvstk_items     WHERE numero_mvstk = m.numero_mvstk
	DELETE FROM datos!mvstk_cabecera  WHERE numero_mvstk = m.numero_mvstk
ENDSCAN

INSERT INTO (ALLTRIM(parametros.path_bup)+"subdiario")   SELECT * from datos!subdiario   A WHERE A.Comprobante>=100000
DELETE from datos!subdiario    WHERE Comprobante>=100000
INSERT INTO (ALLTRIM(parametros.path_bup)+"Estadistica") SELECT * from datos!Estadistica A WHERE A.Comprobante>=100000
DELETE from datos!Estadistica  WHERE Comprobante>=100000
INSERT INTO (ALLTRIM(parametros.path_bup)+"caja")        SELECT * from datos!Caja		 A WHERE A.Comprobante>=100000
DELETE from datos!Caja		   WHERE Comprobante>=100000


CLOSE DATABASES all


WAIT WINDOW "Proceso Finalizado Exitosamente"+CHR(13)+"Debe salir ahora del Sistema <Enter>"

