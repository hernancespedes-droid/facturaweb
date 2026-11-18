-- Modo Kiosco: ventas de mostrador con los productos en tarjetas y un carrito siempre visible. Al finalizar genera un presupuesto con el mismo pago que el presupuesto.
--   parametros.kiosco: «habilitar modo Kiosco» (Tablas → Parámetros).
--   Permiso kiosco.usar: se da o se quita por rol (Roles → Facturación → Kiosco).
ALTER TABLE parametros ADD COLUMN kiosco TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Habilita el modo Kiosco (carrito de mostrador)';
INSERT IGNORE INTO permisos (codigo, modulo, descripcion) VALUES
  ('kiosco.usar', 'kiosco', 'Usar el modo Kiosco: carrito de mostrador que genera un presupuesto');
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'kiosco.usar' WHERE r.es_admin = 1 OR r.es_master = 1 OR r.codigo = 'operador';
