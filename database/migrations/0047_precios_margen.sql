-- Precios con margen sobre el costo (0.49.0, D-130…D-133).
--   Margen (% de recargo sobre el costo) para cada lista: 1, 2, 3 y oferta. Se define en tres niveles y gana el más específico:
--   artículo → rubro → general (Parámetros). NULL = toma el del nivel de arriba; si ninguno tiene, esa lista no se calcula.
--   parametros.precio_redondeo: redondeo hacia arriba del precio calculado (0 = centavos, 1, 10, 50 o 100 pesos).
--   parametros.precios_con_iva: los precios de venta incluyen IVA (precio final). Por omisión, como ya suponía «Costos y márgenes» (D-64):
--   netos si la empresa es responsable inscripta y finales si no.
--   articulos.precio_automatico: los precios del artículo se calculan solos desde el costo. Los artículos que ya existen quedan manuales.
--   articulos_precios_historial: cada cambio de costo o de precios (de la ficha, la actualización masiva, el stock, una compra o un importador).
--   Permiso articulos.precios: márgenes, costo, precio automático y actualización masiva (Administrador y Master).
--   Todo aditivo. El margen viejo del VFP (articulos.margen) pasa como margen de la lista 1 del artículo (la columna queda, sin uso).
ALTER TABLE parametros
  ADD COLUMN margen_l1 DECIMAL(7,2) NULL COMMENT 'Margen general lista 1 (% sobre el costo)',
  ADD COLUMN margen_l2 DECIMAL(7,2) NULL COMMENT 'Margen general lista 2',
  ADD COLUMN margen_l3 DECIMAL(7,2) NULL COMMENT 'Margen general lista 3',
  ADD COLUMN margen_oferta DECIMAL(7,2) NULL COMMENT 'Margen general de oferta',
  ADD COLUMN precio_redondeo SMALLINT UNSIGNED NULL DEFAULT 0 COMMENT 'Redondeo hacia arriba del precio calculado: 0 centavos, 1, 10, 50, 100',
  ADD COLUMN precios_con_iva TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = los precios de venta incluyen IVA (precio final)';
UPDATE parametros SET precios_con_iva = IF(iva = 1, 0, 1);

ALTER TABLE rubros
  ADD COLUMN margen_l1 DECIMAL(7,2) NULL,
  ADD COLUMN margen_l2 DECIMAL(7,2) NULL,
  ADD COLUMN margen_l3 DECIMAL(7,2) NULL,
  ADD COLUMN margen_oferta DECIMAL(7,2) NULL;

ALTER TABLE articulos
  ADD COLUMN margen_l1 DECIMAL(7,2) NULL,
  ADD COLUMN margen_l2 DECIMAL(7,2) NULL,
  ADD COLUMN margen_l3 DECIMAL(7,2) NULL,
  ADD COLUMN margen_oferta DECIMAL(7,2) NULL,
  ADD COLUMN precio_automatico TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = los precios se calculan solos desde el costo y los márgenes';
UPDATE articulos SET margen_l1 = margen WHERE margen > 0 AND margen_l1 IS NULL;

CREATE TABLE IF NOT EXISTS articulos_precios_historial (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  articulo         VARCHAR(15) NOT NULL,
  fecha            DATETIME NOT NULL,
  usuario_id       INT UNSIGNED NULL,
  origen           VARCHAR(12) NOT NULL COMMENT 'FICHA, MASIVO, STOCK, COMPRA, IMPORTACION',
  detalle          VARCHAR(160) NOT NULL DEFAULT '',
  costo_anterior   DECIMAL(15,4) NOT NULL DEFAULT 0,
  costo            DECIMAL(15,4) NOT NULL DEFAULT 0,
  l1_anterior      DECIMAL(15,4) NOT NULL DEFAULT 0,
  l1               DECIMAL(15,4) NOT NULL DEFAULT 0,
  l2_anterior      DECIMAL(15,4) NOT NULL DEFAULT 0,
  l2               DECIMAL(15,4) NOT NULL DEFAULT 0,
  l3_anterior      DECIMAL(15,4) NOT NULL DEFAULT 0,
  l3               DECIMAL(15,4) NOT NULL DEFAULT 0,
  oferta_anterior  DECIMAL(15,4) NOT NULL DEFAULT 0,
  oferta           DECIMAL(15,4) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_aph_articulo (articulo, fecha),
  KEY ix_aph_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permisos (codigo, modulo, descripcion) VALUES
  ('articulos.precios', 'maestros', 'Artículos: márgenes, costo, precio automático y actualización masiva de precios');
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'articulos.precios' WHERE r.es_admin = 1 OR r.es_master = 1;
