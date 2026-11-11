Set Safety Off
Set ENGINEBEHAVIOR 70
Set Dele On
Set Collate To "MACHINE"
Close Tables All
Close Data All
Open Database DATOS Exclusive

lcArticulo = Space(0)
Create Sql View v_orden_de_envio_taller_por_articulo As;
	SELECT Envio_taller_cab.numero, Envio_taller_cab.fecha,;
	Envio_taller_cab.taller, Talleres.descripcion,;
	Envio_taller_det.articulo, Sum(Envio_taller_det.saldo) As saldos;
	FROM Envio_taller_cab ;
	INNER Join Envio_taller_det ON  Envio_taller_cab.numero = Envio_taller_det.numero ;
	INNER Join Talleres ON  Envio_taller_cab.taller = Talleres.codigo;
	WHERE  Envio_taller_det.articulo = ( ?lcArticulo );
	AND  Envio_taller_cab.estado <> ( "A" );
	GROUP By Envio_taller_det.numero;
	HAVING  Sum(Envio_taller_det.saldo) > ( 0 );
	ORDER By Envio_taller_det.numero
