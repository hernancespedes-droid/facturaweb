-- Actualización masiva de precios por filtro (0.60.0, D-144…D-146).
--   precios_actualizaciones: una fila por cada actualización confirmada (filtros, qué se actualizó, forma y valor, cuántos artículos) y si se deshizo.
--   articulos_precios_historial.actualizacion_id: los cambios de cada artículo que hizo esa actualización (para deshacerla).
--   automatico_anterior / automatico: si la actualización pasó el artículo de «Precio automático» a manual (D-145), para devolverlo al deshacer.
--   Todo aditivo; usa el permiso articulos.precios que ya existe (0.49.0).
CREATE TABLE IF NOT EXISTS precios_actualizaciones (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  fecha          DATETIME NOT NULL,
  usuario_id     INT UNSIGNED NULL,
  descripcion    VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Resumen legible: qué, forma, valor y filtros',
  parametros     TEXT NULL COMMENT 'JSON con los filtros y opciones tal como se confirmaron',
  articulos      INT UNSIGNED NOT NULL DEFAULT 0,
  a_manual       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Artículos que pasaron de precio automático a manual',
  deshecha_en    DATETIME NULL,
  deshecha_por   INT UNSIGNED NULL,
  deshechos      INT UNSIGNED NOT NULL DEFAULT 0,
  no_deshechos   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Artículos que cambiaron después y se dejaron como estaban',
  PRIMARY KEY (id),
  KEY ix_pa_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE articulos_precios_historial
  ADD COLUMN actualizacion_id INT UNSIGNED NULL COMMENT 'precios_actualizaciones.id (actualización masiva por filtro)',
  ADD COLUMN automatico_anterior TINYINT(1) NULL,
  ADD COLUMN automatico TINYINT(1) NULL,
  ADD KEY ix_aph_actualizacion (actualizacion_id);
