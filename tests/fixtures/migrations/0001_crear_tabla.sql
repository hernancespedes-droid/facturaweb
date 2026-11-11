-- Fixture de prueba: crea una tabla y carga una fila con un punto y coma adentro de una cadena.
CREATE TABLE test_mig_a (
    id     INT          NOT NULL PRIMARY KEY,
    nombre VARCHAR(20)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO test_mig_a (id, nombre) VALUES (1, 'a;b');
