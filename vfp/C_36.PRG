Set EngineBehavior 70
Set Safety Off
Set Talk Off
Set Dele On
Set Collate To "MACHINE"
Close Tables All
Close Data All
Open Data datos Exclusive
SET STEP ON 
Local acampos(1)
Use articulos_por_proveedor
Afields(acampos, 'articulos_por_proveedor')
acampos(1,1) = 'MATERIA_PRIMA'
Create Table mp_por_proveedor.Dbf Name mp_por_proveedor ;
	from Array acampos

Alter Table mp_por_proveedor ADD COLOR_DEL_PROVE C(10) NOT NULL
Alter Table mp_por_proveedor ADD descripcion_proveedor C(40) NOT NULL


Select mp_por_proveedor
Index On materia_prima  Tag mp
Index On materia_prima + Str(proveedor,5) Tag mp_pro

Create View v_mp_por_proveedor As;
	SELECT mp_por_proveedor.proveedor AS proveedor, Space(40) As pro_nom,;      
	mp_por_proveedor.num_interno As nro_interno,;
	mp_por_proveedor.costo, mp_por_proveedor.desc1,;
	mp_por_proveedor.desc2, mp_por_proveedor.desc3,;
	mp_por_proveedor.unidad_venta As uni_ven, Space(40) As uv_nom,;
	mp_por_proveedor.fecha1 As fec_ult_com1,;
	mp_por_proveedor.fecha2 As fec_ult_com2,;
	mp_por_proveedor.fecha3 As fec_ult_com3,;
	mp_por_proveedor.cantidad1 As can_ult_com1,;
	mp_por_proveedor.cantidad2 As can_ult_com2,;
	mp_por_proveedor.cantidad3 As can_ult_com3,;
	SPACE(20) As clave,;
	mp_por_proveedor.COLOR_DEL_PROVE ,;
	mp_por_proveedor.descripcion_proveedor ;	
	FROM ;
	mp_por_proveedor;
	WHERE  ( .F. )


Local lnProvee
lCProve = 0
Create View v_materias_primas_prove As ;
	select descripcion_propia, codigo,tipo_mp,rubro_mp,Color	;
	from materias_primas ;
	inner join mp_por_proveedor on materias_primas.tipo_mp+materias_primas.rubro_mp+materias_primas.codigo+materias_primas.color =;
		Alltrim(mp_por_proveedor.materia_prima);
		where mp_por_proveedor.proveedor = ?lCProve ;
	order By tipo_mp,rubro_mp,codigo,Color

Create View v_mp_pendiente_compras_saldo as;
	select;
	mp_pendiente_compras.NOTA_DE_PEDIDO,;
	mp_pendiente_compras.FECHA,;
	mp_pendiente_compras.CODIGO_MP,;
	Int(mp_pendiente_compras.SALDO) AS SALDO,;
	materias_primas.descripcion_propia;
	from mp_pendiente_compras;
	inner join mp_por_proveedor on mp_pendiente_compras.codigo_mp =	mp_por_proveedor.materia_prima ;
	where saldo > 0 and ;
	mp_por_proveedor.proveedor = ?lCProve ;
	ORDER BY CODIGO_MP,NOTA_DE_PEDIDO

Alter Table MATERIAS_PRIMAS ADD ESPESOR N(5,2) NOT NULL


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
	where (Left(CODIGO,3) + RUBRO + SUBRUBRO + MODELO + MANGA + CIERRE + TELA + COLOR) = ?lcPatron 	;
	GROUP BY talle
	




