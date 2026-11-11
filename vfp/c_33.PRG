Set EngineBehavior 70
Set Safety Off
Set Talk Off
Set Dele On
Set Collate To "MACHINE" 
Close Tables All
Close Data All
Open Data datos Exclusive
SET STEP ON 
Alter Table materias_primas add largo N(6,2)
Alter Table materias_primas add tamanio C(10)
Alter Table materias_primas add color_proveedor C(10)
Alter Table materias_primas add tipo_de_cierre C(3)
Alter Table materias_primas add proveedor_ultima_compra N(5)


Create Table 'tipo_de_cierre.DBF' Name 'tipo_de_cierre' ( ;
	CODIGO           C(3)  Not Null,;
	DESCRIPCION		 C(20) Not Null,;
	ACTUALIZACION    T Not Null Default Datetime() ;
	)

Index On CODIGO For .Not.Deleted() Tag CODIGO
Index On DESCRIPCION For .Not.Deleted() Tag DESCRIP
Create View V_tipo_de_cierre AS;
	SELECT ;
	CODIGO   		,;
	DESCRIPCION      ;
	FROM tipo_de_cierre;
	ORDER BY DESCRIPCION

Create Table 'Anchos.DBF' Name 'Anchos' (;
	codigo           C(3)  Not Null,;
	ancho			N(5,2) not null )
Index On CODIGO For .Not.Deleted() Tag CODIGO
Index On ancho 	For .Not.Deleted() Tag ancho

Create View v_ancho as;
	Select ; 
	codigo ,;
	ancho  ;
	from Anchos;
	order by ancho
	


