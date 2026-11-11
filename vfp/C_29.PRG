Set Safety Off
Set Talk Off
Set Dele On
Close Tables All
Close Data All
Open Data datos Exclusive

Alter Table ordcomp_detalle_mp add nota_de_pedido n(8) not null

Create View v_ocbrowse_MP;
	AS Select Distinct ;
	ordcomp_encabezado_MP.fecha,;
	ordcomp_encabezado_MP.numero,;
	ordcomp_encabezado_MP.proveedor,;
	Prove.pro_razon_social As razonsoc;
	FROM ordcomp_encabezado_MP ;
	INNER Join ordcomp_detalle_MP ;
	ON  ordcomp_encabezado_MP.numero = ordcomp_detalle_MP.num;
	AND  ordcomp_detalle_MP.saldo > ( 0 ) ;
	INNER Join S:\proveedores\Data\maestros\p_proveedores Prove ;
	ON  Prove.pro_icodigo = ordcomp_encabezado_MP.proveedor;
	ORDER By Prove.pro_razon_social, ordcomp_encabezado_MP.numero

*- Creo la vista de mp pendientes de compras con saldos
If Indbc('v_mp_pendiente_compras_saldo',"VIEW")
	Drop View v_mp_pendiente_compras_saldo
Endif
Local lcprove
lcprove=0
Create View v_mp_pendiente_compras_saldo as;
	select;
	mp_pendiente_compras.NOTA_DE_PEDIDO,;
	mp_pendiente_compras.FECHA,;
	mp_pendiente_compras.CODIGO_MP,;
	Int(mp_pendiente_compras.SALDO) AS SALDO,;
	materias_primas.descripcion_propia;
	from mp_pendiente_compras;
	inner join materias_primas on mp_pendiente_compras.codigo_mp = materias_primas.tipo_mp+materias_primas.rubro_mp+materias_primas.codigo+materias_primas.color;
	where saldo > 0 and;
	InList(?lcprove,proveedor_habitual,proveedor_alternativo) ;
	ORDER BY CODIGO_MP,NOTA_DE_PEDIDO


CREATE VIEW v_orden_detalle_MP AS;
	SELECT MATERIA_PRIMA AS articulo,;
	       SPACE(40) AS descrip,;
		   SPACE(3) AS moneda,;
		   000000.0000 AS costo,;
  	       00.00 AS desc1,;
  	       00.00 AS desc2,;
  	       00.00 AS desc3,;
  	       precio,;
  	       cantidad,;
  	       00.00 AS iva,;
  	       SPACE(15) AS nro_int,;
  	       MATERIA_PRIMA AS origen,;
  	       SPACE(1) AS marca,;
  	       00000000.00 AS Cant_ant;
	FROM datos!ordcomp_detalle_MP;
	WHERE .F.	