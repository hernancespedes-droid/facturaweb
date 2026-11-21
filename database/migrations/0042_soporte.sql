-- Tickets de soporte (0.42.0). Viven SOLO en la base del sitio master (oys.orgysis.site): cada instalación de cliente los manda y los
-- lee por la API /api/v1/soporte con su clave (config del cliente: 'soporte' => ['url' => …, 'clave' => …], la escribe el master).
-- En las bases de los clientes estas tablas quedan vacías (la migración corre igual en todas).
--   soporte_tickets.instancia: dirección del cliente (vacía = la propia instalación principal).
--   estado: nuevo, en_curso, esperando (al cliente), resuelto, cerrado. pendiente: 1 = lo último lo escribió el cliente (falta contestar).
--   soporte_mensajes.interno = 1: nota de O&S que el cliente no ve. autor_tipo: C cliente, O O&S.
CREATE TABLE IF NOT EXISTS soporte_tickets (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  instancia        VARCHAR(190) NOT NULL DEFAULT '',
  instancia_nombre VARCHAR(150) NOT NULL DEFAULT '',
  asunto           VARCHAR(150) NOT NULL,
  tipo             VARCHAR(20)  NOT NULL,
  prioridad        VARCHAR(10)  NOT NULL DEFAULT 'normal',
  estado           VARCHAR(20)  NOT NULL DEFAULT 'nuevo',
  pendiente        TINYINT(1)   NOT NULL DEFAULT 1,
  usuario          VARCHAR(100) NOT NULL DEFAULT '',
  usuario_mail     VARCHAR(190) NOT NULL DEFAULT '',
  version          VARCHAR(20)  NOT NULL DEFAULT '',
  pantalla         VARCHAR(255) NOT NULL DEFAULT '',
  creado_en        DATETIME     NOT NULL,
  actualizado_en   DATETIME     NOT NULL,
  resuelto_en      DATETIME     NULL,
  cerrado_en       DATETIME     NULL,
  aviso_error      VARCHAR(255) NULL COMMENT 'Último error al mandar un mail de aviso',
  operacion        VARCHAR(40)  NULL COMMENT 'Clave del formulario: si el cliente reintenta (el master tardó), no se duplica el ticket',
  PRIMARY KEY (id),
  UNIQUE KEY uq_soporte_operacion (instancia, operacion),
  KEY ix_soporte_instancia (instancia, estado),
  KEY ix_soporte_estado (estado, actualizado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS soporte_mensajes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id   INT UNSIGNED NOT NULL,
  autor_tipo  CHAR(1)      NOT NULL COMMENT 'C cliente, O O&S',
  autor       VARCHAR(100) NOT NULL DEFAULT '',
  texto       TEXT         NOT NULL,
  interno     TINYINT(1)   NOT NULL DEFAULT 0,
  creado_en   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_soporte_mensajes_ticket (ticket_id, id),
  CONSTRAINT fk_soporte_mensajes_ticket FOREIGN KEY (ticket_id) REFERENCES soporte_tickets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS soporte_adjuntos (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id   INT UNSIGNED NOT NULL,
  mensaje_id  INT UNSIGNED NOT NULL,
  nombre      VARCHAR(150) NOT NULL,
  tipo        VARCHAR(60)  NOT NULL,
  tamano      INT UNSIGNED NOT NULL,
  datos       MEDIUMBLOB   NOT NULL,
  creado_en   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_soporte_adjuntos_ticket (ticket_id),
  CONSTRAINT fk_soporte_adjuntos_ticket FOREIGN KEY (ticket_id) REFERENCES soporte_tickets (id),
  CONSTRAINT fk_soporte_adjuntos_mensaje FOREIGN KEY (mensaje_id) REFERENCES soporte_mensajes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO configuracion (clave, valor, tipo, descripcion) VALUES
  ('soporte.mail_aviso', '', 'texto', 'Soporte: mails (separados por coma) que reciben el aviso de cada ticket nuevo y de cada respuesta de un cliente');

-- DECISIÓN D-75: todos los roles pueden abrir tickets y ven los de toda su instalación (se quita por rol).
INSERT IGNORE INTO permisos (codigo, modulo, descripcion) VALUES
  ('soporte.usar', 'sistema', 'Abrir tickets de soporte a O&S y seguir los de la instalación');
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'soporte.usar';
