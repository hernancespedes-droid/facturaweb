OPEN DATABASE datos

ALTER TABLE ordcomp_detalle ADD COLUMN costo N(11,4)

CREATE VIEW v_mvstk_items AS;
  	       SELECT Mvstk_items.numero_mvstk, SPACE(15) AS articulo,;
 	              SPACE(40) AS descripcion, Mvstk_items.cantidad_unidades,;
	              Mvstk_items.cantidad_kilos, 00000000.00 AS costo;
             FROM datos!Mvstk_items;
	         WHERE .F.
