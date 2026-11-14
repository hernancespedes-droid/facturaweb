-- Tabla de retenciones que sufren los cobros (Ganancias, IVA, Ingresos Brutos por provincia, SUSS...). La carga el cliente en Tablas → Retenciones.
-- Cada recibo lleva las que quiera (cobra_retenciones), cada una con su importe y el número de certificado. Las 5 columnas cod_rt/imp_rt de cobra
-- quedan para los recibos que vienen del VFP (su código coincide con retenciones.codigo).
CREATE TABLE IF NOT EXISTS retenciones (
  id             SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo         SMALLINT UNSIGNED NOT NULL,
  descripcion    VARCHAR(40) NOT NULL,
  impuesto       TINYINT UNSIGNED NOT NULL DEFAULT 9 COMMENT '1 Ganancias, 2 IVA, 3 Ingresos Brutos, 4 SUSS, 9 Otra',
  provincia      VARCHAR(2) COLLATE utf8mb4_bin NULL COMMENT 'provincias.codigo (Ingresos Brutos)',
  actualizacion  DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_retenciones_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cobra_retenciones (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cobra_id     INT UNSIGNED NOT NULL,
  retencion    SMALLINT UNSIGNED NOT NULL COMMENT 'retenciones.codigo',
  importe      DECIMAL(14,2) NOT NULL DEFAULT 0,
  certificado  VARCHAR(30) NULL,
  PRIMARY KEY (id),
  KEY ix_cobraret_cobra (cobra_id),
  KEY ix_cobraret_retencion (retencion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Las que traía el VFP por omisión (01 a 04); el cliente agrega las suyas y las separa por provincia.
INSERT IGNORE INTO retenciones (codigo, descripcion, impuesto) VALUES
  (1, 'Ganancias', 1), (2, 'IVA', 2), (3, 'Ingresos Brutos', 3), (4, 'SUSS', 4);

INSERT IGNORE INTO permisos (codigo, modulo, descripcion) VALUES ('tablas.retenciones', 'tablas', 'Retenciones de los cobros');
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'tablas.retenciones' WHERE r.es_admin = 1 OR r.es_master = 1;
