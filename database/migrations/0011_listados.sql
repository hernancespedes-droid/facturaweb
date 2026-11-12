-- Listados (etapa 5): permiso para ver el subdiario de IVA ventas. Lo tienen Master y Administrador; los demás roles se lo dan en Usuarios → Roles.
INSERT IGNORE INTO permisos (codigo, modulo, descripcion) VALUES
  ('listados.ver', 'listados', 'Ver y bajar a Excel los listados (subdiario de IVA ventas)');
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'listados.ver' WHERE r.es_admin = 1 OR r.es_master = 1;
