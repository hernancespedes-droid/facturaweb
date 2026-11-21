-- Listados de estadística, trazabilidad de stock y costos (0.32.0).
-- Los costos y márgenes son sensibles: solo los ve quien tiene listados.costos (Administrador y Master por omisión; al Operador se le tilda en Roles).
INSERT IGNORE INTO permisos (codigo, modulo, descripcion) VALUES
  ('listados.costos', 'listados', 'Listados: ver costos, márgenes, rentabilidad y stock valorizado');
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'listados.costos' WHERE r.es_admin = 1 OR r.es_master = 1;
