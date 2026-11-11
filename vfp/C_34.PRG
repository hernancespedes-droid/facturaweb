Set EngineBehavior 70
Set Safety Off
Set Talk Off
Set Dele On
Set Collate To "MACHINE" 
Close Tables All
Close Data All
Open Data datos Exclusive

Local lcPatron
lcPatron = Space(0)

Create View v_talles_por_Articulo as  ;
	select ;
	codigo as codigo ,;
	descripcion as descrip ,;
	Iif(Empty(talle_en_letras), Str(talle,3), talle_en_letras) as talles ,;	
	000000 as unidades_pedidas;
	from articulos;
	where (Left(CODIGO,3) + RUBRO + SUBRUBRO + MODELO + MANGA + CIERRE + TELA + COLOR) = ?lcPatron 
*	

Alter Table articulos add Talle_en_letras c(3) not null
Index UNIQUE On Left(CODIGO,3)+RUBRO+SUBRUBRO+MODELO+MANGA+CIERRE+TELA+COLOR FOR !Deleted() TAG PATRON 


Alter Table PARAMETROS ADD ULTIMA_ORDEN_DE_CORTE   N(8)
