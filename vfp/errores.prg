LPARAMETERS tcERROR, tcMESSAGE, tcMESSAGE1, tcLINENO, tcPROGRAM
LOCAL lcMsg
IF !USED('errores')
   SELECT 0
   USE errores SHARED
ENDIF

SELECT errores
APPEND BLANK
REPLACE fechahora WITH DATETIME()
REPLACE ERROR     WITH tcERROR
REPLACE MESSAGE   WITH tcMESSAGE
REPLACE message1  WITH tcMESSAGE1
REPLACE LINENO    WITH tcLINENO
REPLACE PROGRAM   WITH tcPROGRAM
REPLACE usuario   WITH Iif(Type(_usuario)#'U',_usuario,'')
REPLACE canal     WITH _gcanal
REPLACE sucursal  WITH _gsucursal
REPLACE terminal  WITH _gterminal
USE

lcMsg=tcMESSAGE
lcBoton=0      &&O es OK
lcIcon=48	  &&32 es !
lcNroBoton=0   &&0 es 1er boton
lcTitulo='!! ERROR !! Salida Forzada del Sistema'

			TEXT TO lcError TEXTMERGE noshow
Fecha: <<STRTRAN(DTOC(DATE()),"/","-")>>
Usuario: <<SYS(0)>>
Error: <<ERROR()>>
Descripción: <<MESSAGE()>>
Programa: <<PROGRAM()>>
          <<PROGRAM(1)>>
          <<PROGRAM(2)>>
          <<PROGRAM(3)>>
Línea: <<LINENO()>>
Código Con Error: <<MESSAGE(1)>>

			ENDTEXT 	
			
=MESSAGEBOX(lcError,lcBoton+lcIcon+lcNroBoton,lcTitulo)

IF TXNLEVEL() > 0    &&Si hay una transaccion en progreso
   ROLLBACK
ENDIF

IF !USED("parametros")
	USE parametros IN 0
ENDIF 

IF !USED("Sucursales")
	USE Sucursales IN 0
ENDIF 

=SEEK(_gsucursal,'SUCURSALES','CODIGO')

IF parametros.envio_email_error
	WAIT WINDOW "GENERANDO ARCHIVOS.... Aguarde un instante..." NOWAIT NOCLEAR TIMEOUT 5
	DOEVENTS FORCE
	TRY			
			TEXT TO lcError TEXTMERGE noshow
Fecha: <<STRTRAN(TTOC(DATETIME()),"/","-")>>
Usuario: <<SYS(0)>>
Error: <<ERROR()>>
Descripción: <<MESSAGE()>>
Programa: <<PROGRAM()>>
          <<PROGRAM(1)>>
          <<PROGRAM(2)>>
          <<PROGRAM(3)>>
Línea: <<LINENO()>>
Código Con Error: <<MESSAGE(1)>>

			ENDTEXT 	
			

			DECLARE Sleep IN Win32API INTEGER nMilliseconds
			
			IF !DIRECTORY(CURDIR()+"temp")
				MD (CURDIR()+"temp")
			ENDIF 
			lcPathTemp = FULLPATH(CURDIR()+"temp")+"\"
			lcArchivoDestino = lcPathTemp+SYS(2015)+".bmp"
			lcArchivoRAR = lcPathTemp+"pantalla.rar"
			
			IF parametros.envio_pantalla
				imgAarch(lcArchivoDestino)
				lTermino = .f.
				nSegundos = SECONDS()
				DOEVENTS FORCE
				WAIT WINDOW "Generando archivo... Aguarde un instante " NOWAIT NOCLEAR TIMEOUT 5 
				DO WHILE !lTermino AND SECONDS()-nSegundos <5
					TRY 
						* espera 1 segundo
						nSleepSeconds = 1
						nMilliseconds = nSleepSeconds * 500
						=Sleep(nMilliSeconds)
						DOEVENTS FORCE 
						IF 	FILE(lcArchivoDestino)
							lTermino = .T.
						ELSE
							lTermino = .f.	
						ENDIF	
					CATCH
						lTermino = .T.
					ENDTRY
				ENDDO	
			ENDIF 
			loMsg = CREATEOBJECT("CDO.Message")
			lcLinkConfi = "http://schemas.microsoft.com/cdo/configuration/"
			loMsg.Configuration.FIELDS.ITEM(lcLinkConfi+"smtpauthenticate") = ALLTRIM(sucursales.smtpauthenticate)
			loMsg.Configuration.FIELDS.ITEM(lcLinkConfi+"sendusername") 	= ALLTRIM(sucursales.sendusername)
			loMsg.Configuration.FIELDS.ITEM(lcLinkConfi+"sendpassword") 	= ALLTRIM(sucursales.sendpassword)
			loMsg.Configuration.FIELDS.ITEM(lcLinkConfi+"sendusing") 		= ALLTRIM(sucursales.sendusing)
			loMsg.Configuration.FIELDS.ITEM(lcLinkConfi+"smtpserver") 		= ALLTRIM(sucursales.smtpserver)
			loMsg.Configuration.FIELDS.ITEM(lcLinkConfi+"smtpserverport") 	= ALLTRIM(sucursales.smtpserverport)
			loMsg.Configuration.FIELDS.ITEM(lcLinkConfi+"smtpconnectiontimeout") = ALLTRIM(sucursales.smtpconnectiontimeour)
			loMsg.Configuration.FIELDS.UPDATE
			loMsg.Subject	= "ERROR EN "+parametros.empresa+" "+TTOC(DATETIME())
	  		
			loMsg.FROM		= ALLTRIM(sucursales.emailenvio)
			loMsg.TO		= ALLTRIM(parametros.emailbackup)
			lomsg.TextBody = lcError

			IF FILE(lcArchivoDestino) AND parametros.envio_pantalla

				lComm = 'c:\archivos de programa\winrar\winrar a -dh -ac -df -ep "'+lcArchivoRAR+'"'+' "'+lcArchivoDestino+'"'
				RUN /n &lComm

				lTermino = .f.
				nSegundos = SECONDS()
				DO WHILE !lTermino AND SECONDS()-nSegundos <120
					TRY 
						* espera 1 segundo
						nSleepSeconds = 1
						nMilliseconds = nSleepSeconds * 1500
						=Sleep(nMilliSeconds)
						DOEVENTS FORCE 
						lnHdl = FOPEN(lcArchivoRAR,11)
						IF 	lnHdl>0
							FCLOSE(lnHdl)
							lTermino = .T.
						ELSE
							lTermino = .f.	
						ENDIF	
					CATCH
						lTermino = .T.
					ENDTRY
				ENDDO	
				
				WAIT CLEAR 
				IF FILE(lcArchivoRAR)
					lomsg.AddAttachment(lcArchivoRAR)
				ENDIF 	
			ENDIF 	
			WAIT WINDOW "Enviando archivo... Aguarde un instante "+CHR(13)+"Puede demorar varios segundos... Sea paciente" NOWAIT NOCLEAR 
			lRta = loMsg.SEND()
			DOEVENTS FORCE
			WAIT CLEAR 
			DELETE FILE (lcArchivoDestino)
			DELETE FILE (lcArchivoRAR)
	CATCH
	ENDTRY	
ENDIF 
WAIT CLEAR 

SET SYSMENU TO DEFAULT
ON ERROR
CLOSE ALL
QUIT
