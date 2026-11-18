-- Reservas de stock: quien toma un producto (carrito del kiosco o comprobante en carga) lo reserva hasta que se factura (o se abandona y vence).
-- Si otro lo toma igual (porque no alcanza lo disponible), hace falta la autorización de un supervisor (permiso stock.autorizar), que queda registrada.
-- Fotos de los artículos (con zoom en pantalla).

CREATE TABLE IF NOT EXISTS stock_reservas (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  articulo    VARCHAR(15) NOT NULL,
  cantidad    DECIMAL(14,3) NOT NULL,
  token       CHAR(32) NOT NULL COMMENT 'Una venta en curso (carrito del kiosco o formulario de comprobante)',
  usuario_id  INT UNSIGNED NULL,
  origen      VARCHAR(12) NOT NULL DEFAULT 'comprobante',
  creado      DATETIME NOT NULL,
  vence       DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reserva_token_articulo (token, articulo),
  KEY ix_reserva_articulo (articulo, vence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_autorizaciones (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  fecha              DATETIME NOT NULL,
  articulo           VARCHAR(15) NOT NULL,
  cantidad           DECIMAL(14,3) NOT NULL COMMENT 'Lo que se vendió de más (no alcanzaba lo disponible)',
  disponible         DECIMAL(14,3) NOT NULL COMMENT 'Lo que había disponible (stock menos lo reservado por otros)',
  pide_usuario_id    INT UNSIGNED NULL,
  autoriza_usuario_id INT UNSIGNED NULL COMMENT 'Quién autorizó (NULL si ARCA ya había autorizado el comprobante y el stock cambió en el medio)',
  subdiario_id       INT UNSIGNED NULL,
  detalle            VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY ix_autoriz_fecha (fecha),
  KEY ix_autoriz_subdiario (subdiario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS articulos_fotos (
  articulo       VARCHAR(15) NOT NULL,
  grande         MEDIUMBLOB NOT NULL COMMENT 'JPEG de hasta 1600 px (para el zoom)',
  chica          MEDIUMBLOB NOT NULL COMMENT 'JPEG de hasta 360 px (tarjetas y listas)',
  ancho          SMALLINT UNSIGNED NOT NULL,
  alto           SMALLINT UNSIGNED NOT NULL,
  actualizacion  DATETIME NOT NULL,
  PRIMARY KEY (articulo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO configuracion (clave, valor, tipo, descripcion) VALUES
  ('stock.reserva_minutos', '15', 'entero',
   'Minutos que dura la reserva de un producto en una venta en curso (carrito del kiosco o comprobante en carga) si no se factura ni se sigue trabajando en esa pantalla');

INSERT IGNORE INTO permisos (codigo, modulo, descripcion) VALUES
  ('stock.autorizar', 'stock', 'Autorizar vender un producto sin stock disponible o reservado por otro puesto');
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'stock.autorizar' WHERE r.es_admin = 1 OR r.es_master = 1;
