open data datos excl
create view v_costos as ;
SELECT Articulos.codigo, Articulos.descripcion, Articulos.proveedor,;
  Articulos.costo_ultima_entrada_unidad, Articulos.costo_anterior,;
  Articulos.fecha_ultimo_cambio_de_precio, Articulos.cos_ant,;
  Articulos.precio_de_venta_1, Articulos.precio_de_venta_2,;
  Articulos.precio_de_venta_3, Articulos.precio_de_oferta;
 FROM datos!articulos;
 WHERE Articulos.proveedor = ?_PROVEEDOR;
 ORDER BY Articulos.codigo, Articulos.proveedor
validate data 
close all
