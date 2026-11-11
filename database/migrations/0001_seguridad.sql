-- Seguridad: usuarios, roles, permisos, sesiones y "recordarme". Base: núcleo de oesteparts, sin empresas
-- (una instalación por cliente: cada usuario tiene un solo rol). Idempotente.

CREATE TABLE IF NOT EXISTS roles (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo      VARCHAR(30)  NOT NULL,
  nombre      VARCHAR(60)  NOT NULL,
  es_sistema  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = no se puede borrar',
  asignable   TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = el Administrador del cliente no puede asignarlo (Master)',
  es_admin    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = todos los permisos menos los del Master',
  es_master   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = todos los permisos; solo un Master lo ve y lo asigna',
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_codigo (codigo),
  UNIQUE KEY uq_roles_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permisos (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo      VARCHAR(60)  NOT NULL,
  modulo      VARCHAR(30)  NOT NULL,
  descripcion VARCHAR(150) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permisos_codigo (codigo),
  KEY ix_permisos_modulo (modulo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rol_permisos (
  rol_id      SMALLINT UNSIGNED NOT NULL,
  permiso_id  SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (rol_id, permiso_id),
  KEY ix_rol_permisos_permiso (permiso_id),
  CONSTRAINT fk_rp_rol     FOREIGN KEY (rol_id)     REFERENCES roles(id)    ON DELETE CASCADE,
  CONSTRAINT fk_rp_permiso FOREIGN KEY (permiso_id) REFERENCES permisos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuarios (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario            VARCHAR(40)  NOT NULL,
  nombre             VARCHAR(80)  NOT NULL,
  email              VARCHAR(120) NULL,
  password_hash      VARCHAR(255) NOT NULL COMMENT 'password_hash() de PHP',
  rol_id             SMALLINT UNSIGNED NOT NULL,
  activo             TINYINT(1)   NOT NULL DEFAULT 1,
  debe_cambiar_clave TINYINT(1)   NOT NULL DEFAULT 0,
  intentos_fallidos  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  bloqueado_hasta    DATETIME     NULL,
  ultimo_login       DATETIME     NULL,
  usuario_vfp        VARCHAR(20)  NULL COMMENT 'Nombre en cla_usuarios del sistema VFP (si vino de la migración)',
  creado_en          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_usuario (usuario),
  UNIQUE KEY uq_usuarios_email (email),
  KEY ix_usuarios_rol (rol_id),
  CONSTRAINT fk_usuarios_rol FOREIGN KEY (rol_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sesiones en la base: no dependen de los permisos de carpetas del hosting.
CREATE TABLE IF NOT EXISTS sesiones (
  id          VARCHAR(128)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  datos       MEDIUMBLOB    NOT NULL,
  actualizado INT UNSIGNED  NOT NULL COMMENT 'Unix timestamp del último uso',
  PRIMARY KEY (id),
  KEY ix_sesiones_actualizado (actualizado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Recordarme en este dispositivo" (30 días): en la base va el sha256 del validador, nunca el validador.
CREATE TABLE IF NOT EXISTS recordarme (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id  INT UNSIGNED NOT NULL,
  selector    CHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  validador   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  agente      VARCHAR(200) NULL,
  vence_en    DATETIME NOT NULL,
  creado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  usado_en    DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recordarme_selector (selector),
  KEY ix_recordarme_usuario (usuario_id),
  CONSTRAINT fk_recordarme_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configuración del sistema web (clave/valor). Distinta de la tabla "parametros" de O&S (datos de la empresa).
CREATE TABLE IF NOT EXISTS configuracion (
  clave           VARCHAR(60)  NOT NULL,
  valor           VARCHAR(255) NOT NULL,
  tipo            ENUM('entero','texto','si_no') NOT NULL DEFAULT 'texto',
  descripcion     VARCHAR(255) NOT NULL,
  actualizado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  actualizado_por INT UNSIGNED NULL,
  PRIMARY KEY (clave),
  KEY ix_config_usuario (actualizado_por),
  CONSTRAINT fk_config_usuario FOREIGN KEY (actualizado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO configuracion (clave, valor, tipo, descripcion) VALUES
  ('grillas.por_pagina', '50', 'entero', 'Renglones por página en las grillas de clientes y artículos (entre 10 y 500)');

INSERT IGNORE INTO roles (codigo, nombre, es_sistema, asignable, es_admin, es_master) VALUES
  ('master',   'Master (O&S)',  1, 0, 0, 1),
  ('admin',    'Administrador', 1, 1, 1, 0),
  ('operador', 'Operador',      1, 1, 0, 0);

-- Permisos: uno por opción del menú del VFP (cla_opciones) y las acciones del sistema web.
INSERT IGNORE INTO permisos (codigo, modulo, descripcion) VALUES
  ('usuarios.administrar',       'seguridad', 'Usuarios y roles'),
  ('configuracion.administrar',  'seguridad', 'Configuración del sistema web'),
  ('tablas.sucursales',          'tablas',    'Sucursales y puntos de venta (VFP TA)'),
  ('tablas.rubros',              'tablas',    'Rubros (VFP TB)'),
  ('tablas.subrubros',           'tablas',    'Subrubros (VFP TC)'),
  ('tablas.provincias',          'tablas',    'Provincias (VFP TD)'),
  ('tablas.parametros',          'tablas',    'Parámetros: datos de la empresa (VFP TE)'),
  ('tablas.bancos',              'tablas',    'Bancos (VFP TF)'),
  ('tablas.cotizacion_dolar',    'tablas',    'Cotizaciones del dólar (VFP TG)'),
  ('tablas.vendedores',          'tablas',    'Vendedores (VFP TI)'),
  ('tablas.tasas_iva',           'tablas',    'Alícuotas de IVA (VFP TJ)'),
  ('tablas.condicionpago',       'tablas',    'Condiciones de pago (VFP TK)'),
  ('tablas.transportes',         'tablas',    'Transportes (VFP TL)'),
  ('tablas.forma_de_pagos',      'tablas',    'Formas de pago (VFP TM)'),
  ('clientes.ver',               'maestros',  'Consultar clientes (VFP MA)'),
  ('clientes.editar',            'maestros',  'Alta y modificación de clientes'),
  ('articulos.ver',              'maestros',  'Consultar artículos (VFP MB)'),
  ('articulos.editar',           'maestros',  'Alta y modificación de artículos');

-- Master y Administrador: todo. Operador: maestros y tablas, menos sucursales y parámetros de la empresa.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r, permisos p WHERE r.codigo IN ('master', 'admin');
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r JOIN permisos p
   WHERE r.codigo = 'operador'
     AND (p.modulo = 'maestros' OR (p.modulo = 'tablas' AND p.codigo NOT IN ('tablas.sucursales', 'tablas.parametros')));
