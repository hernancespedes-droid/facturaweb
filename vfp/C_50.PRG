Set Safety Off
Set ENGINEBEHAVIOR 70
Set Dele On
Set Collate To "MACHINE"
Close Tables All
Close Data All
Open Database DATOS Exclusive

Create SQL View V_liquidacion_taller as ; 
Select ;
	LC.numero ,;
	LC.Fecha  ,;
	talleres.descripcion ,;
	Round(sum(LD.Unidades*LD.costo),2) as importe ;
	from liquidacion_taller_cab LC ;
	inner join liquidacion_taller_det LD on LC.numero = LD.numero ;
	inner join talleres on LC.taller = talleres.codigo ;
	group by LC.numero ;
	order by LC.numero 