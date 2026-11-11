LOCAL cDirectorio

CLOSE DATABASES all

*-cDirectorio = '\datas'
cDirectorio = ''

TRY 
  OPEN DATABASE (CURDIR()+ cDirectorio +'\datos')
  SET DATABASE TO datos

CATCH To oError
	  WAIT WINDOW "No se pudo ingresar datos. Se esta utilizando el sistema" + MESSAGE() 
ENDTRY 	  

CLOSE DATABASES all
TRY 
	SET EXCLUSIVE OFF 
	TRY 
		SET NOTIFY on
		SET NOtify CURSOR on
		SET TALK on
		OPEN DATABASE (CURDIR()+ cDirectorio + '\datos') EXCLUSIVE 

		DROP TABLE 'FE_PARAMETROS'

		WAIT WINDOW "Agregando columna de clave en subdi" nowait	
		
		ALTER TABLE Subdiario ADD COLUMN fe_dVtoCae D not 		null default ctod(' ') NOVALIDATE
		ALTER TABLE Subdiario ADD COLUMN nrointerno N(7) 		default 0 NOVALIDATE		
		ALTER TABLE Subdiario ADD COLUMN electronica L 		    default .f. NOVALIDATE
		
*!*		   	ALTER table subdiario ADD COLUMN fe_clave N(14) 		DEFAULT RECNO()
*!*			ALTER TABLE Subdiario ADD COLUMN fe_iid N(14) 			not null default 0 NOVALIDATE
*!*			ALTER TABLE Subdiario ADD COLUMN fe_cCAE C(30) 			not null default ' ' NOVALIDATE
*!*			ALTER TABLE Subdiario ADD COLUMN fe_dFECHA D 			not null default ctod(' ') NOVALIDATE
*!*			ALTER TABLE Subdiario ADD COLUMN fe_lImprimio L 		default .T. NOVALIDATE
*!*			ALTER TABLE Subdiario ADD COLUMN fe_cEmailEnvio C(100)  not null default ' ' NOVALIDATE
*!*			ALTER TABLE Subdiario ADD COLUMN fe_dFechaEnvio D 		not null default CTOD(' ') NOVALIDATE
*!*			ALTER TABLE Subdiario ADD COLUMN fe_nTipoAfip N(2) 		default 0 NOVALIDATE
*!*			ALTER TABLE Subdiario ADD COLUMN fe_lRechazado L 		default .f. NOVALIDATE
*!*			ALTER TABLE Subdiario ADD COLUMN FE_NroCbte N(8) 		default 0 NOVALIDATE
*!*			ALTER TABLE Subdiario ADD COLUMN FE_mObservaciones M 	NOVALIDATE
*!*			ALTER TABLE Subdiario ADD COLUMN fe_dVtoCae D not 		null default ctod(' ') NOVALIDATE
*!*			ALTER TABLE Subdiario ADD COLUMN nrointerno N(7) 		default 0 NOVALIDATE		
*!*			ALTER TABLE Subdiario ADD COLUMN electronica L 		    default .f. NOVALIDATE
*!*			ALTER TABLE Subdiario ALTER COLUMN FE_Clave N(14) 		default VAL(STRTRAN(RIGHT(DTOS(DATE()),4)+STR(SECONDS()*100,8)+RIGHT(TRANSFORM(ABS(RECNO()),"99999999"),2),' ','')) NOVALIDATE
*!*			ALTER TABLE Subdiario ADD COLUMN condicion_pago C(2)  	Default ''

		
		SET TALK off
		WAIT CLEAR 
	CATCH To oError
		WAIT WINDOW "ERROR "+MESSAGE()
	ENDTRY 	


	CREATE TABLE (Curdir()+ cDirectorio +"\fe_Actualizacion.dbf") (;
	  [tabla] [char] (50) NOT NULL, ;
	  [camponumero] [char] (20) NOT NULL, ;
	  [campofecha]	[char] (20) NOT NULL, ;
	  [clavetabla]	[char] (100) NOT NULL, ;
	  [clavesubdi]	[char] (100) NOT NULL, ;
	  [base]	[char] (100) default 'datos')


	TRY 
	      WAIT WINDOW "Modificando ctact" NOWAIT 
		  cLeyenda = 'Estimado cliente adjuntamos comprobante de facturación.'
		  
	      ALTER table ctact 				ADD COLUMN fe_clave N(14) DEFAULT RECNO()
	      ALTER table caja 					ADD COLUMN fe_clave N(14) DEFAULT RECNO()	      
   	      ALTER table iva 					ADD COLUMN fe_clave N(14) DEFAULT RECNO()	      
   	      ALTER table mvstk_cabecera 		ADD COLUMN fe_clave N(14) DEFAULT RECNO()
   	      ALTER table estadistica		 	ADD COLUMN fe_clave N(14) DEFAULT RECNO()
	      
		  ALTER table sucursales 			ADD COLUMN fac_electronica l  				DEFAULT .f. 	      	      
		  ALTER table sucursales 			ADD COLUMN enviaMail l 	 					DEFAULT .f. 
		  ALTER table sucursales 			ADD COLUMN smtpusessl 	L					DEFAULT .F.   
		  ALTER table sucursales 			ADD COLUMN smtpconnectiontimeout C(10)		DEFAULT '9'		  	      
   	      
		  
