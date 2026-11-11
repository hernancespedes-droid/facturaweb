Do SETEOS
Set Dele On
Set Collate To "MACHINE"
Close Tables All
Close Data All
Open Database DATOS Exclusive 

Create Table Orden_de_Corte_Promedios (;
	numero n(8) not null ,;
	codigo_mp c(15) not null ,;
	promedio_real n(8,2) not null)
Select Orden_de_Corte_Promedios 
Index On numero tag numero
Index On Str(numero,8)+codigo_mp tag codigo_mp

Local lcArticulo
lcArticulo = Space(0)
Create SQL View V_PROMEDIOS_TELAS_POR_ARTICULO AS;
	Select ;
	materias_primas.descripcion_propia ,;
	materias_primas.tipo_mp+materias_primas.rubro_mp+materias_primas.CODIGO+materias_primas.Color AS CODIGO_MP ,;
	formulas_articulo.CANTIDAD as promedio_estimado ,;
	0000000.0000 as PROMEDIO_REAL ;
	from articulos ;
	inner Join formulas_articulo On articulos.CODIGO = formulas_articulo.articulo ;
	inner Join materias_primas On formulas_articulo.tipo_mp+formulas_articulo.rubro_mp+formulas_articulo.CODIGO+formulas_articulo.Color = ;
	materias_primas.tipo_mp+materias_primas.rubro_mp+materias_primas.CODIGO+materias_primas.Color ;
	and materias_primas.en_rollo = .T.    ;
	where articulos.codigo = ?lcArticulo ;
	order by materias_primas.descripcion_propia