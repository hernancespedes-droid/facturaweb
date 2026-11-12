-- Artículos: EAN-13, DUN-14, SKU y unidades por bulto. Renglones: texto adicional que se imprime en el comprobante.
ALTER TABLE articulos
  ADD COLUMN ean13 VARCHAR(13) NULL COMMENT 'Código de barras EAN-13 de la unidad',
  ADD COLUMN dun14 VARCHAR(14) NULL COMMENT 'Código DUN-14 del bulto',
  ADD COLUMN sku VARCHAR(40) NULL COMMENT 'SKU',
  ADD COLUMN unidades_por_bulto INT UNSIGNED NULL COMMENT 'Unidades que trae cada bulto',
  ADD KEY ix_articulos_ean13 (ean13),
  ADD KEY ix_articulos_dun14 (dun14),
  ADD KEY ix_articulos_sku (sku);

ALTER TABLE estadistica
  ADD COLUMN texto_impresion TEXT NULL COMMENT 'Texto adicional del renglón que se imprime debajo de la descripción';

-- El código de barras que venía del VFP: si es de 13 o 14 dígitos pasa al campo que le corresponde (el original se conserva).
UPDATE articulos SET ean13 = codigo_de_barras WHERE ean13 IS NULL AND codigo_de_barras REGEXP '^[0-9]{13}$';
UPDATE articulos SET dun14 = codigo_de_barras WHERE dun14 IS NULL AND codigo_de_barras REGEXP '^[0-9]{14}$';
