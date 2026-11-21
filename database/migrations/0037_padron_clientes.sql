-- Alta de clientes desde el CUIT (0.37.0).
--   padron_consultas: la última respuesta del padrón de ARCA (constancia de inscripción, ws_sr_constancia_inscripcion) por CUIT, ya normalizada (JSON).
--     Sirve de caché (no se vuelve a preguntar a ARCA por el mismo CUIT durante unos días) y para mostrar en la ficha del cliente
--     los datos impositivos (condición de IVA, monotributo, actividades) con la fecha en que se consultaron.
--     entorno: S simulado, H homologación, P producción (lo simulado nunca se usa en producción).
--   Permiso clientes.alta: dar de alta un cliente nuevo desde las ventas (factura, presupuesto, kiosco) sin poder modificar los existentes.
CREATE TABLE IF NOT EXISTS padron_consultas (
  cuit            BIGINT UNSIGNED NOT NULL,
  entorno         CHAR(1) NOT NULL,
  datos           MEDIUMTEXT NOT NULL,
  consultado_en   DATETIME NOT NULL,
  usuario_id      INT UNSIGNED NULL,
  PRIMARY KEY (cuit, entorno),
  KEY ix_padron_consultado (consultado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permisos (codigo, modulo, descripcion) VALUES
  ('clientes.alta', 'maestros', 'Dar de alta clientes nuevos desde las ventas (factura, presupuesto, kiosco), con los datos del padrón de ARCA');
-- Lo tienen los que ya pueden editar clientes, emitir comprobantes o usar el kiosco (además de Administrador y Master).
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'clientes.alta' WHERE r.es_admin = 1 OR r.es_master = 1;
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT DISTINCT rp.rol_id, p.id FROM rol_permisos rp JOIN permisos q ON q.id = rp.permiso_id JOIN permisos p ON p.codigo = 'clientes.alta'
   WHERE q.codigo IN ('clientes.editar', 'comprobantes.emitir', 'kiosco.usar');
