-- Etapa 4: cuenta corriente y recibos. Esquema O&S (cobra = recibos, imputaciones) con las columnas que agrega la web.
-- tico de ctact: 1 factura, 2 nota de débito, 3 ajuste de débito (AD) | 7 recibo (RC), 8 nota de crédito, 9 ajuste de crédito (AC).

CREATE TABLE IF NOT EXISTS cobra (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cliente        INT UNSIGNED NOT NULL,
  fecha          DATE NOT NULL,
  sucursal       SMALLINT UNSIGNED NOT NULL,
  prefijo        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  recibo         INT UNSIGNED NOT NULL,
  bruto          DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Lo que se cancela de la deuda (más el anticipo, si hay)',
  neto           DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Lo que se cobra de verdad = bruto - descuento - retenciones',
  dto_bruto      DECIMAL(14,2) NOT NULL DEFAULT 0,
  dto_iva        DECIMAL(14,2) NOT NULL DEFAULT 0,
  cod_rt1        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  imp_rt1        DECIMAL(14,2) NOT NULL DEFAULT 0,
  cod_rt2        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  imp_rt2        DECIMAL(14,2) NOT NULL DEFAULT 0,
  cod_rt3        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  imp_rt3        DECIMAL(14,2) NOT NULL DEFAULT 0,
  cod_rt4        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  imp_rt4        DECIMAL(14,2) NOT NULL DEFAULT 0,
  cod_rt5        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  imp_rt5        DECIMAL(14,2) NOT NULL DEFAULT 0,
  observaciones  VARCHAR(255) NULL,
  estado         CHAR(1) NOT NULL DEFAULT '' COMMENT "'' vigente, 'A' anulado",
  usuario_id     INT UNSIGNED NULL,
  operacion      CHAR(32) NULL COMMENT 'Clave contra el doble envío del formulario',
  actualizacion  DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cobra_operacion (operacion),
  UNIQUE KEY uq_cobra_numero (sucursal, recibo, fecha),
  KEY ix_cobra_cliente (cliente, fecha),
  KEY ix_cobra_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quién canceló qué: "db" es lo que se cancela (factura, nota de débito, ajuste de débito), "cr" lo que cancela (recibo, nota de crédito,
-- ajuste de crédito). ctact_db / ctact_cr apuntan a las filas de ctact.
CREATE TABLE IF NOT EXISTS imputaciones (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  clientes       INT UNSIGNED NOT NULL,
  tipodb         VARCHAR(2) COLLATE utf8mb4_bin NOT NULL,
  ticodb         TINYINT UNSIGNED NOT NULL,
  letradb        CHAR(1) COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  nrcodb         INT UNSIGNED NOT NULL,
  fecodb         DATE NULL,
  tipocr         VARCHAR(2) COLLATE utf8mb4_bin NOT NULL,
  ticocr         TINYINT UNSIGNED NOT NULL,
  nrcocr         INT UNSIGNED NOT NULL,
  fecocr         DATE NULL,
  importe        DECIMAL(14,2) NOT NULL DEFAULT 0,
  recibo         INT UNSIGNED NOT NULL DEFAULT 0,
  ctact_db       INT UNSIGNED NULL,
  ctact_cr       INT UNSIGNED NULL,
  cobra_id       INT UNSIGNED NULL,
  usuario_id     INT UNSIGNED NULL,
  actualizacion  DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_imput_cliente (clientes),
  KEY ix_imput_db (ctact_db),
  KEY ix_imput_cr (ctact_cr),
  KEY ix_imput_cobra (cobra_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ctact
  ADD COLUMN cobra_id INT UNSIGNED NULL AFTER subdiario_id,
  ADD COLUMN observaciones VARCHAR(255) NULL,
  ADD KEY ix_ctact_cobra (cobra_id);

-- Cartera de cheques: los cheques cobrados con un recibo o con una factura (caja.p_fpago de tipo Cheques).
ALTER TABLE caja
  ADD COLUMN cobra_id INT UNSIGNED NULL AFTER subdiario_id,
  ADD COLUMN cheque_estado CHAR(1) NOT NULL DEFAULT '' COMMENT "'' en cartera, 'D' depositado, 'E' entregado a un tercero, 'R' rechazado",
  ADD COLUMN cheque_fecha DATE NULL COMMENT 'Fecha del último cambio de estado',
  ADD COLUMN cheque_obs VARCHAR(255) NULL,
  ADD KEY ix_caja_cobra (cobra_id),
  ADD KEY ix_caja_cheque (cheque_estado, p_fecvto);

INSERT IGNORE INTO permisos (codigo, modulo, descripcion) VALUES
  ('ctacte.ver', 'ctacte', 'Ver la cuenta corriente de los clientes, la deuda y la cartera de cheques'),
  ('ctacte.cobrar', 'ctacte', 'Cargar recibos (cobranzas)'),
  ('ctacte.imputar', 'ctacte', 'Imputar notas de crédito y anticipos contra la deuda'),
  ('ctacte.ajustar', 'ctacte', 'Ajustes de cuenta corriente, anulación de recibos y estado de los cheques');
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN ('ctacte.ver', 'ctacte.cobrar', 'ctacte.imputar', 'ctacte.ajustar') WHERE r.es_admin = 1 OR r.es_master = 1;
-- El Operador (el que factura) consulta la cuenta corriente y carga recibos; imputar, ajustar y anular quedan para Administrador y Master (D-32).
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN ('ctacte.ver', 'ctacte.cobrar') WHERE r.codigo = 'operador';
