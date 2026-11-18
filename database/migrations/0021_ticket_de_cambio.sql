-- Ticket de cambio y cambio de mercadería.
--   parametros.ticket_cambio: «habilita modo ticket de cambio» (Tablas → Parámetros).
--   Cambio = comprobante interno CM (letra X, numeración propia sucursales.ultimo_cambio): lo que se devuelve entra al stock, lo que se lleva sale,
--   y solo se cobra la diferencia (caja o cuenta corriente, ctact.tico 5). subdiario.cambio_de lo liga con el presupuesto/factura original y
--   estadistica.linea_origen con el renglón devuelto (para no devolver dos veces lo mismo).
--   Permiso cambios.hacer (leer ticket y hacer cambios).

ALTER TABLE parametros ADD COLUMN ticket_cambio TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Habilita el modo ticket de cambio';
ALTER TABLE sucursales ADD COLUMN ultimo_cambio INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE subdiario
  ADD COLUMN cambio_de INT UNSIGNED NULL COMMENT 'En un cambio (CM): fe_clave del comprobante original',
  ADD KEY ix_subdiario_cambio_de (cambio_de);
ALTER TABLE estadistica
  ADD COLUMN linea_origen INT UNSIGNED NULL COMMENT 'En un cambio: renglón (estadistica.fe_clave) del original que se devuelve',
  ADD KEY ix_estadistica_linea_origen (linea_origen);

INSERT IGNORE INTO permisos (codigo, modulo, descripcion) VALUES
  ('cambios.hacer', 'cambios', 'Leer el ticket de cambio y hacer cambios de mercadería (presupuestos y facturas)');
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'cambios.hacer' WHERE r.es_admin = 1 OR r.es_master = 1 OR r.codigo = 'operador';
