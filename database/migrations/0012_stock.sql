-- Stock (opcional por instalación: Configuración → stock.controlar). Modelo: movimientos (la historia, la verdad) + saldos (caché que se
-- actualiza en la MISMA transacción que cada movimiento, así consultar el stock es leer una fila y no sumar todo el historial).
-- Todo en UNIDADES; los bultos se convierten con articulos.unidades_por_bulto al cargar.
CREATE TABLE IF NOT EXISTS depositos (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo     VARCHAR(10) COLLATE utf8mb4_bin NOT NULL,
  nombre     VARCHAR(60) NOT NULL,
  principal  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Del que descuentan las facturas',
  activo     TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_depositos_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO depositos (codigo, nombre, principal) VALUES ('PRINC', 'Depósito principal', 1);

CREATE TABLE IF NOT EXISTS stock_movimientos (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  articulo     VARCHAR(15) NOT NULL,
  deposito_id  INT UNSIGNED NOT NULL,
  fecha        DATETIME NOT NULL,
  cantidad     DECIMAL(14,3) NOT NULL COMMENT 'Con signo, en unidades: + entra, - sale',
  origen       VARCHAR(12) NOT NULL COMMENT 'FC, NC, INGRESO, EGRESO, AJUSTE, TRANSF',
  subdiario_id INT UNSIGNED NULL,
  grupo        CHAR(32) NULL COMMENT 'Une las dos patas de una transferencia',
  motivo       VARCHAR(255) NULL,
  usuario_id   INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY ix_stockmov_articulo (articulo, deposito_id, fecha),
  KEY ix_stockmov_subdiario (subdiario_id),
  KEY ix_stockmov_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_saldos (
  articulo     VARCHAR(15) NOT NULL,
  deposito_id  INT UNSIGNED NOT NULL,
  saldo        DECIMAL(14,3) NOT NULL DEFAULT 0,
  PRIMARY KEY (articulo, deposito_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Los servicios y cosas que no se cuentan se destildan.
ALTER TABLE articulos ADD COLUMN controla_stock TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Si mueve stock al facturarlo';

INSERT IGNORE INTO configuracion (clave, valor, tipo, descripcion) VALUES
  ('stock.controlar', '0', 'si_no',
   'Controlar stock: cada factura descuenta y cada nota de crédito repone (solo renglones con artículo). Siempre se puede facturar sin stock: avisa y sigue.');

INSERT IGNORE INTO permisos (codigo, modulo, descripcion) VALUES
  ('stock.ver', 'stock', 'Ver saldos y movimientos de stock'),
  ('stock.mover', 'stock', 'Ingresos, egresos, ajustes y transferencias de stock'),
  ('stock.administrar', 'stock', 'Depósitos y recálculo de saldos');
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN ('stock.ver', 'stock.mover', 'stock.administrar') WHERE r.es_admin = 1 OR r.es_master = 1;
