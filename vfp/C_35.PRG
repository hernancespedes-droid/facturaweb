Set EngineBehavior 70
Set Safety Off
Set Talk Off
Set Dele On
Set Collate To "MACHINE" 
Close Tables All
Close Data All
Open Data datos Exclusive

ALTER TABLE PARAMETROS ADD ultima_orden_de_corte n(8) not null
Alter Table PARAMETROS ADD ultimo_det_corte n(8) not null
ALTER TABLE articulos ADD talle_en_letras c(03) NOT null
SELECT articulos
INDEX ON LEFT(codigo,3)+rubro+subrubro+modelo+manga+cierre+tela+color TAG patron UNIQUE additive

Create Table 'ORDEN_DE_CORTE_CAB.DBF' Name 'ORDEN_DE_CORTE_CAB' ( ;
	NUMERO           N(8)   Not Null,;
	FECHA_hora		     T  Not Null Default Datetime() ,;
	PROMEDIO         N(8,2) Not Null,;
	ESTADO			 N(1)   Not Null,;
	ACTUALIZACION    T      Not Null Default Datetime() ;
	)
	
Create Table 'ORDEN_DE_CORTE_DET.DBF' Name 'ORDEN_DE_CORTE_DET' ( ;
	NUMERO           N(8)   Not Null,;
	ARTICULO	     C(15)  Not Null,;
	PEDIDAS	         N(4)   Not Null,;
	PENDIENTES       N(4)   Not Null,;	
	ACTUALIZACION    T      Not Null Default Datetime() ;
	)	
	
Select ORDEN_DE_CORTE_CAB
Index On NUMERO TAG NUMERO

Select ORDEN_DE_CORTE_DET
Index On NUMERO TAG NUMERO
Index On Str(NUMERO,8)+ARTICULO TAG ORD_ART

Create View V_ORDEN_DE_CORTE_CAB AS ;
	SELECT;
	ORDEN_DE_CORTE_CAB.NUMERO,;
	FECHA_hora,;
	PROMEDIO ,;
	articulos.descripcion as descrip;
	from ORDEN_DE_CORTE_CAB ;
	inner join ORDEN_DE_CORTE_DET on ORDEN_DE_CORTE_DET.numero = ORDEN_DE_CORTE_CAB.numero;
	inner join articulos on ORDEN_DE_CORTE_DET.articulo = articulos.codigo;
	order by ORDEN_DE_CORTE_CAB.numero,ORDEN_DE_CORTE_DET.articulo;
	group by ORDEN_DE_CORTE_CAB.numero

Local lcPatron
lcPatron = Space(0)

Create View v_talles_por_Articulo as  ;
	select ;
	codigo as codigo ,;
	descripcion as descrip ,;
	Iif(Empty(talle_en_letras), Str(talle,3), talle_en_letras) as talles ,;	
	000000 as unidades_pedidas,;
	000000 as unidades_pendientes;
	from articulos;
	where (Left(CODIGO,3) + RUBRO + SUBRUBRO + MODELO + MANGA + CIERRE + TELA + COLOR) = ?lcPatron 	
	
	
Create Table 'CORTES_DET.DBF' Name 'CORTES_DET' ( ;
	NUMERO           N(8)   Not Null,;
	ORDEN_NRO        N(8)   Not Null,;
	FECHA			 D      NOT NULL ,;
	ARTICULO	     C(15)  Not Null,;
	UNIDADES	     N(4)   Not Null,;
	ACTUALIZACION    T      Not Null Default Datetime() ;
	)	
	
Select CORTES_DET
Index On NUMERO TAG NUMERO FOR !Deleted()
Index On Str(ORDEN_NRO,8) + ARTICULO TAG NUM_ART FOR !Deleted()

Local LnORDEN_NRO 
LnORDEN_NRO =Space(0)
Create View V_CORTES_DET AS;
	SELECT ;
	NUMERO,;
	ORDEN_NRO ,;
	FECHA	 ,;
	ARTICULO,;
	ARTICULOS.DESCRIPCION AS DESCRIP,;
	UNIDADES AS UNIDADES,;
	UNIDADES AS N_UNIDADES,;
	0000 as renglon ,;
	Iif(Empty(articulos.talle_en_letras), Str(articulos.talle,3), articulos.talle_en_letras) as talles ;
	FROM CORTES_DET;
	inner join articulos on articulos.codigo = CORTES_DET.articulo;
	ORDER BY ARTICULO,FECHA;
	WHERE UNIDADES > 0 AND ORDEN_NRO = ?LnORDEN_NRO 
	

