-- Presupuestos: la opción de vender sin hacer una factura en ARCA.
--   PR = Presupuesto (letra X, no fiscal): descuenta stock, se cobra o va a cuenta corriente, se imprime/mail/WhatsApp.
--   NX = Nota de crédito X: la contrapartida de un presupuesto (repone stock y deja saldo a favor), como la NC con la factura.
-- ctact.tico: PR 4 (débito) y NX 10 (crédito). Sus números salen de sucursales (ultimo_presupuesto, ultima_nc_x).
-- Un presupuesto puede generar UNA sola factura: esa factura va solo al subdiario (solo_subdiario = 1: sin caja, sin cuenta corriente y
-- sin stock, porque el presupuesto ya los movió) y queda ligada con presupuesto_id (único: una factura por presupuesto).

ALTER TABLE sucursales
  ADD COLUMN ultimo_presupuesto INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN ultima_nc_x INT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE subdiario
  ADD COLUMN presupuesto_id INT UNSIGNED NULL COMMENT 'En una factura: el presupuesto del que salió',
  ADD COLUMN solo_subdiario TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = factura (o nota) de un presupuesto: solo al subdiario',
  ADD UNIQUE KEY uq_subdiario_presupuesto (presupuesto_id);
