-- Logo de la empresa para los comprobantes impresos (factura, nota de crédito/débito, recibo y PDF).
-- Se guarda en la base (no en una carpeta) para que viaje con el backup y no dependa de permisos de escritura del hosting.
-- La web lo normaliza al subirlo: JPEG sobre fondo blanco, como máximo 800 x 400 píxeles.
CREATE TABLE IF NOT EXISTS imagenes (
  clave           VARCHAR(40)  NOT NULL,
  tipo            VARCHAR(30)  NOT NULL,
  datos           MEDIUMTEXT   NOT NULL,   -- la imagen en base64
  ancho           INT UNSIGNED NOT NULL,
  alto            INT UNSIGNED NOT NULL,
  actualizado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  actualizado_por INT UNSIGNED NULL,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
