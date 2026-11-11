Set Deleted On
CLOSE TABLES all

LOCAL LCaRT, i, nro
i=0

SELECT 0
CREATE CURSOR diferentes (id_stock N(10), art_est C(15), obs C(40))

SELECT 0
USE articulos
SET ORDER TO codigo

SELECT 0
USE mvstk_cabecera

SELECT 0
USE Mvstk_items 

SELECT 0
USE estadistica

SCAN && FOR Estadistica.comprobante = 24539

	lctipo = Estadistica.tipo 
	lcLetra = Estadistica.letra
	lncomprobante = Estadistica.comprobante

	SELECT numero_mvstk ;
	FROM mvstk_cabecera;
	where ;
	lctipo = Mvstk_cabecera.tipo  and ;
	lncomprobante = Mvstk_cabecera.numero ;
	into cursor detalle

*	lcletra= Mvstk_cabecera.letra and ;
	
	IF _tally > 0
	
		nro=detalle.numero_mvstk 
		
		LCaRT = Estadistica.articulo 
		SELECT * from Mvstk_items WHERE numero_mvstk = nro AND articulo =LCaRT ;
		into cursor coinciden
		
		IF _tally = 0
			INSERT INTO diferentes (id_stock, art_est, obs) ;
			VALUES (nro, Estadistica.articulo, 	DTOS(Estadistica.fecha)+"-"+Estadistica.tipo +Estadistica.letra + STR(Estadistica.comprobante))
			i=i+1
		ENDIF

	ENDIF	
ENDSCAN

WAIT WINDOW STR(i)
SELECT mvstk_items
SET ORDER TO numero

SELECT diferentes
SET RELATION TO Diferentes.id_stock INTO Mvstk_items ADDITIVE

SELECT diferentes
BROWSE nowait

SELECT mvstk_items
BROWSE nowait

SELECT articulos
BROWSE nowait

