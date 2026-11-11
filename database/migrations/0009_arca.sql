-- Etapa 3: factura electrónica (CAE de ARCA). Lo que agrega la web (no está en el VFP) va marcado.

-- Cada comprobante sabe con qué se autorizó. es_prueba = sin validez fiscal (interno, simulado u homologación): son los que
-- se pueden borrar al pasar a producción (Sistema → ARCA).
ALTER TABLE subdiario
  ADD COLUMN es_prueba TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'web: 1 = sin validez fiscal (interno, simulado u homologación)',
  ADD COLUMN fe_entorno CHAR(1) NOT NULL DEFAULT '' COMMENT 'web: con qué se autorizó: vacío interno, S simulado, H homologación, P producción',
  ADD COLUMN fe_concepto TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'web: 1 productos, 2 servicios, 3 productos y servicios',
  ADD COLUMN fe_serv_desde DATE NULL COMMENT 'web: servicios, inicio del período',
  ADD COLUMN fe_serv_hasta DATE NULL COMMENT 'web: servicios, fin del período';
-- Lo emitido en la etapa 2 (sin CAE) era de prueba.
UPDATE subdiario SET es_prueba = 1 WHERE canal = 'W' AND fe_ccae = '';

-- Registro de cada pedido de CAE: si algo se corta entre que ARCA aprueba y que se graba, acá queda todo para recuperarlo.
-- estado: pendiente (se está pidiendo, o se cortó sin respuesta), aprobada (ARCA dio el CAE y falta grabar), rechazada,
-- grabada, error (ARCA aprobó y no se pudo grabar: ver detalle).
CREATE TABLE IF NOT EXISTS afip_operaciones (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  operacion      CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  estado         VARCHAR(10) NOT NULL DEFAULT 'pendiente',
  entorno        CHAR(1) NOT NULL DEFAULT '',
  tipo_afip      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  pto_vta        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  numero         INT UNSIGNED NULL,
  cae            VARCHAR(30) NULL,
  vence          DATE NULL,
  total          DECIMAL(14,2) NOT NULL DEFAULT 0,
  usuario_id     INT UNSIGNED NULL,
  subdiario_id   INT UNSIGNED NULL,
  solicitud      MEDIUMTEXT NULL COMMENT 'El comprobante armado (JSON): alcanza para grabarlo de nuevo',
  respuesta      MEDIUMTEXT NULL COMMENT 'Lo que contestó ARCA (JSON)',
  detalle        VARCHAR(600) NULL,
  creado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_afip_operaciones_operacion (operacion),
  KEY ix_afip_operaciones_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Decisiones de Claude que Hernán puede cambiar desde Configuración (ver docs/decisiones-pendientes.md).
INSERT IGNORE INTO configuracion (clave, valor, tipo, descripcion) VALUES
  ('comprobantes.nc_limite_factura', '1', 'si_no',
   'D-01 (decisión de Claude, a confirmar): una nota de crédito no puede superar lo que queda por acreditar de la factura que corrige. Destildado = sin control, como el VFP'),
  ('comprobantes.credito_cero_sin_limite', '1', 'si_no',
   'D-02 (decisión de Claude, a confirmar): un cliente con límite de crédito 0 no tiene control de crédito. Destildado = todo comprobante a cuenta corriente de un cliente sin límite pide autorización, como el VFP'),
  ('comprobantes.concepto_defecto', '1', 'entero',
   'D-11 (decisión de Claude, a confirmar): concepto por omisión de los comprobantes electrónicos: 1 productos, 2 servicios, 3 productos y servicios (el VFP mandaba siempre 1)');

-- Permiso de la pantalla Sistema → ARCA (diagnóstico, pendientes y herramientas): la tienen Master y Administrador.
INSERT IGNORE INTO permisos (codigo, modulo, descripcion) VALUES
  ('arca.administrar', 'facturacion', 'Ver el estado de ARCA, probar la conexión y recuperar comprobantes pendientes'),
  ('tablas.equivalencias', 'tablas', 'Equivalencias de ARCA: condición de IVA del cliente (etapa 3)');
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN ('arca.administrar', 'tablas.equivalencias') WHERE r.es_admin = 1 OR r.es_master = 1;
