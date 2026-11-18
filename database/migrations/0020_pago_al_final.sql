-- La forma de pago se pide al terminar la venta (pantalla de revisión) y, si se paga en efectivo de más, se indica el vuelto.
--   forma_de_pagos.acepta_vuelto: por forma de pago (Tablas → Formas de pago) si admite que el cliente entregue más y se le devuelva la diferencia.
--   caja.p_recibido / p_vuelto: lo que entregó el cliente y el vuelto (p_importe es lo que queda en caja: el total de la venta).
--   Tipo 10 = Billetera virtual (Mercado Pago, MODO, etc.), junto a Tarjeta para ver lo cobrado por medios electrónicos.

ALTER TABLE forma_de_pagos ADD COLUMN acepta_vuelto TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = si se paga de más se da vuelto (efectivo)';
UPDATE forma_de_pagos SET acepta_vuelto = 1 WHERE tipo = 1;
INSERT IGNORE INTO forma_de_pagos (codigo, descripcion, tipo) VALUES ('BV', 'BILLETERA VIRTUAL', 10);

ALTER TABLE caja
  ADD COLUMN p_recibido DECIMAL(15,2) NULL COMMENT 'Lo que entregó el cliente (efectivo)',
  ADD COLUMN p_vuelto DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'Vuelto dado al cliente';
