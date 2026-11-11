Select ;
	codigo_mp         ,;
	MP.DESCRIPCION_PROPIA ,;
	SUM(Iif(IIF(!Empty(signo_destino),;
		signo_destino,signo_origen)='+',;
		cantidad_unidades,-cantidad_unidades)) ;
		As unidades ;
	FROM datos!mvstkmp_cabecera MC ;
	INNER Join datos!mvstkmp_items MI ON MC.numero_mvstk_mp = MI.numero_mvstk_mp;
	INNER join MATERIAS_PRIMAS MP on mp.tipo_mp+mp.rubro_mp+mp.codigo+mp.color = MI.codigo_mp ;
	Into Cursor CUR_detalle ;
	GROUP BY codigo_mp

SELECT MATERIAS_PRIMAS
REPLACE ALL STOCK_FISICO WITH 0

SELECT CUR_detalle 
I=0
GO TOP
SCAN
	UPDATE MATERIAS_PRIMAS SET;
		stock_fisico = CUR_DETALLE.UNIDADES ;
		WHERE ;
		ALLTRIM(MATERIAS_PRIMAS.TIPO_MP+;
			MATERIAS_PRIMAS.RUBRO_MP+;
			MATERIAS_PRIMAS.CODIGO +;
			MATERIAS_PRIMAS.COLOR) = ALLTRIM(CUR_DETALLE.CODIGO_MP)
		IF _TALLY > 0
			WAIT WINDOW 'ACTUALIZANDO STOCK '+LEFT(CUR_DETALLE.DESCRIPCION_PROPIA,40) NOWAIT
			I=I+1
		ENDIF

ENDSCAN 

WAIT WINDOW 'SE ACTUALIZARON ' + ALLTRIM(STR(I)) + ' STOCKS DE MATERIAS PRIMAS'
