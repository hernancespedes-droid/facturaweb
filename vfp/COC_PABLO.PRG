OPEN DATA DATOS EXCL
CREATE VIEW v_productos AS;
SELECT Articulos.descripcion, Articulos.codigo,;
  Articulos.codigo_asociado, Articulos.rubro, Articulos.subrubro,;
  Articulos.unidad_de_medida, Articulos.unidades_por_pack,;
  Articulos.cantidad_de_unidades,;
  Articulos.costo_ultima_entrada_unidad,;
  Articulos.costo_reposicion_neto, Articulos.precio_de_venta_1,;
  Articulos.precio_de_venta_2, Articulos.precio_de_venta_3,;
  Articulos.fecha_ultimo_cambio_de_precio, Articulos.margen,;
  Articulos.base_costo, Articulos.precio_unitario;
 FROM datos!articulos;
 WHERE Articulos.rubro = "ROT";
 ORDER BY Articulos.descripcion, Articulos.codigo
 
 DBSETPROP('V_PRODUCTOS', 'View', 'SendUpdates', .T.)
VALIDATE DATA
CLOSE DATA