-- Actualización automática y panel de instalaciones (0.28.0).
--   sistema_estado: pares clave/valor internos del sistema (firma de las migraciones ya aplicadas, cuándo se aplicaron). No se muestran en Configuración.
--   instalaciones: las instalaciones (clientes) que el Master quiere vigilar desde Sistema → Instalaciones, con el resultado de la última revisión de su /salud.
CREATE TABLE IF NOT EXISTS sistema_estado (
  clave       VARCHAR(60) NOT NULL,
  valor       VARCHAR(255) NOT NULL,
  actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS instalaciones (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre          VARCHAR(80) NOT NULL,
  url             VARCHAR(200) NOT NULL COMMENT 'dirección base de la instalación (sin /salud)',
  notas           VARCHAR(200) NOT NULL DEFAULT '',
  revisada_en     DATETIME NULL,
  ok              TINYINT(1) NULL,
  version         VARCHAR(20) NULL,
  pendientes      INT NULL COMMENT 'cambios de base pendientes',
  licencia_dias   INT NULL COMMENT 'días que faltan para vencer la licencia (null = sin licencia)',
  migracion_error TINYINT(1) NULL,
  error           VARCHAR(200) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_instalaciones_url (url)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
