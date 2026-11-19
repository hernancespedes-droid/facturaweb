-- Caja (0.25.0): turnos con arqueo, ingresos y egresos manuales, auditoría con cadena de control, y hora + usuario en cada movimiento.
--   caja.usuario_id / creado_en / turno_id: quién y cuándo anotó cada cobro (los anteriores quedan sin dato).
--   parametros.caja_tope: tope por movimiento manual (por omisión $ 10.000.000; 0 = sin tope).
--   Permisos: caja.turno (abrir y cerrar mi turno), caja.mover (ingresos y egresos manuales), caja.auditar (auditoría y turnos de todos).
ALTER TABLE caja ADD COLUMN usuario_id INT UNSIGNED NULL COMMENT 'usuarios.id de quien cobró',
  ADD COLUMN creado_en DATETIME NULL COMMENT 'fecha y hora en que se anotó',
  ADD COLUMN turno_id INT UNSIGNED NULL COMMENT 'caja_turnos.id abierto por ese usuario en ese momento',
  ADD KEY ix_caja_turno (turno_id);

ALTER TABLE parametros ADD COLUMN caja_tope DECIMAL(14,2) NOT NULL DEFAULT 10000000.00 COMMENT 'Tope por movimiento manual de caja (0 = sin tope)';

CREATE TABLE IF NOT EXISTS caja_turnos (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id     INT UNSIGNED NOT NULL,
  abierto_en     DATETIME NOT NULL,
  fondo_inicial  DECIMAL(14,2) NOT NULL DEFAULT 0,
  estado         CHAR(1) NOT NULL DEFAULT 'A' COMMENT 'A abierto, C cerrado',
  cerrado_en     DATETIME NULL,
  esperado       DECIMAL(14,2) NULL COMMENT 'efectivo que debía haber al cerrar',
  contado        DECIMAL(14,2) NULL COMMENT 'efectivo contado al cerrar (arqueo)',
  diferencia     DECIMAL(14,2) NULL COMMENT 'contado - esperado',
  observaciones  VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY ix_turno_usuario (usuario_id, estado),
  KEY ix_turno_abierto (abierto_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ingresos y egresos de efectivo que no son ventas ni cobranzas. No se editan ni se borran: se anulan con un contra-asiento (anula_id).
CREATE TABLE IF NOT EXISTS caja_movimientos (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  creado_en   DATETIME NOT NULL,
  usuario_id  INT UNSIGNED NOT NULL,
  turno_id    INT UNSIGNED NULL,
  tipo        CHAR(1) NOT NULL COMMENT 'I ingreso, E egreso',
  importe     DECIMAL(14,2) NOT NULL,
  motivo      VARCHAR(200) NOT NULL,
  anula_id    INT UNSIGNED NULL COMMENT 'movimiento que este contra-asiento anula',
  PRIMARY KEY (id),
  UNIQUE KEY uq_mov_anula (anula_id),
  KEY ix_mov_fecha (creado_en),
  KEY ix_mov_turno (turno_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro de todo lo que se hace en la caja. Cada fila lleva un hash que encadena con la anterior: si alguien toca la tabla, la verificación lo marca.
CREATE TABLE IF NOT EXISTS caja_auditoria (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  creado_en   DATETIME NOT NULL,
  usuario_id  INT UNSIGNED NULL,
  accion      VARCHAR(30) NOT NULL COMMENT 'apertura, cierre, ingreso, egreso, anulacion, rechazo',
  entidad     VARCHAR(20) NOT NULL DEFAULT '',
  entidad_id  INT UNSIGNED NULL,
  detalle     VARCHAR(500) NOT NULL DEFAULT '',
  ip          VARCHAR(45) NOT NULL DEFAULT '',
  hash        CHAR(64) NOT NULL,
  PRIMARY KEY (id),
  KEY ix_aud_fecha (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permisos (codigo, modulo, descripcion) VALUES
  ('caja.turno', 'caja', 'Caja: abrir y cerrar mi turno (arqueo) y ver mis movimientos'),
  ('caja.mover', 'caja', 'Caja: ingresos y egresos manuales de dinero y su anulación'),
  ('caja.auditar', 'caja', 'Caja: auditoría de movimientos y turnos de todos los cajeros');
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN ('caja.turno', 'caja.mover', 'caja.auditar') WHERE r.es_admin = 1 OR r.es_master = 1;
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'caja.turno' WHERE r.codigo = 'operador';
