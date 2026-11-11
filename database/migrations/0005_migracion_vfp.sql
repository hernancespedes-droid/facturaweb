-- Registro de cada corrida del migrador DBF → MySQL (bin/migrar-dbf.php). Lo muestra la página Sistema.
CREATE TABLE IF NOT EXISTS migracion_vfp (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  fecha       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  origen      VARCHAR(255) NOT NULL COMMENT 'Carpeta de los DBF',
  tablas      VARCHAR(500) NOT NULL,
  resumen     MEDIUMTEXT   NOT NULL COMMENT 'JSON: filas leídas, grabadas y avisos por tabla',
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
