Set Safety Off
Set Talk Off
Set Dele On
Close Tables All
Close Data All
Open Data datos Exclusive

*- se creo originalmente como Numerico de 10 al dope con 1 le sobra
Alter Table sucursales Alter copias_notped  N(1) Not Null

*- originalmente era un Character de 60
Alter Table notaped_cab Alter observaciones M Not Null

Alter Table articulos Add modelo c(4) Not Null
Alter Table articulos Add manga  c(2) Not Null
Alter Table articulos Add cierre c(2) Not Null
Alter Table articulos Add tela   c(3) Not Null
Alter Table articulos Add Color  c(3) Not Null
Alter Table articulos Add talle  c(2) Not Null

Alter Table Rubros_materias_primas Add numerador  N(4) Not Null


** vista de Materias primas
Create View v_materias_primas As ;
	select descripcion_propia, codigo,tipo_mp,rubro_mp,Color	;
	from materias_primas ;
	order By tipo_mp,rubro_mp,codigo,Color

If Indbc('V_Rubros_materias_primas',"VIEW")
	Drop View V_Rubros_materias_primas
Endif
Create View V_Rubros_materias_primas As ;
	select * From datos!Rubros_materias_primas ;
	order By Rubros_materias_primas.descripcion

Create View v_formulas_base As ;
	select formulas_base.modelo,;
	modelos.descripcion As modelosdescrip,;
	formulas_base.tipo_mp,;
	tipos_de_materia_prima.descripcion As tipos_de_materia_prima,;
	formulas_base.rubro_mp,;
	Rubros_materias_primas.descripcion As Rubros_materias_primas,;
	formulas_base.Color,;
	colores.descripcion As colordescrip,;
	formulas_base.codigo,;
	formulas_base.cantidad,;
	materias_primas.descripcion_propia ;
	from formulas_base ;
	inner Join modelos On modelos.codigo = formulas_base.modelo ;
	inner Join colores On colores.codigo = formulas_base.Color ;
	inner Join tipos_de_materia_prima On tipos_de_materia_prima.codigo = formulas_base.tipo_mp ;
	inner Join Rubros_materias_primas On Rubros_materias_primas.codigo = formulas_base.rubro_mp ;
	inner Join materias_primas On materias_primas.tipo_mp + materias_primas.rubro_mp + Str(materias_primas.codigo,4) + materias_primas.Color =;
	formulas_base.tipo_mp   + formulas_base.rubro_mp   + Str(formulas_base.codigo,4)   + formulas_base.Color ;
	order By modelosdescrip,descripcion_propia,modelo,formulas_base.tipo_mp,;
	formulas_base.rubro_mp,formulas_base.codigo,formulas_base.Color


Create View v_notaped_Pendientes As ;
	Select notaped_cab.tipo, notaped_cab.letra, notaped_cab.sucursal,;
	notaped_cab.numero, notaped_cab.fecha,;
	SUM(Notaped_det.saldo) As sum_saldo, ;
	SUM(notaped_det.precio * notaped_det.saldo) As Tot_Saldo,;
	notaped_cab.cliente,;
	Clientes.razon_social, notaped_cab.prefijo, notaped_cab.valides,;
	notaped_cab.neto;
	FROM ;
	datos!notaped_cab ;
	inner Join datos!Notaped_det ;
	ON  Str(notaped_cab.sucursal,2)+Str(notaped_cab.numero,8) = Str(Notaped_det.sucursal,2)+Str(Notaped_det.numero,8) ;
	inner Join datos!Clientes ;
	ON  notaped_cab.cliente = Clientes.codigo;
	GROUP By notaped_cab.sucursal, notaped_cab.numero;
	HAVING  ( sum_saldo ) <> ( 0 );
	ORDER By Clientes.razon_social
