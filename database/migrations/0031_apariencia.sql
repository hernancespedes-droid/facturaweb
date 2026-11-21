-- 0.31.0 Apariencia de cada instalación: logo, color y nombre del cliente en todo el sistema (barra, ingreso, pestaña,
-- portal), con el mismo código para todos. Las claves se editan en Sistema → Configuración → Apariencia (no en la lista
-- general). Las imágenes van en la tabla imagenes (0017): 'logo_web', 'logo_pantalla' y 'logo_icono' se crean al subir.
-- Vacías = se ve como siempre (el azul y el nombre FacturaWeb).
INSERT IGNORE INTO configuracion (clave, valor, tipo, descripcion) VALUES
  ('apariencia.nombre', '', 'texto', 'Apariencia: nombre que se ve en la barra y en la pestaña (vacío = FacturaWeb)'),
  ('apariencia.color', '', 'texto', 'Apariencia: color principal en #rrggbb (vacío = el azul de FacturaWeb)'),
  ('apariencia.logo_en_pantalla', '1', 'si_no', 'Apariencia: mostrar el logo en la barra y en el ingreso'),
  ('apariencia.logo_placa', '0', 'si_no', 'Apariencia: poner el logo sobre una placa blanca');
