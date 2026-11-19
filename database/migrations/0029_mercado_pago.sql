-- Mercado Pago (0.27.0): cobros con QR dinámico y link de pago, y transferencias como forma de pago.
--   mp_cobros: cada cobro iniciado. La venta NO se emite (ni pide CAE a ARCA) hasta que Mercado Pago confirma la acreditación: mientras tanto el cobro queda «pendiente»
--     y guarda la venta armada (`datos`, sin claves ni contraseñas). Estados: pendiente, emitido, error_emision (se cobró pero no se pudo emitir), vencido, cancelado.
--   forma_de_pagos: 'MP' MERCADO PAGO y 'TR' TRANSFERENCIA, ambas de tipo 10 (billetera virtual / medios electrónicos).
--   Permisos: mercadopago.cobrar (cobrar con Mercado Pago y ver mis cobros), mercadopago.administrar (ver todos los cobros, reintentar, diagnóstico).
CREATE TABLE IF NOT EXISTS mp_cobros (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  token               CHAR(32) NOT NULL COMMENT 'external_reference en Mercado Pago y clave de operación de la venta',
  estado              VARCHAR(15) NOT NULL DEFAULT 'pendiente',
  modo                CHAR(1) NOT NULL DEFAULT 'S' COMMENT 'S simulado, P producción',
  importe             DECIMAL(14,2) NOT NULL,
  descripcion         VARCHAR(150) NOT NULL DEFAULT '',
  origen              VARCHAR(10) NOT NULL DEFAULT '',
  datos               MEDIUMTEXT NOT NULL,
  autorizo            VARCHAR(255) NULL COMMENT 'JSON: quién autorizó vender sin stock (si hizo falta)',
  usuario_id          INT UNSIGNED NOT NULL,
  creado_en           DATETIME NOT NULL,
  vence_en            DATETIME NOT NULL,
  link                VARCHAR(600) NULL,
  qr_data             TEXT NULL,
  mp_ref              VARCHAR(80) NULL COMMENT 'id de la preferencia / orden de Mercado Pago',
  mp_pago_id          VARCHAR(30) NULL,
  acreditado_en       DATETIME NULL,
  comprobante_id      INT UNSIGNED NULL,
  emitido_en          DATETIME NULL,
  error               VARCHAR(500) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mp_token (token),
  KEY ix_mp_estado (estado),
  KEY ix_mp_creado (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO forma_de_pagos (codigo, descripcion, tipo) VALUES ('MP', 'MERCADO PAGO', 10), ('TR', 'TRANSFERENCIA', 10);

INSERT IGNORE INTO permisos (codigo, modulo, descripcion) VALUES
  ('mercadopago.cobrar', 'mercadopago', 'Mercado Pago: cobrar con QR o link de pago y ver mis cobros'),
  ('mercadopago.administrar', 'mercadopago', 'Mercado Pago: ver los cobros de todos, reintentar emisiones y diagnóstico');
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN ('mercadopago.cobrar', 'mercadopago.administrar') WHERE r.es_admin = 1 OR r.es_master = 1;
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'mercadopago.cobrar' WHERE r.codigo = 'operador';
