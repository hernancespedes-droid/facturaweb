Set SAFETY OFF
Set TALK OFF
Set DELE ON
Close TABLES ALL
Close DATA ALL
LOCAL lCocinado
STORE .T. TO lCocinado

Open DATA datos EXCLUSIVE
TRY
	Open DATA datos EXCLUSIVE
	
	USE parametros
	lcDatosBup = ALLTRIM(parametros.path_bup)
	USE IN 'parametros'
	
CATCH TO oError
	MESSAGEBOX(oError.message,16,'No pudo abrir en Exclusivo')
	lCocinado = .F.		
ENDTRY


	*---------------  subdiario   -----------------------
	TRY 
		Alter TABLE subdiario add tipoafipoys 			N(3) 	DEFAULT 0
	catch	
	ENDTRY
	
	TRY 	
		Alter TABLE subdiario add fe_vencimiento 		D 		DEFAULT CTOD('')
	catch	
	ENDTRY	
		
	TRY 		
		Alter TABLE subdiario ADD fe_tipocbte_asoc 		C(3) 	DEFAULT ''
	catch	
	ENDTRY
	
	TRY
		Alter TABLE subdiario ADD fe_nrocbte_asoc 		N(7,0) 	DEFAULT 0
	catch	
	ENDTRY	
	
	TRY		
		Alter TABLE subdiario add fe_fecha_asoc 		D 		DEFAULT CTOD('')
	catch	
	ENDTRY	
		
&&------------------------------------------------------------------------------------------------------

CLOSE DATABASES all
&&------------------------------------------------------------------------------------------------------
*!*	TRY
*!*		OPEN DATABASE lcDatosBup+'datosbup.dbc' EXCLUSIVE
*!*	CATCH TO oError
*!*		MESSAGEBOX(oError.message,16,'Error Abrir Bup')
*!*		lCocinado = .F.
*!*	ENDTRY
*!*	****************************************
*!*		*---------------  subdiario   -----------------------
*!*		TRY 
*!*			Alter TABLE subdiario add tipoafipoys 			N(3) 	DEFAULT 0
*!*		catch	
*!*		ENDTRY
*!*		
*!*		TRY 	
*!*			Alter TABLE subdiario add fe_vencimiento 		D 		DEFAULT CTOD('')
*!*		catch	
*!*		ENDTRY	
*!*			
*!*		TRY 		
*!*			Alter TABLE subdiario ADD fe_tipocbte_asoc 		C(3) 	DEFAULT ''
*!*		catch	
*!*		ENDTRY
*!*		
*!*		TRY
*!*			Alter TABLE subdiario ADD fe_nrocbte_asoc 		N(7,0) 	DEFAULT 0
*!*		catch	
*!*		ENDTRY	
*!*		
*!*		TRY		
*!*			Alter TABLE subdiario add fe_fecha_asoc 		D 		DEFAULT CTOD('')
*!*		catch	
*!*		ENDTRY	


*!*	IF !lCocinado
*!*		RETURN
*!*	ENDIF
*!*	****************************************

	

&&------------------------------------------------------------------------------------------------------
Wait CLEAR

	MESSAGEBOX('PROCESO TERMINADO!!!',0+64,"Aviso del sistema")

