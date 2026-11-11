-- Las 12 tablas del menú Tablas del VFP (menu1), con los nombres de tabla y campo del esquema O&S
-- (los nombres largos de datos.dbc), para que la migración y el módulo afip_fe trabajen casi 1 a 1.
-- Cada tabla lleva un id propio (lo usa la web) y su código O&S único. Los códigos se comparan
-- exactos (utf8mb4_bin): en el VFP conviven, por ejemplo, 'SE' y 'SÑ'. Idempotente.

-- TA Sucursales: puntos de venta y numeradores.
CREATE TABLE IF NOT EXISTS sucursales (
  id                SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo            SMALLINT UNSIGNED NOT NULL,
  descripcion       VARCHAR(30)  NOT NULL,
  direccion         VARCHAR(60)  NULL,
  prefijo           SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Punto de venta',
  ultima_factura_a  INT UNSIGNED NOT NULL DEFAULT 0,
  ultima_factura_b  INT UNSIGNED NOT NULL DEFAULT 0,
  ultima_factura_e  INT UNSIGNED NOT NULL DEFAULT 0,
  ultima_nc_a       INT UNSIGNED NOT NULL DEFAULT 0,
  ultima_nc_b       INT UNSIGNED NOT NULL DEFAULT 0,
  ultima_nd_a       INT UNSIGNED NOT NULL DEFAULT 0,
  ultima_nd_b       INT UNSIGNED NOT NULL DEFAULT 0,
  ultimo_recibo     INT UNSIGNED NOT NULL DEFAULT 0,
  ultimo_remito     INT UNSIGNED NOT NULL DEFAULT 0,
  iva_1             DECIMAL(5,2) NOT NULL DEFAULT 0,
  iva_2             DECIMAL(5,2) NOT NULL DEFAULT 0,
  copias_fac        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  fac_electronica   TINYINT(1)   NOT NULL DEFAULT 0,
  emailenvio        VARCHAR(100) NULL,
  actualizacion     DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sucursales_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TB Rubros
CREATE TABLE IF NOT EXISTS rubros (
  id                   SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo               VARCHAR(3) COLLATE utf8mb4_bin NOT NULL,
  descripcion          VARCHAR(30)  NOT NULL,
  porcentaje_rubro_l1  DECIMAL(5,2) NOT NULL DEFAULT 0,
  porcentaje_rubro_l2  DECIMAL(5,2) NOT NULL DEFAULT 0,
  porcentaje_rubro_l3  DECIMAL(5,2) NOT NULL DEFAULT 0,
  actualizacion        DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rubros_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TC Subrubros (el código se repite entre rubros)
CREATE TABLE IF NOT EXISTS subrubros (
  id                      SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rubro                   VARCHAR(3) COLLATE utf8mb4_bin NOT NULL,
  codigo                  VARCHAR(3) COLLATE utf8mb4_bin NOT NULL,
  descripcion             VARCHAR(30)  NOT NULL,
  porcentaje_subrubro_l1  DECIMAL(5,2) NOT NULL DEFAULT 0,
  porcentaje_subrubro_l2  DECIMAL(5,2) NOT NULL DEFAULT 0,
  porcentaje_subrubro_l3  DECIMAL(5,2) NOT NULL DEFAULT 0,
  actualizacion           DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subrubros (rubro, codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TD Provincias
CREATE TABLE IF NOT EXISTS provincias (
  id           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo       VARCHAR(2) COLLATE utf8mb4_bin NOT NULL,
  descripcion  VARCHAR(30) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_provincias_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TE Parámetros: una sola fila con los datos de la empresa emisora. Del parametros.dbf del VFP se traen
-- los campos que siguen sirviendo (las rutas de Windows, backups y producción textil no).
CREATE TABLE IF NOT EXISTS parametros (
  id                  TINYINT UNSIGNED NOT NULL,
  empresa             VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'Razón social',
  cuit                BIGINT UNSIGNED NULL,
  iva                 TINYINT UNSIGNED NULL COMMENT 'Condición de IVA de la empresa (1 Inscripto, 7 Monotributo, 4 Exento...)',
  tk_direccion        VARCHAR(100) NULL COMMENT 'Domicilio comercial impreso',
  tk_telefono         VARCHAR(40)  NULL,
  tk_iva              VARCHAR(40)  NULL COMMENT 'Condición de IVA impresa',
  tk_i_brutos         VARCHAR(20)  NULL COMMENT 'Ingresos brutos',
  inicio_actividades  DATE         NULL COMMENT 'Agregado en la web (va impreso en la factura)',
  mail                VARCHAR(100) NULL,
  ultimo_cliente      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Último código de cliente asignado',
  ultimo_recibo       INT UNSIGNED NOT NULL DEFAULT 0,
  por_impuesto        DECIMAL(5,2) NOT NULL DEFAULT 0,
  actualizacion       DATETIME     NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO parametros (id) VALUES (1);

-- TF Bancos
CREATE TABLE IF NOT EXISTS bancos (
  id             SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo         SMALLINT UNSIGNED NOT NULL,
  descripcion    VARCHAR(30) NOT NULL,
  actualizacion  DATETIME    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bancos_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TG Cotizaciones del dólar
CREATE TABLE IF NOT EXISTS cotizacion_dolar (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  fecha          DATE          NOT NULL,
  importe        DECIMAL(12,4) NOT NULL,
  observaciones  VARCHAR(50)   NULL,
  actualizacion  DATETIME      NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cotizacion_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TI Vendedores
CREATE TABLE IF NOT EXISTS vendedores (
  id           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo       VARCHAR(2) COLLATE utf8mb4_bin NOT NULL,
  descripcion  VARCHAR(30)  NOT NULL,
  comision_1   DECIMAL(5,2) NOT NULL DEFAULT 0,
  comision_2   DECIMAL(5,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vendedores_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TJ Alícuotas de IVA
CREATE TABLE IF NOT EXISTS tasas_iva (
  id             SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo         VARCHAR(2) COLLATE utf8mb4_bin NOT NULL,
  descripcion    VARCHAR(30)  NOT NULL,
  porcentaje     DECIMAL(5,2) NOT NULL DEFAULT 0,
  actualizacion  DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tasas_iva_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TK Condiciones de pago
CREATE TABLE IF NOT EXISTS condicionpago (
  id           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo       VARCHAR(2) COLLATE utf8mb4_bin NOT NULL,
  descripcion  VARCHAR(30) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_condicionpago_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TL Transportes
CREATE TABLE IF NOT EXISTS transportes (
  id             SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo         SMALLINT UNSIGNED NOT NULL,
  descripcion    VARCHAR(30)  NOT NULL,
  domicilio      VARCHAR(60)  NULL,
  localidad      VARCHAR(40)  NULL,
  codigo_postal  VARCHAR(8)   NULL,
  provincia      VARCHAR(2) COLLATE utf8mb4_bin NULL,
  telefono       VARCHAR(60)  NULL,
  cuit           BIGINT UNSIGNED NULL,
  actualizacion  DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_transportes_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TM Formas de pago. tipo: 1 Efectivo, 2 Tickets, 3 Tarjeta, 4 Dólares, 5 Cheques, 6 Cta. Cte., 7 Ajuste,
-- 8 Nota de crédito, 9 Gastos (combo de forma_de_pagos.scx).
CREATE TABLE IF NOT EXISTS forma_de_pagos (
  id             SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo         VARCHAR(2) COLLATE utf8mb4_bin NOT NULL,
  descripcion    VARCHAR(30)  NOT NULL,
  tipo           TINYINT UNSIGNED NOT NULL DEFAULT 1,
  recargo        DECIMAL(6,2) NOT NULL DEFAULT 0,
  cta_contable   SMALLINT UNSIGNED NULL,
  actualizacion  DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_forma_de_pagos_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Unidades de venta de los artículos (unidad_venta.dbf). No está en el menú Tablas del VFP: se migra y se usa
-- en el combo del artículo.
CREATE TABLE IF NOT EXISTS unidad_venta (
  id             SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo         VARCHAR(2) COLLATE utf8mb4_bin NOT NULL,
  descripcion    VARCHAR(40) NOT NULL,
  actualizacion  DATETIME    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_unidad_venta_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO unidad_venta (codigo, descripcion) VALUES ('01', 'UNIDAD');

-- Valores iniciales para una instalación nueva (la migración desde el VFP los reemplaza).
INSERT IGNORE INTO tasas_iva (codigo, descripcion, porcentaje) VALUES
  ('21', 'ALICUOTA 21 %', 21.00), ('10', 'ALICUOTA 10,5 %', 10.50), ('27', 'ALICUOTA 27 %', 27.00),
  ('5', 'ALICUOTA 5 %', 5.00), ('2', 'ALICUOTA 2,5 %', 2.50), ('0', 'SIN IVA', 0.00);
INSERT IGNORE INTO provincias (codigo, descripcion) VALUES
  ('01', 'SANTIAGO DEL ESTERO'), ('02', 'BUENOS AIRES'), ('03', 'CORDOBA'), ('04', 'SANTA FE'), ('05', 'SALTA'),
  ('06', 'SAN LUIS'), ('07', 'MENDOZA'), ('08', 'JUJUY'), ('09', 'CHUBUT'), ('10', 'SANTA CRUZ'), ('11', 'RIO NEGRO'),
  ('12', 'NEUQUEN'), ('13', 'SAN JUAN'), ('14', 'LA RIOJA'), ('15', 'TUCUMAN'), ('16', 'FORMOSA'), ('17', 'CHACO'),
  ('18', 'ENTRE RIOS'), ('19', 'MISIONES'), ('20', 'CORRIENTES'), ('21', 'LA PAMPA'), ('22', 'CATAMARCA'),
  ('23', 'CAPITAL FEDERAL'), ('24', 'TIERRA DEL FUEGO');
