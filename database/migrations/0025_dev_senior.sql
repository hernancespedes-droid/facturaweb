-- 0.23.1: en las descripciones de Configuración, «decisión de Claude» pasa a «decisión de Dev Senior». Idempotente.
UPDATE configuracion
   SET descripcion = REPLACE(descripcion, 'decisión de Claude', 'decisión de Dev Senior')
 WHERE descripcion LIKE '%decisión de Claude%';
