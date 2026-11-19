-- Impresión de comprobantes: A4 o ticket (impresora térmica de 80 o de 50 mm).
--   parametros.impresion: 'a4' (por omisión), 't80' o 't50' (Tablas → Parámetros).
ALTER TABLE parametros ADD COLUMN impresion VARCHAR(4) NOT NULL DEFAULT 'a4' COMMENT 'Papel de impresión: a4, t80 (ticket 80 mm) o t50 (ticket 50 mm)';
