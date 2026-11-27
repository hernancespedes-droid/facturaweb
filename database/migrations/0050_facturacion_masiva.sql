-- Facturación masiva desde Excel (0.66.0, D-154…D-158).
--   prefacturas_lotes: cada planilla subida (archivo, quién, cuántas pre-facturas armó).
--   prefacturas: una factura pendiente de aprobar. «datos» guarda lo mismo que carga el formulario de la factura (JSON: cliente, renglones, fecha,
--   cómo se cobra…), así al aprobar se usa ComprobantesService::armar() + emitir() como cualquier factura (ARCA, stock, caja o cuenta corriente).
--   Mientras está pendiente no toca stock, caja ni cuenta corriente. «operacion» es la clave de la emisión: reintentar nunca factura dos veces.
--   Todo aditivo.
CREATE TABLE IF NOT EXISTS prefacturas_lotes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  creado_en     DATETIME NOT NULL,
  usuario_id    INT UNSIGNED NULL,
  archivo       VARCHAR(255) NOT NULL DEFAULT '',
  filas         INT UNSIGNED NOT NULL DEFAULT 0,
  prefacturas   INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prefacturas (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  lote_id         INT UNSIGNED NULL COMMENT 'prefacturas_lotes.id (NULL si se cargó a mano)',
  referencia      VARCHAR(60) NOT NULL DEFAULT '' COMMENT 'Lo que agrupa los renglones en la planilla, o el número de fila',
  filas_planilla  VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Filas del archivo que la formaron (para encontrarlas)',
  cliente         INT UNSIGNED NULL,
  cliente_texto   VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'Lo que decía la planilla si el cliente no se encontró',
  fecha           DATE NULL COMMENT 'NULL = la del día en que se factura',
  letra           CHAR(1) NOT NULL DEFAULT '',
  cobro           VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Cómo se cobra, para mostrar',
  total           DECIMAL(15,2) NULL,
  datos           MEDIUMTEXT NOT NULL COMMENT 'JSON con lo del formulario de la factura',
  errores         TEXT NULL COMMENT 'Lo que hay que corregir antes de facturar (NULL = lista)',
  estado          VARCHAR(12) NOT NULL DEFAULT 'pendiente' COMMENT 'pendiente | procesando | emitida | eliminada',
  operacion       CHAR(32) NOT NULL COMMENT 'Clave de la emisión (ComprobantesService::emitir)',
  subdiario_id    INT UNSIGNED NULL,
  aviso           VARCHAR(255) NULL COMMENT 'Después de emitir: el mail que no salió, etc.',
  creado_en       DATETIME NOT NULL,
  creado_por      INT UNSIGNED NULL,
  modificado_en   DATETIME NULL,
  modificado_por  INT UNSIGNED NULL,
  procesado_en    DATETIME NULL,
  procesado_por   INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_prefacturas_operacion (operacion),
  KEY ix_prefacturas_estado (estado, fecha),
  KEY ix_prefacturas_lote (lote_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permisos (codigo, modulo, descripcion) VALUES
  ('comprobantes.masiva', 'facturacion', 'Facturación masiva: subir la planilla, modificar, eliminar y aprobar pre-facturas');
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'comprobantes.masiva' WHERE r.es_admin = 1 OR r.es_master = 1;
