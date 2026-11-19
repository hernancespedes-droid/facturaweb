-- Kiosco (0.26.0): orden de las tarjetas, precio editable con permiso y venta sin stock sin pedir autorización (con permiso).
--   articulos.orden_kiosco: posición en las tarjetas del kiosco (1 = primero; 0 = sin posición, va al final por orden alfabético).
--   kiosco.precio: editar el precio de un producto en el carrito del kiosco (sin el permiso, el kiosco usa siempre el precio de lista).
--   kiosco.sin_stock: en el kiosco vender sin stock disponible sin pedir autorización (queda registrado).
--   stock.vender_sin_stock: lo mismo en todas las ventas (facturas y presupuestos).
--   stock_autorizaciones.automatica: 1 cuando no hubo autorización de un supervisor sino un permiso del que vende.
ALTER TABLE articulos ADD COLUMN orden_kiosco SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Posición en las tarjetas del kiosco (0 = sin posición)',
  ADD KEY ix_articulos_orden_kiosco (orden_kiosco);
ALTER TABLE stock_autorizaciones ADD COLUMN automatica TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = vendió sin stock por permiso propio, sin autorización de un supervisor';

INSERT IGNORE INTO permisos (codigo, modulo, descripcion) VALUES
  ('kiosco.precio', 'kiosco', 'Kiosco: cambiar el precio de un producto en el carrito'),
  ('kiosco.sin_stock', 'kiosco', 'Kiosco: vender sin stock disponible sin pedir autorización (queda registrado)'),
  ('stock.vender_sin_stock', 'stock', 'Ventas: vender sin stock disponible sin pedir autorización (queda registrado)');
-- Administrador y Master tienen todos los permisos (cambian el precio y venden sin stock sin pedir autorización: son los que autorizan). El Operador no: se tilda por rol (Roles → Kiosco / Comprobantes).
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
  SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo IN ('kiosco.precio', 'kiosco.sin_stock', 'stock.vender_sin_stock') WHERE r.es_admin = 1 OR r.es_master = 1;
