-- Contraseñas reseteadas desde el master (0.59.0, D-142…D-144).
--   Sistema → Instalaciones de clientes → Administrar → Usuarios: el Master de O&S ve los usuarios de la base de un cliente y le genera
--   una contraseña temporal a uno (queda con debe_cambiar_clave = 1: la cambia al entrar). Acá queda quién lo hizo, a quién, cuándo,
--   desde qué IP y por qué ticket. La contraseña nunca se guarda. La tabla vive en la base del master (en las de los clientes queda vacía).
CREATE TABLE IF NOT EXISTS instancias_resets (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  instancia       VARCHAR(190) NOT NULL COMMENT 'Dirección de la instalación del cliente',
  usuario_id      INT UNSIGNED NOT NULL COMMENT 'usuarios.id en la base del cliente',
  usuario         VARCHAR(40)  NOT NULL,
  usuario_nombre  VARCHAR(80)  NOT NULL DEFAULT '',
  reactivado      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = estaba inactivo y se activó',
  ticket_id       INT UNSIGNED NULL COMMENT 'soporte_tickets.id del pedido (opcional)',
  motivo          VARCHAR(200) NOT NULL DEFAULT '',
  por_id          INT UNSIGNED NULL COMMENT 'usuarios.id del master que lo hizo',
  por             VARCHAR(40)  NOT NULL,
  ip              VARCHAR(45)  NOT NULL DEFAULT '',
  creado_en       DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_instancias_resets_instancia (instancia, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
