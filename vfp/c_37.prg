Set EngineBehavior 70
Set Safety Off
Set Talk Off
Set Dele On
Set Collate To "MACHINE"
Close Tables All
Close Data All
Open Data datos Exclusive

Create View v_mp_pendiente_compras_saldo as;
	select;
	mp_pendiente_compras.NOTA_DE_PEDIDO,;
	mp_pendiente_compras.FECHA,;
	mp_pendiente_compras.CODIGO_MP,;
	Int(mp_pendiente_compras.SALDO) AS SALDO,;
	mp.descripcion_propia;
	from mp_pendiente_compras;
	inner join mp_por_proveedor on mp_pendiente_compras.codigo_mp =	mp_por_proveedor.materia_prima ;
	inner join materias_primas MP on mp_pendiente_compras.CODIGO_MP = MP.tipo_mp+MP.Rubro_mp+MP.codigo+MP.color;
	where mp_pendiente_compras.saldo > 0 and ;
	mp_por_proveedor.proveedor = ?lCProve ;
	ORDER BY CODIGO_MP,NOTA_DE_PEDIDO

CLOSE TABLES all
USE materias_primas EXCLUSIVE
	
Alter Table MATERIAS_PRIMAS ADD ALICUOTA_IVA N(5,2) NOT NULL
Alter Table MATERIAS_PRIMAS ALTER DESCRIPCION_PROPIA C(60) NOT NULL