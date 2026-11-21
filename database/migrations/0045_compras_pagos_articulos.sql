-- Compras, entrega B (0.45.0): órdenes de pago a proveedores (varias facturas, notas de crédito y anticipos; efectivo de la caja, transferencia,
-- cheques de la cartera y retenciones) y compras con artículos que suman stock y actualizan el costo.
--   Todo aditivo: tablas nuevas, ninguna columna nueva en tablas existentes.

-- Numeradores propios de compras (una fila por clave; se bloquea con FOR UPDATE al numerar).
CREATE TABLE IF NOT EXISTS compras_numeradores (
  clave   VARCHAR(10) NOT NULL,
  ultimo  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO compras_numeradores (clave, ultimo) VALUES ('OP', 0);

CREATE TABLE IF NOT EXISTS ordenes_pago (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  numero            INT UNSIGNED NOT NULL,
  proveedor_id      INT UNSIGNED NOT NULL,
  fecha             DATE NOT NULL,
  debitos           DECIMAL(16,2) NOT NULL DEFAULT 0 COMMENT 'facturas, notas de débito y gastos que cancela',
  creditos          DECIMAL(16,2) NOT NULL DEFAULT 0 COMMENT 'notas de crédito y anticipos que descuenta',
  retenciones       DECIMAL(16,2) NOT NULL DEFAULT 0 COMMENT 'retenciones practicadas (parte del pago)',
  pagado            DECIMAL(16,2) NOT NULL DEFAULT 0 COMMENT 'efectivo + transferencias + cheques + retenciones',
  anticipo          DECIMAL(16,2) NOT NULL DEFAULT 0 COMMENT 'lo pagado de más: queda a favor para la próxima orden',
  anticipo_saldo    DECIMAL(16,2) NOT NULL DEFAULT 0 COMMENT 'parte del anticipo que todavía no se usó',
  observaciones     VARCHAR(255) NOT NULL DEFAULT '',
  estado            CHAR(1) NOT NULL DEFAULT 'V' COMMENT 'V vigente, A anulada',
  usuario_id        INT UNSIGNED NULL,
  creado_en         DATETIME NOT NULL,
  operacion         CHAR(32) NULL COMMENT 'clave contra el doble envío',
  anulada_en        DATETIME NULL,
  anulada_por       INT UNSIGNED NULL,
  motivo_anulacion  VARCHAR(160) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_op_numero (numero),
  UNIQUE KEY uq_op_operacion (operacion),
  KEY ix_op_proveedor (proveedor_id, fecha),
  KEY ix_op_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Qué cancela cada orden: compras (débitos con signo +, notas de crédito con signo −) o anticipos de órdenes anteriores.
CREATE TABLE IF NOT EXISTS ordenes_pago_imputaciones (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  orden_id        INT UNSIGNED NOT NULL,
  compra_id       INT UNSIGNED NULL,
  anticipo_de     INT UNSIGNED NULL COMMENT 'ordenes_pago.id cuyo anticipo se usa',
  importe         DECIMAL(16,2) NOT NULL COMMENT 'siempre positivo',
  PRIMARY KEY (id),
  KEY ix_opi_orden (orden_id),
  KEY ix_opi_compra (compra_id),
  KEY ix_opi_anticipo (anticipo_de)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Con qué se paga: E efectivo de la caja, T transferencia, Q cheque de terceros de la cartera, P cheque propio, R retención, O otro medio.
CREATE TABLE IF NOT EXISTS ordenes_pago_medios (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  orden_id            INT UNSIGNED NOT NULL,
  medio               CHAR(1) NOT NULL,
  importe             DECIMAL(16,2) NOT NULL,
  detalle             VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'banco y cuenta, CBU o alias, observación',
  numero              VARCHAR(30) NOT NULL DEFAULT '' COMMENT 'número de cheque o de certificado de retención',
  vence               DATE NULL COMMENT 'fecha de pago del cheque',
  retencion           VARCHAR(4) NOT NULL DEFAULT '' COMMENT 'GAN, IVA, IIBB o SUSS',
  cheque_caja_id      INT UNSIGNED NULL COMMENT 'caja.fe_clave del cheque de la cartera entregado',
  caja_movimiento_id  INT UNSIGNED NULL COMMENT 'caja_movimientos.id del efectivo',
  PRIMARY KEY (id),
  KEY ix_opm_orden (orden_id),
  KEY ix_opm_cheque (cheque_caja_id),
  KEY ix_opm_caja (caja_movimiento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Renglones de una compra con artículos: suman (o restan, la nota de crédito) stock en el depósito elegido y actualizan articulos.costo.
CREATE TABLE IF NOT EXISTS compras_items (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  compra_id        INT UNSIGNED NOT NULL,
  articulo         VARCHAR(60) NOT NULL,
  descripcion      VARCHAR(120) NOT NULL DEFAULT '',
  cantidad         DECIMAL(14,3) NOT NULL,
  costo_unitario   DECIMAL(16,4) NOT NULL COMMENT 'sin IVA si el comprobante discrimina; con IVA si no',
  total            DECIMAL(16,2) NOT NULL,
  deposito_id      INT UNSIGNED NULL,
  costo_anterior   DECIMAL(16,4) NULL COMMENT 'articulos.costo antes de esta compra (NULL si no se actualizó)',
  stock_mov_id     INT UNSIGNED NULL COMMENT 'stock_movimientos.id (NULL si el artículo no controla stock)',
  PRIMARY KEY (id),
  KEY ix_citems_compra (compra_id),
  KEY ix_citems_articulo (articulo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
