-- Web → VFP: lo que se emite en la web (con validez fiscal) y el stock que mueve se baja al VFP por el agente. Cada comprobante y cada
-- movimiento de stock sabe si ya está en el VFP (vfp_enviado) o si el VFP lo rechazó (vfp_error: no se reenvía hasta reintentar).
ALTER TABLE subdiario
  ADD COLUMN vfp_enviado DATETIME NULL COMMENT 'web: cuándo se grabó en el VFP (NULL = pendiente)',
  ADD COLUMN vfp_error VARCHAR(255) NULL COMMENT 'web: por qué el VFP no lo grabó';
ALTER TABLE stock_movimientos
  ADD COLUMN vfp_enviado DATETIME NULL COMMENT 'cuándo se grabó en el VFP (NULL = pendiente)',
  ADD COLUMN vfp_error VARCHAR(255) NULL COMMENT 'por qué el VFP no lo grabó';
-- Con qué sucursal del VFP se corresponde cada depósito (STOCK del VFP es por sucursal + artículo).
ALTER TABLE depositos
  ADD COLUMN sucursal_vfp SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Sucursal del VFP donde se graba el stock de este depósito';
