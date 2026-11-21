-- Importadores desde Excel, CSV y TXT (0.33.0): clientes, artículos, stock inicial y comprobantes históricos (subdiario), para los clientes
-- que no vienen del VFP. Sirve para cualquier cliente sin código propio: cada uno arma su «modelo» (qué columna de su archivo va a cada campo).
--   importacion_modelos: modelos guardados (por destino). El mapa guarda el TÍTULO de la columna del archivo, así sirve aunque cambie el orden.
--   importaciones: registro de cada importación grabada (quién, qué archivo, cuántas filas, el resumen).
--   subdiario.importacion_id: de qué importación vino un comprobante histórico (canal 'I'; no va al VFP ni se pide CAE).
CREATE TABLE IF NOT EXISTS importacion_modelos (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  destino        VARCHAR(20) NOT NULL COMMENT 'clientes, articulos, stock, subdiario',
  nombre         VARCHAR(60) NOT NULL,
  mapa           TEXT NOT NULL COMMENT 'JSON: campo => título de la columna del archivo',
  opciones       TEXT NOT NULL COMMENT 'JSON: opciones de la importación',
  creado_por     INT UNSIGNED NULL,
  actualizado_en DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_importacion_modelo (destino, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS importaciones (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  destino        VARCHAR(20) NOT NULL,
  archivo        VARCHAR(255) NOT NULL,
  usuario_id     INT UNSIGNED NULL,
  filas          INT UNSIGNED NOT NULL DEFAULT 0,
  nuevas         INT UNSIGNED NOT NULL DEFAULT 0,
  actualizadas   INT UNSIGNED NOT NULL DEFAULT 0,
  salteadas      INT UNSIGNED NOT NULL DEFAULT 0,
  con_error      INT UNSIGNED NOT NULL DEFAULT 0,
  resumen        MEDIUMTEXT NULL COMMENT 'JSON: errores y avisos (los primeros)',
  creado_en      DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_importaciones_creado (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE subdiario ADD COLUMN importacion_id INT UNSIGNED NULL COMMENT 'importaciones.id si vino de un archivo (comprobante histórico)',
  ADD KEY ix_subdiario_importacion (importacion_id);

INSERT IGNORE INTO permisos (codigo, modulo, descripcion) VALUES
  ('importar.datos', 'sistema', 'Importar clientes, artículos, stock y comprobantes desde Excel, CSV o TXT');
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'importar.datos' WHERE r.es_admin = 1 OR r.es_master = 1;
