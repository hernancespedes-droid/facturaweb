-- En el VFP el texto de cada renglón puede ser largo (varios renglones, hasta ~4.000 letras en los datos de OYS): se guarda completo.
ALTER TABLE estadistica MODIFY observaciones TEXT NULL COMMENT 'Descripción que se imprime (puede tener varios renglones)';
