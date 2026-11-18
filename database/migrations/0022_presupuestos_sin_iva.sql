-- «Presupuestos sin IVA» (Sistema → Configuración): con la marca activa el presupuesto no calcula IVA sobre el valor de los productos (el precio es el final).
-- Cada presupuesto guarda cómo se hizo (subdiario.sin_iva): cambiar la marca después no altera los ya emitidos. La contrapartida (NX) hereda lo del presupuesto.
ALTER TABLE subdiario ADD COLUMN sin_iva TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = presupuesto (o su contrapartida) hecho sin calcular IVA';
INSERT IGNORE INTO configuracion (clave, valor, tipo, descripcion) VALUES
  ('presupuestos.sin_iva', '0', 'si_no',
   'Presupuestos sin IVA: si está tildado, el presupuesto no calcula IVA sobre el valor de los productos (el precio es el final). Los ya emitidos no cambian');
