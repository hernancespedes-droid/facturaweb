PARAMETERS lnIva

DO case
	CASE lnIva = 1
		RETURN "Resp. Inscripto"
	CASE lnIva = 2
		RETURN "Resp. No Inscripto"		
	CASE lnIva = 3
		RETURN "Consumidor Final"		
	CASE lnIva = 4
		RETURN "Exento (B)"		
	CASE lnIva = 5
		RETURN "Exento (A)"		
	CASE lnIva = 6
		RETURN "Liberado"		
	CASE lnIva = 7
		RETURN "Monotributo"
	CASE lnIva = 8
		RETURN "Exento Especial (A)"
	CASE lnIva = 9
		RETURN "Exento Especial (B)"						
ENDCASE
