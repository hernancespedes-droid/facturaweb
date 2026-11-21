-- Compras y centros de costos, entrega A (0.34.0): proveedores, conceptos de gasto, centros de costos con presupuesto mensual, compras con IVA por alícuota,
-- percepciones, reparto entre centros, adjuntos y pagos (efectivo de la caja, otro medio o a cuenta corriente para pagar después).
--   Todo lo nuevo de la web (no hay tablas de compras en el VFP de O&S). El módulo se enciende por cliente (Core\Modulos 'compras').
--   Permisos: compras.ver (tablero y listados), compras.cargar, compras.pagar, compras.centros (centros, conceptos, presupuestos y plantillas).

CREATE TABLE IF NOT EXISTS proveedores (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo          INT UNSIGNED NOT NULL COMMENT 'código visible (el mismo que articulos.proveedor)',
  razon_social    VARCHAR(100) NOT NULL,
  cuit            VARCHAR(11) NULL,
  iva             TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'condición de IVA, mismos códigos que clientes.iva',
  domicilio       VARCHAR(100) NOT NULL DEFAULT '',
  localidad       VARCHAR(60) NOT NULL DEFAULT '',
  telefono        VARCHAR(40) NOT NULL DEFAULT '',
  email           VARCHAR(120) NOT NULL DEFAULT '',
  cbu_alias       VARCHAR(40) NOT NULL DEFAULT '' COMMENT 'CBU o alias para transferir',
  plazo_dias      SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'plazo de pago habitual: vence = fecha + plazo',
  concepto_id     INT UNSIGNED NULL COMMENT 'concepto de gasto que se propone al cargarle una compra',
  centro_id       INT UNSIGNED NULL COMMENT 'centro de costos que se propone',
  observaciones   VARCHAR(255) NOT NULL DEFAULT '',
  activo          TINYINT(1) NOT NULL DEFAULT 1,
  creado_en       DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_prov_codigo (codigo),
  KEY ix_prov_cuit (cuit),
  KEY ix_prov_nombre (razon_social)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conceptos_gasto (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre    VARCHAR(60) NOT NULL,
  activo    TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_concepto_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dos niveles como mucho: un centro puede tener un padre (que no tenga padre). «General» es el centro por omisión y no se desactiva.
CREATE TABLE IF NOT EXISTS centros_costo (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre    VARCHAR(60) NOT NULL,
  padre_id  INT UNSIGNED NULL,
  general   TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = el centro por omisión',
  activo    TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_centro_padre (padre_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS centros_presupuesto (
  centro_id  INT UNSIGNED NOT NULL,
  periodo    CHAR(7) NOT NULL COMMENT 'AAAA-MM',
  importe    DECIMAL(14,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (centro_id, periodo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Plantillas de reparto («Luz: Local 60 / Depósito 40»): detalle = [{"centro":id,"porcentaje":60.00}, ...]
CREATE TABLE IF NOT EXISTS reparto_plantillas (
  id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre   VARCHAR(60) NOT NULL,
  detalle  JSON NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_plantilla_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cabecera de cada comprobante de compra. Importes siempre positivos; la nota de crédito resta en los listados (signo por tipo).
CREATE TABLE IF NOT EXISTS compras (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  proveedor_id     INT UNSIGNED NOT NULL,
  tipo             CHAR(2) NOT NULL COMMENT 'FC factura, ND nota de débito, NC nota de crédito, TQ tique, GS gasto sin comprobante',
  letra            CHAR(1) NOT NULL DEFAULT '' COMMENT 'A, B, C, M o vacío (gasto sin comprobante)',
  punto_venta      INT UNSIGNED NOT NULL DEFAULT 0,
  numero           INT UNSIGNED NOT NULL DEFAULT 0,
  fecha            DATE NOT NULL,
  periodo_iva      CHAR(7) NOT NULL COMMENT 'AAAA-MM en que se computa el crédito fiscal',
  gravado          DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'neto gravado (suma de compras_iva.neto)',
  no_gravado       DECIMAL(14,2) NOT NULL DEFAULT 0,
  exento           DECIMAL(14,2) NOT NULL DEFAULT 0,
  iva              DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'IVA discriminado (suma de compras_iva.iva)',
  percepciones     DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'suma de compras_tributos',
  total            DECIMAL(14,2) NOT NULL,
  costo            DECIMAL(14,2) NOT NULL COMMENT 'lo que se reparte entre centros: sin IVA ni percepciones si el crédito se computa; si no, el total',
  computa_iva      TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = va al subdiario de IVA compras con crédito fiscal',
  forma            CHAR(1) NOT NULL COMMENT 'E efectivo de la caja, O otro medio (ya pagado), C cuenta corriente (a pagar)',
  vence            DATE NULL,
  saldo            DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'lo que falta pagar (solo cuenta corriente)',
  cae              VARCHAR(20) NOT NULL DEFAULT '',
  observaciones    VARCHAR(255) NOT NULL DEFAULT '',
  a_revisar        TINYINT(1) NOT NULL DEFAULT 0,
  estado           CHAR(1) NOT NULL DEFAULT 'V' COMMENT 'V vigente, A anulada',
  anulada_en       DATETIME NULL,
  anulada_por      INT UNSIGNED NULL,
  motivo_anulacion VARCHAR(160) NOT NULL DEFAULT '',
  usuario_id       INT UNSIGNED NULL,
  creado_en        DATETIME NOT NULL,
  operacion        CHAR(32) NULL COMMENT 'clave del formulario contra el doble envío',
  PRIMARY KEY (id),
  UNIQUE KEY uq_compra_operacion (operacion),
  KEY ix_compra_prov (proveedor_id, tipo, letra, punto_venta, numero),
  KEY ix_compra_fecha (fecha),
  KEY ix_compra_periodo (periodo_iva),
  KEY ix_compra_vence (estado, forma, vence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS compras_iva (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  compra_id  INT UNSIGNED NOT NULL,
  alicuota   DECIMAL(5,2) NOT NULL,
  neto       DECIMAL(14,2) NOT NULL,
  iva        DECIMAL(14,2) NOT NULL,
  PRIMARY KEY (id),
  KEY ix_civa_compra (compra_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS compras_tributos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  compra_id     INT UNSIGNED NOT NULL,
  tipo          VARCHAR(4) NOT NULL COMMENT 'IVA percepción de IVA, IIBB, GAN ganancias, MUN municipal, OTR otro',
  detalle       VARCHAR(60) NOT NULL DEFAULT '' COMMENT 'jurisdicción u observación',
  importe       DECIMAL(14,2) NOT NULL,
  PRIMARY KEY (id),
  KEY ix_ctrib_compra (compra_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS compras_reparto (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  compra_id    INT UNSIGNED NOT NULL,
  centro_id    INT UNSIGNED NOT NULL,
  concepto_id  INT UNSIGNED NOT NULL,
  porcentaje   DECIMAL(6,2) NOT NULL,
  importe      DECIMAL(14,2) NOT NULL COMMENT 'parte del costo de la compra (siempre positivo)',
  PRIMARY KEY (id),
  KEY ix_crep_compra (compra_id),
  KEY ix_crep_centro (centro_id),
  KEY ix_crep_concepto (concepto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS compras_adjuntos (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  compra_id   INT UNSIGNED NOT NULL,
  nombre      VARCHAR(120) NOT NULL,
  mime        VARCHAR(60) NOT NULL,
  tamano      INT UNSIGNED NOT NULL,
  contenido   MEDIUMBLOB NOT NULL,
  creado_en   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_cadj_compra (compra_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pagos de compras (la entrega B suma las órdenes de pago). El efectivo es un egreso en caja_movimientos del turno de quien paga.
CREATE TABLE IF NOT EXISTS compras_pagos (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  compra_id           INT UNSIGNED NOT NULL,
  fecha               DATE NOT NULL,
  medio               CHAR(1) NOT NULL COMMENT 'E efectivo de la caja, T transferencia, O otro',
  importe             DECIMAL(14,2) NOT NULL,
  detalle             VARCHAR(120) NOT NULL DEFAULT '',
  caja_movimiento_id  INT UNSIGNED NULL,
  usuario_id          INT UNSIGNED NULL,
  creado_en           DATETIME NOT NULL,
  anulado             TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_cpago_compra (compra_id),
  KEY ix_cpago_caja (caja_movimiento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Datos de fábrica: el centro General, cinco centros de ejemplo y los conceptos habituales (cada cliente los renombra o desactiva).
INSERT INTO centros_costo (nombre, general)
  SELECT 'General', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM centros_costo);
INSERT INTO centros_costo (nombre)
  SELECT x.nombre FROM (SELECT 'Local / Ventas' AS nombre UNION ALL SELECT 'Depósito y logística' UNION ALL SELECT 'Administración'
    UNION ALL SELECT 'Taller / Producción' UNION ALL SELECT 'Marketing') x
  WHERE (SELECT COUNT(*) FROM centros_costo) = 1;
INSERT IGNORE INTO conceptos_gasto (nombre) VALUES
  ('Mercadería para reventa'), ('Materias primas e insumos'), ('Alquiler'), ('Servicios (luz, gas, agua, internet)'), ('Sueldos y cargas sociales'),
  ('Honorarios'), ('Fletes y envíos'), ('Publicidad'), ('Mantenimiento y reparaciones'), ('Impuestos y tasas'), ('Gastos bancarios'),
  ('Librería y oficina'), ('Combustible y movilidad'), ('Otros gastos');

INSERT IGNORE INTO permisos (codigo, modulo, descripcion) VALUES
  ('compras.ver', 'compras', 'Compras: tablero, compras cargadas y listados de gastos'),
  ('compras.cargar', 'compras', 'Compras: cargar y anular compras y gastos, alta de proveedores'),
  ('compras.pagar', 'compras', 'Compras: pagar compras a cuenta corriente'),
  ('compras.centros', 'compras', 'Compras: centros de costos, presupuestos, conceptos y plantillas de reparto');
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN ('compras.ver', 'compras.cargar', 'compras.pagar', 'compras.centros') WHERE r.es_admin = 1 OR r.es_master = 1;
