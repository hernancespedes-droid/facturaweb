-- Agente de sincronización (Windows) → web: claves de API por instalación. Solo se guarda el hash; la clave se muestra una vez al crearla.
CREATE TABLE IF NOT EXISTS api_claves (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre       VARCHAR(60) NOT NULL,
  clave_hash   CHAR(64) NOT NULL,
  prefijo      VARCHAR(12) NOT NULL COMMENT 'Primeros caracteres, para reconocerla en la lista',
  activa       TINYINT(1) NOT NULL DEFAULT 1,
  ultimo_uso   DATETIME NULL,
  ultimo_ip    VARCHAR(45) NULL,
  creada_por   INT UNSIGNED NULL,
  creada_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_claves_hash (clave_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permisos (codigo, modulo, descripcion) VALUES
  ('agente.administrar', 'sistema', 'Crear y revocar las claves del agente de sincronización y ver sus envíos');
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'agente.administrar' WHERE r.es_admin = 1 OR r.es_master = 1;
