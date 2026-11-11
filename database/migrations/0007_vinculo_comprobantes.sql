-- Con los datos reales de OYS: el número interno (comprobante) NO es único en el VFP. El servicio de ARCA renumera al aprobar
-- y quedan comprobantes repetidos de distinta fecha; el VFP los distingue con la fecha (su clave es tipo + letra + prefijo +
-- sucursal + número + fecha). Acá la clave única incluye la fecha y cada fila de iva, estadistica, caja y ctact apunta a su
-- comprobante con subdiario_id (el fe_clave del subdiario), sin ambigüedades.
ALTER TABLE subdiario
  DROP INDEX uq_subdiario_comprobante,
  ADD UNIQUE KEY uq_subdiario_comprobante (tipo, letra, sucursal, comprobante, fecha);

ALTER TABLE iva
  DROP INDEX uq_iva,
  ADD COLUMN subdiario_id INT UNSIGNED NULL AFTER fe_clave,
  ADD KEY ix_iva_comprobante (tipo, letra, sucursal, comprobante),
  ADD KEY ix_iva_subdiario (subdiario_id);
ALTER TABLE estadistica
  ADD COLUMN subdiario_id INT UNSIGNED NULL AFTER fe_clave,
  ADD KEY ix_estadistica_subdiario (subdiario_id);
ALTER TABLE caja
  ADD COLUMN subdiario_id INT UNSIGNED NULL AFTER fe_clave,
  ADD KEY ix_caja_subdiario (subdiario_id);
ALTER TABLE ctact
  ADD COLUMN subdiario_id INT UNSIGNED NULL AFTER fe_clave,
  ADD KEY ix_ctact_subdiario (subdiario_id);
