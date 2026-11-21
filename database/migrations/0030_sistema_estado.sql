-- Actualización automática de la base (0.30.0).
--   sistema_estado: pares clave/valor internos del sistema (firma de las migraciones ya aplicadas y cuándo se aplicaron). No se muestran en Configuración.
CREATE TABLE IF NOT EXISTS sistema_estado (
  clave       VARCHAR(60) NOT NULL,
  valor       VARCHAR(255) NOT NULL,
  actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
