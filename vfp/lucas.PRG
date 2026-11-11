select 0
use subdiario

select 0
use iva

SELECT SUBDIARIO
SCAN
	scatter memvar
	IF neto_grabado > 0
		m.monto = subdiario.neto_grabado
		m.importe = subdiario.iva_1
		m.alicuota = 21
		INSERT INTO IVA from MEMVAR
	ENDIF
	IF neto_exento > 0
		m.monto = subdiario.neto_exento
		m.importe = 0
		m.alicuota = 0
		INSERT INTO IVA from MEMVAR
	ENDIF
ENDSCAN
