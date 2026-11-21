-- Compras, entrega C (0.47.0): comprobantes recibidos según ARCA («Mis Comprobantes Recibidos»), para controlar contra lo cargado.
--   Todo aditivo: una tabla nueva. Volver a importar el mismo archivo actualiza las filas (clave: CUIT del emisor + tipo + punto de venta + número).

CREATE TABLE IF NOT EXISTS compras_arca (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cuit           BIGINT UNSIGNED NOT NULL COMMENT 'CUIT del emisor (el proveedor)',
  tipo_cmp       SMALLINT UNSIGNED NOT NULL COMMENT 'código de comprobante de ARCA (1 factura A, 6 factura B, 11 factura C…)',
  punto_venta    INT UNSIGNED NOT NULL,
  numero         INT UNSIGNED NOT NULL,
  fecha          DATE NOT NULL,
  denominacion   VARCHAR(120) NOT NULL DEFAULT '',
  neto           DECIMAL(16,2) NOT NULL DEFAULT 0 COMMENT 'neto gravado',
  no_gravado     DECIMAL(16,2) NOT NULL DEFAULT 0 COMMENT 'no gravado + exento',
  iva            DECIMAL(16,2) NOT NULL DEFAULT 0,
  otros          DECIMAL(16,2) NOT NULL DEFAULT 0 COMMENT 'otros tributos',
  total          DECIMAL(16,2) NOT NULL,
  moneda         VARCHAR(5) NOT NULL DEFAULT '$',
  cambio         DECIMAL(12,6) NOT NULL DEFAULT 1,
  cae            VARCHAR(20) NOT NULL DEFAULT '',
  importado_en   DATETIME NOT NULL,
  usuario_id     INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_carca (cuit, tipo_cmp, punto_venta, numero),
  KEY ix_carca_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