*!*			  ALTER table sucursales 			ADD COLUMN fac_electronica l  				DEFAULT .f. 	      	      
*!*			  ALTER table sucursales 			ADD COLUMN prefijo_fac_electronica N(4,0)	DEFAULT 0
*!*			  ALTER table sucursales 			ADD COLUMN emailenvio C(60)  				DEFAULT 'info@oys.com.ar'
*!*			  ALTER table sucursales 			ADD COLUMN mensajeenvio M					DEFAULT 'Estimado cliente adjuntamos comprobante de facturación.'
*!*			  ALTER table sucursales 			ADD COLUMN enviaMail l 	 					DEFAULT .f. 
*!*			  ALTER table sucursales 			ADD COLUMN smtpauthenticate C(40)			DEFAULT '1'
*!*			  ALTER table sucursales 			ADD COLUMN sendusername C(40)				DEFAULT 'operadoresoys'		  
*!*			  ALTER table sucursales 			ADD COLUMN sendpassword C(40)				DEFAULT 'system10'
*!*			  ALTER table sucursales 			ADD COLUMN sendusing C(40)					DEFAULT '2'		  
*!*			  ALTER table sucursales 			ADD COLUMN smtpserver C(40)					DEFAULT 'smtp.fibertel.com.ar'
*!*			  ALTER table sucursales 			ADD COLUMN smtpserverport C(40)				DEFAULT '25'		  
*!*			  ALTER table sucursales 			ADD COLUMN smtpconnectiontimeout C(10)		DEFAULT '9'
*!*			  ALTER table sucursales 			ADD COLUMN smtpusessl 	L					DEFAULT .F.
   
	      WAIT WINDOW "Modificando configuracion" NOWAIT 

	CATCH To oError
		  WAIT WINDOW "No se pudo ingresar datos en fe_actualizacion" + MESSAGE() 
	ENDTRY 	 
      
	CREATE TABLE (Curdir()+ cDirectorio +"\Equivalencias_afip") (; 
	      [Tabla]    [char] (30) , ;
	      [codigoafip]    [char] (3) , ;	      
	      [codigooys]    [char] (3) , ;	      
	      [descripcion]    [char] (20))
	      
	      
	CREATE TABLE (Curdir()+ cDirectorio +"\tributos.dbf") (; 
	  [FE_CLAVE] [num] (15,0) DEFAULT 0, ;
	  [ID] [num] (2) DEFAULT 0, ;
	  [DESCRIPCION]	[char] (80) DEFAULT '', ;
	  [BASEIMPONIBLE]	[num] (15,2) DEFAULT 0, ;
	  [ALICUOTA]	[num] (15,2) DEFAULT 0, ;
	  [IMPORTE]		[num] (15,2) default 0)

	INDEX ON fe_clave TAG CLAVE FOR .NOT.DELETED() COLLATE 'MACHINE'	      
	
	
	    CREATE TABLE (Curdir()+ cDirectorio +"\fe_Parametros.dbf") (;
	      [par_iid] [int] AUTOINC ,  ;
	      [par_cCodigo] [char] (100) NOT NULL , ;
	      [par_cCampo1] [char] (100)  NULL , ;
	      [par_cCampo2] [char] (200)  NULL )
	
	      
CATCH To oError
	MESSAGEBOX("Error al crear configuracion de automatico.exe"+CHR(13)+MESSAGE())
ENDTRY 


SET NOTIFY OFF
SET NOTIFY CURSOR OFF


Local lEnterSaltoLinea As Number , lcErrorMenasje As String

Close Databases All
Set Exclusive On

*-- Arrancamos el proceso de Modificación de Comprobantes (Facturas de Exportación)
TRY
   CLOSE DATABASES all
   *-- Abrimos al base de datos de para ver la corrida del procesos al dia de la Fecha
	OPEN DATABASE (CURDIR()+ cDirectorio + '\datos')
    SET DATABASE TO datos

	Update subdiario Set subdiario.fe_ccae =  "." WHERE EMPTY(subdiario.fe_ccae)
Close Databases

   *-- Captura del Error del bloque
Catch To oError
   =Messagebox("Error al intentar procesar datos de Facturas de Electrónicas de Exportación "+Chr(13)+;
      oError.Message+Chr(13)+;
      "Error # :"+Transform(oError.ErrorNo)+Chr(13)+ Chr(10) +;
      "Line # :"+Transform(oError.Lineno)+Chr(13)+ Chr(10) +;
      "Contenido Linea # :"+Transform(oError.LineContents)+Chr(13)+ Chr(10)+;
      "Mensage # : " + oError.Message +Chr(13)+ Chr(10)+;
      "Procedimiento # : " + oError.Procedure +Chr(13)+ Chr(10)+;
      "Detalle # : " + oError.Details +Chr(13)+ Chr(10)+;
      "StackLevel # : " + Str(oError.StackLevel) +Chr(13)+ Chr(10)+ ;
      "UserValue # : " + oError.UserValue ,48,"Error" )
Endtry


Wait Clear
Close Databases All
Release All

MESSAGEBOX('Proceso Terminado')

