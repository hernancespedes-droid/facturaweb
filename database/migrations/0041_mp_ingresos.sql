-- Ingresos de dinero a la cuenta de Mercado Pago (0.41.0): avisos de todo pago aprobado que entra a la cuenta, venga o no de una venta del sistema
-- (QR, link, tarjeta, dinero en cuenta, transferencias que informa la API). Se enteran la notificación firmada (/api/v1/mercadopago/webhook) y una consulta
-- periódica (como mucho una por minuto, la disparan las pantallas abiertas o bin/mp-ingresos.php por cron).
--   mp_ingresos: un renglón por pago de Mercado Pago (mp_pago_id único: nunca se repite). referencia = external_reference (el token de mp_cobros si es una venta de la web).
--   mp_ingresos_vistos: hasta qué ingreso vio cada usuario (la campanita cuenta los posteriores).
--   Permiso mercadopago.ingresos: ver los ingresos y recibir los avisos. Configuración mercadopago.ingresos_mails: mails que reciben un aviso por cada ingreso (vacío = no se manda).
CREATE TABLE IF NOT EXISTS mp_ingresos (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  mp_pago_id      VARCHAR(30) NOT NULL,
  modo            CHAR(1) NOT NULL DEFAULT 'P' COMMENT 'S simulado, P producción',
  estado          VARCHAR(20) NOT NULL DEFAULT 'approved' COMMENT 'estado del pago en Mercado Pago (approved, refunded, charged_back…)',
  fecha           DATETIME NOT NULL COMMENT 'acreditación (date_approved) en hora local',
  monto           DECIMAL(14,2) NOT NULL,
  neto            DECIMAL(14,2) NULL COMMENT 'lo que recibe la cuenta (descontada la comisión), si Mercado Pago lo informa',
  moneda          CHAR(3) NOT NULL DEFAULT 'ARS',
  medio           VARCHAR(30) NOT NULL DEFAULT '' COMMENT 'payment_type_id',
  medio_detalle   VARCHAR(40) NOT NULL DEFAULT '' COMMENT 'payment_method_id',
  pagador         VARCHAR(150) NOT NULL DEFAULT '',
  pagador_mail    VARCHAR(150) NOT NULL DEFAULT '',
  pagador_doc     VARCHAR(40) NOT NULL DEFAULT '',
  descripcion     VARCHAR(255) NOT NULL DEFAULT '',
  referencia      VARCHAR(64) NOT NULL DEFAULT '',
  origen          VARCHAR(10) NOT NULL DEFAULT '' COMMENT 'webhook, consulta o simulado',
  registrado_en   DATETIME NOT NULL,
  mail_enviado_en DATETIME NULL,
  mail_error      VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mpi_pago (mp_pago_id),
  KEY ix_mpi_fecha (fecha),
  KEY ix_mpi_referencia (referencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mp_ingresos_vistos (
  usuario_id  INT UNSIGNED NOT NULL,
  ultimo_id   INT UNSIGNED NOT NULL DEFAULT 0,
  visto_en    DATETIME NOT NULL,
  PRIMARY KEY (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO configuracion (clave, valor, tipo, descripcion) VALUES
  ('mercadopago.ingresos_mails', '', 'texto', 'Mercado Pago: mails (separados por coma) que reciben un aviso por cada ingreso de dinero a la cuenta. Vacío = no se manda mail'),
  ('interno.mp_ingresos_consulta', '', 'texto', 'Última consulta de ingresos a Mercado Pago (uso interno)');

INSERT IGNORE INTO permisos (codigo, modulo, descripcion) VALUES
  ('mercadopago.ingresos', 'mercadopago', 'Mercado Pago: ver los ingresos de dinero a la cuenta y recibir sus avisos');
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'mercadopago.ingresos' WHERE r.es_admin = 1 OR r.es_master = 1;
