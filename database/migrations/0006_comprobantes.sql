-- Etapa 2: comprobantes de venta con el esquema O&S (subdiario, iva, estadistica, caja, ctact) y sus equivalencias de ARCA.
-- Los nombres de tabla y campo son los de datos.dbc. En el VFP cada tabla tiene su propio número interno (fe_clave) y se
-- relacionan por el comprobante: tipo + letra + sucursal + número. Lo que agrega la web está marcado como "web".

-- Numeradores de las facturas, notas de crédito y débito C (el VFP solo tenía A, B y E).
ALTER TABLE sucursales
  ADD COLUMN ultima_factura_c INT UNSIGNED NOT NULL DEFAULT 0 AFTER ultima_factura_b,
  ADD COLUMN ultima_nc_c INT UNSIGNED NOT NULL DEFAULT 0 AFTER ultima_nc_b,
  ADD COLUMN ultima_nd_c INT UNSIGNED NOT NULL DEFAULT 0 AFTER ultima_nd_b;

-- Cabecera de cada comprobante. Importes: bruto = renglones sin IVA antes del descuento; bonificacion = descuento;
-- neto_grabado / neto_exento = lo que queda; iva_1 = IVA total; neto = TOTAL del comprobante (con IVA).
CREATE TABLE IF NOT EXISTS subdiario (
  fe_clave              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  fecha                 DATE NOT NULL,
  tipo                  VARCHAR(2) COLLATE utf8mb4_bin NOT NULL COMMENT 'FC, NC, ND',
  letra                 CHAR(1) COLLATE utf8mb4_bin NOT NULL COMMENT 'A, B, C (M más adelante)',
  sucursal              SMALLINT UNSIGNED NOT NULL,
  prefijo               SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Punto de venta',
  comprobante           INT UNSIGNED NOT NULL,
  cliente               INT UNSIGNED NOT NULL,
  bruto                 DECIMAL(14,2) NOT NULL DEFAULT 0,
  neto_grabado          DECIMAL(14,2) NOT NULL DEFAULT 0,
  neto_exento           DECIMAL(14,2) NOT NULL DEFAULT 0,
  iva_1                 DECIMAL(14,2) NOT NULL DEFAULT 0,
  iva_2                 DECIMAL(14,2) NOT NULL DEFAULT 0,
  liberado              DECIMAL(14,2) NOT NULL DEFAULT 0,
  bonificacion          DECIMAL(14,2) NOT NULL DEFAULT 0,
  neto                  DECIMAL(14,2) NOT NULL DEFAULT 0,
  porcentaje_descuento  DECIMAL(6,2) NOT NULL DEFAULT 0,
  estado                CHAR(1) NOT NULL DEFAULT '' COMMENT 'Vacío = vigente',
  observaciones         VARCHAR(255) NULL,
  canal                 CHAR(1) NULL,
  terminal              SMALLINT UNSIGNED NULL,
  iva_cliente           TINYINT UNSIGNED NULL COMMENT 'Condición de IVA del cliente al emitir (código VFP de clientes.iva)',
  cuit_cliente          BIGINT UNSIGNED NULL,
  recargo_f_pago        DECIMAL(14,2) NOT NULL DEFAULT 0,
  impuesto              DECIMAL(6,2) NOT NULL DEFAULT 0,
  recibo                INT UNSIGNED NOT NULL DEFAULT 0,
  mvstk                 INT UNSIGNED NOT NULL DEFAULT 0,
  cobro                 DATE NULL,
  vendedor              VARCHAR(2) COLLATE utf8mb4_bin NULL,
  forma_pago            TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '1 Contado, 2 Cuenta corriente',
  cond_pago             VARCHAR(2) COLLATE utf8mb4_bin NULL,
  orden_compra          VARCHAR(20) NULL,
  actualizacion         DATETIME NULL,
  nrointerno            INT UNSIGNED NOT NULL DEFAULT 0,
  electronica           TINYINT(1) NOT NULL DEFAULT 0,
  tipoafipoys           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  fe_nrocbte            INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Número que dio ARCA',
  fe_ccae               VARCHAR(30) NOT NULL DEFAULT '',
  fe_dfecha             DATE NULL,
  fe_dvtocae            DATE NULL,
  fe_ntipoafip          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  fe_lrechazado         TINYINT(1) NOT NULL DEFAULT 0,
  fe_mobservaciones     TEXT NULL,
  fe_limprimio          TINYINT(1) NOT NULL DEFAULT 0,
  fe_cemailenvio        VARCHAR(255) NULL,
  fe_dfechaenvio        DATE NULL,
  fe_vencimiento        DATE NULL,
  fe_tipocbte_asoc      VARCHAR(3) NOT NULL DEFAULT '' COMMENT 'Comprobante asociado de una NC/ND: tipo',
  fe_nrocbte_asoc       INT UNSIGNED NOT NULL DEFAULT 0,
  fe_fecha_asoc         DATE NULL,
  -- web: copia de los datos del cliente al emitir (editar el cliente después no cambia el comprobante)
  cliente_razon_social  VARCHAR(100) NULL,
  cliente_domicilio     VARCHAR(80) NULL,
  cliente_localidad     VARCHAR(40) NULL,
  -- web: quién lo emitió y la clave de la operación (contra el doble envío)
  creado_por            INT UNSIGNED NULL,
  creado_en             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  operacion             CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  PRIMARY KEY (fe_clave),
  UNIQUE KEY uq_subdiario_comprobante (tipo, letra, sucursal, comprobante),
  UNIQUE KEY uq_subdiario_operacion (operacion),
  KEY ix_subdiario_fecha (fecha),
  KEY ix_subdiario_cliente (cliente, fecha),
  KEY ix_subdiario_cae (fe_ccae)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- IVA de cada comprobante por alícuota: monto = base, importe = IVA.
CREATE TABLE IF NOT EXISTS iva (
  fe_clave       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tipo           VARCHAR(2) COLLATE utf8mb4_bin NOT NULL,
  letra          CHAR(1) COLLATE utf8mb4_bin NOT NULL,
  sucursal       SMALLINT UNSIGNED NOT NULL,
  comprobante    INT UNSIGNED NOT NULL,
  alicuota       DECIMAL(5,2) NOT NULL,
  monto          DECIMAL(14,2) NOT NULL DEFAULT 0,
  importe        DECIMAL(14,2) NOT NULL DEFAULT 0,
  cierre_z       INT UNSIGNED NOT NULL DEFAULT 0,
  actualizacion  DATETIME NULL,
  PRIMARY KEY (fe_clave),
  UNIQUE KEY uq_iva (tipo, letra, sucursal, comprobante, alicuota)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Renglones (el VFP los llama "estadística"). Convención de la web: precio y total_item son los que se ven en el
-- comprobante (en la A neto, en la B y C con IVA incluido); precio_siniva y total_siniva son netos de IVA y ya con el
-- descuento general aplicado. orden = posición del renglón (agregado en la web).
CREATE TABLE IF NOT EXISTS estadistica (
  fe_clave              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  fecha                 DATE NOT NULL,
  tipo                  VARCHAR(2) COLLATE utf8mb4_bin NOT NULL,
  letra                 CHAR(1) COLLATE utf8mb4_bin NOT NULL,
  sucursal              SMALLINT UNSIGNED NOT NULL,
  prefijo               SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  comprobante           INT UNSIGNED NOT NULL,
  orden                 SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  pedido                INT UNSIGNED NOT NULL DEFAULT 0,
  articulo              VARCHAR(15) NULL COMMENT 'Código del artículo (vacío en un renglón libre)',
  codigo_de_barras      VARCHAR(20) NULL,
  bultos                SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  unidades              DECIMAL(14,3) NOT NULL DEFAULT 0,
  unidades_ori          DECIMAL(14,3) NOT NULL DEFAULT 0,
  merma                 DECIMAL(14,3) NOT NULL DEFAULT 0,
  lista                 VARCHAR(3) NULL,
  precio                DECIMAL(15,4) NOT NULL DEFAULT 0,
  precio_siniva         DECIMAL(15,4) NOT NULL DEFAULT 0,
  costo                 DECIMAL(15,4) NOT NULL DEFAULT 0,
  porcentaje_descuento  DECIMAL(6,2) NOT NULL DEFAULT 0,
  observaciones         VARCHAR(255) NULL COMMENT 'Descripción que se imprime',
  total_item            DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_siniva          DECIMAL(14,2) NOT NULL DEFAULT 0,
  cliente               INT UNSIGNED NOT NULL DEFAULT 0,
  grabado               TINYINT(1) NOT NULL DEFAULT 1,
  por_iva               DECIMAL(5,2) NOT NULL DEFAULT 0,
  actualizacion         DATETIME NULL,
  PRIMARY KEY (fe_clave),
  KEY ix_estadistica_comprobante (tipo, letra, sucursal, comprobante, orden),
  KEY ix_estadistica_articulo (articulo, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cómo se paga cada comprobante (en la etapa 4 se suman cheques y cobranzas).
CREATE TABLE IF NOT EXISTS caja (
  fe_clave       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  fecha          DATE NOT NULL,
  tipo           VARCHAR(2) COLLATE utf8mb4_bin NOT NULL,
  letra          CHAR(1) COLLATE utf8mb4_bin NOT NULL,
  canal          CHAR(1) NULL,
  terminal       SMALLINT UNSIGNED NULL,
  sucursal       SMALLINT UNSIGNED NOT NULL,
  prefijo        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  comprobante    INT UNSIGNED NOT NULL,
  cliente        INT UNSIGNED NOT NULL DEFAULT 0,
  p_fpago        VARCHAR(2) COLLATE utf8mb4_bin NOT NULL COMMENT 'forma_de_pagos.codigo',
  p_cuotas       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  p_nro          VARCHAR(20) NULL,
  p_fecvto       DATE NULL,
  p_banco        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  p_cotiz        DECIMAL(12,4) NOT NULL DEFAULT 0,
  p_importe      DECIMAL(14,2) NOT NULL DEFAULT 0,
  a_pagar        DECIMAL(14,2) NOT NULL DEFAULT 0,
  actualizacion  DATETIME NULL,
  PRIMARY KEY (fe_clave),
  KEY ix_caja_comprobante (tipo, letra, sucursal, comprobante),
  KEY ix_caja_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cuenta corriente: una fila por comprobante a cuenta (tico: 1 factura, 2 nota de débito, 8 nota de crédito). Los recibos,
-- ajustes e imputaciones son de la etapa 4.
CREATE TABLE IF NOT EXISTS ctact (
  fe_clave       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cliente        INT UNSIGNED NOT NULL,
  fecha          DATE NOT NULL,
  tipo           VARCHAR(2) COLLATE utf8mb4_bin NOT NULL,
  tico           TINYINT UNSIGNED NOT NULL,
  letra          CHAR(1) COLLATE utf8mb4_bin NOT NULL,
  sucursal       SMALLINT UNSIGNED NOT NULL,
  prefijo        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  numero         INT UNSIGNED NOT NULL,
  neto           DECIMAL(14,2) NOT NULL DEFAULT 0,
  descuento      DECIMAL(14,2) NOT NULL DEFAULT 0,
  saldo          DECIMAL(14,2) NOT NULL DEFAULT 0,
  vencimiento    DATE NULL,
  valor_dolar    DECIMAL(12,4) NOT NULL DEFAULT 1,
  actualizacion  DATETIME NULL,
  PRIMARY KEY (fe_clave),
  KEY ix_ctact_cliente (cliente, fecha),
  KEY ix_ctact_comprobante (tipo, letra, sucursal, numero)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Equivalencias entre los códigos del sistema y los de ARCA (comprobante, IVA, documento, moneda...). La condición de IVA del
-- receptor va con tabla = 'Equivaliencia' (así la escribe el módulo afip_fe): codigooys = clientes.iva, codigoafip = Id de ARCA.
CREATE TABLE IF NOT EXISTS equivalencias_afip (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tabla        VARCHAR(30) NOT NULL,
  codigoafip   VARCHAR(3) NOT NULL,
  codigooys    VARCHAR(3) NOT NULL DEFAULT '',
  descripcion  VARCHAR(40) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_equivalencias (tabla, codigoafip, codigooys)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Equivalencias de la condición de IVA aprobadas por Hernán (11/11/2026). "2 No Inscripto" ya no existe en ARCA: no se carga.
INSERT IGNORE INTO equivalencias_afip (tabla, codigoafip, codigooys, descripcion) VALUES
  ('Equivaliencia', '1',  '1', 'Inscripto'),
  ('Equivaliencia', '5',  '3', 'Consumidor Final'),
  ('Equivaliencia', '4',  '4', 'Exento (B)'),
  ('Equivaliencia', '4',  '5', 'Exento (A)'),
  ('Equivaliencia', '10', '6', 'Liberado'),
  ('Equivaliencia', '6',  '7', 'Monotributo'),
  ('Equivaliencia', '4',  '8', 'Exento Especial (A)'),
  ('Equivaliencia', '4',  '9', 'Exento Especial (B)');

-- Permisos de la etapa 2: una opción del menú del VFP cada uno (CB facturación, CD consulta de comprobantes).
INSERT IGNORE INTO permisos (codigo, modulo, descripcion) VALUES
  ('comprobantes.ver',      'facturacion', 'Consultar e imprimir comprobantes (VFP CD)'),
  ('comprobantes.emitir',   'facturacion', 'Emitir facturas, notas de crédito y débito (VFP CB)'),
  ('comprobantes.credito',  'facturacion', 'Autorizar un comprobante que excede el crédito del cliente');
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo LIKE 'comprobantes.%' WHERE r.es_admin = 1 OR r.es_master = 1;
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN ('comprobantes.ver', 'comprobantes.emitir') WHERE r.codigo = 'operador';
