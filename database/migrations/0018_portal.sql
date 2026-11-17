-- Portal de clientes: el cliente entra con un link que le llega al mail (sin contraseña) y baja sus comprobantes.
-- Solo se guarda el hash del link; vence a los 30 minutos y sirve una sola vez.
CREATE TABLE IF NOT EXISTS portal_accesos (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email       VARCHAR(120) NOT NULL,
  token_hash  CHAR(64) NOT NULL,
  vence       DATETIME NOT NULL,
  usado_en    DATETIME NULL,
  ip          VARCHAR(45) NULL,
  creado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_portal_token (token_hash),
  KEY ix_portal_email (email, creado_en),
  KEY ix_portal_ip (ip, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO configuracion (clave, valor, tipo, descripcion) VALUES
  ('portal.activo', '0', 'si_no',
   'Portal de clientes (/portal): cada cliente entra con un link que le llega al mail cargado en su ficha (mails para facturas) y baja sus comprobantes. Necesita el mail de salida configurado.');
