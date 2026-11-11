LPARAMETERS lcClave

SELECT S.*,C.*,P.*,s.forma_pago as FPag,articulo,codigo_de_barra,;
  unidades,bultos,kilos,lista, precio, precio_siniva,;
  E.porcentaje_descuento AS dtoLinea,total_item,total_siniva, grabado, por_iva,;
  costo,e.observaciones as detart, merma,unidades_ori,pg.descripcion as condpago FROM subdiario S ;
INNER JOIN estadistica E ON E.tipo+E.letra+STR(E.sucursal,2)+STR(E.comprobante,7)=S.tipo+S.letra+STR(S.sucursal,2)+STR(S.comprobante,7) AND s.cliente=e.cliente AND s.prefijo=e.prefijo ; 
INNER JOIN clientes c    ON s.cliente=c.codigo ;
INNER JOIN provincias p  ON c.provincia=p.codigo ;
INNER JOIN condicionPago pg ON pg.codigo=cond_pago ;
where fe_Clave=lcClave INTO CURSOR imprime

IF _tally>0
	lcCbte = tipo+"_"+TRANSFORM(prefijo,"@L 9999")+"_"+TRANSFORM(comprobante,"@L 99999999")
	** ALLTRIM(razon_social)+"-"+
	lcNombre =tipo+"_"+TRANSFORM(prefijo,"@L 9999")+"_"+TRANSFORM(comprobante,"@L 99999999")
	
	lcCommand = "ERASE "+lcNombre+".pdf"
	&lcCommand
	
*!*		*-- Crear la clase Listener
*!*		SET CLASSLIB TO curdir() + 'reports\_REPORTLISTENER'
*!*		ox = CREATEOBJECT('htmlListener')
*!*		 *-- Configurar algunas propiedades
*!*		TRY 
*!*			MD (CURDIR()+"FACTURAS\") 
*!*		CATCH 
*!*		ENDTRY 	
*!*		ox.QuietMode = .T.
*!*	ox.TargetFileName = CURDIR()+"FACTURAS\"+lcNombre
	 *-- Ejecutar el informe
*!*	REPORT FORM (CURDIR()+"reports\factura_e.frx") OBJECT ox 
	
	TargetFileName = CURDIR()+"FACTURAS\"+lcNombre+".pdf"
	lcCommand = "ERASE "+TargetFileName
	&lcCommand
	SET STEP ON 
	genera_pdf(CURDIR()+"reports\factura_e.frx",TargetFileName)
	TRY 	
			&&Envio el Email
			*- Create and send the message.
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

			loMsg.Subject	= "Organizacion & Sistemas - Envio de Comprobante Electronico: "+lcCbte

	  		
			loMsg.FROM		= ALLTRIM(sucursales.emailenvio)
			loMsg.TO		= ALLTRIM(clientes.emailfc)
			
			&& para recibir la confirmacion de llegada
			loMsg.Fields("urn:schemas:mailheader:disposition-notification-to") = loMsg.From
	  		loMsg.Fields("urn:schemas:mailheader:return-receipt-to") = loMsg.From
	  		loMsg.Fields.Update
			*loMsg.htmlbody	= FILETOSTR(ox.TargetFileName)
			** Si es otro titulo agregar texto
			lomsg.TextBody = "Estimado cliente, le enviamos la factura correspondiente a los servicios prestados por nuestra empresa. Puede visualizarla abriendo el archivo adjunto. "+CHR(13)+CHR(10)+"Muchas Gracias"+CHR(13)+CHR(10)+"Organizacion & Sistemas"+CHR(13)+CHR(10)
			lomsg.AddAttachment = TargetFileName			
			lRta = loMsg.SEND()
			MESSAGEBOX("Se envio el mensaje con la factura electronica",48,"Atencion")
			UPDATE subdiario SET fe_lImprimio=.t.,fe_cEmailEnvio=ALLTRIM(clientes.emailfc) WHERE fe_clave=lcClave
			
	CATCH TO oError
			MESSAGEBOX("ERROR AL ENVIAR EL MAIL!"+CHR(13)+oError.Message,48,"Envío de E-mail")
	ENDTRY

	RELEASE loMsg

 
ENDIF 