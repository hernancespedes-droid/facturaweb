SET STEP ON 
TRY 
	ALTER table sucursales 			ADD COLUMN smtpconnectiontimeout C(10)		DEFAULT '9'
	ALTER table sucursales 			ADD COLUMN smtpusessl 	L					DEFAULT .F.
CATCH TO oError
	MESSAGEBOX(oError.message)

endtry


*!*	SELECT S.Fecha as Fecha,S.Tipo as Tipo,S.Letra as Letra,TRANSFORM(S.Prefijo,'@L 9999') as Prefi,;
*!*	    S.Comprobante as Comprob,S.Neto_grabado+S.Neto_exento+IIF(s.letra='B',iva_1+iva_2,0) as SubTot,S.Iva_1 as IVA1,'21.00' as tIva1,'10.50' as tIva2,;
*!*	    'Descuento '+iif(S.Bonificacion # 0,alltrim(str(S.Porcentaje_descuento,6,2))+'%','') as DescripcionDto,;
*!*	    S.Iva_2 as Iva2,S.Bonificacion as DtoGral,;
*!*	    IIF((S.Neto_grabado+S.Neto_exento+IIF(s.letra='B',iva_1+iva_2,0))>Neto,(S.Neto_grabado+S.Neto_exento+IIF(s.letra='B',iva_1+iva_2,0)),Neto)as Neto ,;
*!*	    e.Porcentaje_descuento as PORDTO,S.Cuit_cliente as CUIT,;
*!*	    ' ' as MONEDA, 0 as COTIZ,;
*!*	    S.Fe_ntipoafip as TipAfip,S.Fe_ccae as CAE,;
*!*	    S.Fe_dfecha as FecAfip,TRANSFORM(S.Fe_nrocbte,'@L 99999999') as NroAfip ,fe_dFecha as CaeVto,;
*!*		PADR('Articulo',20)+' '+PADR('Descripcion',40)+' ' as TITDES,;
*!*		PADR(e.articulo,20)+' '+ (iif(e.articulo#'NC_ND_VARIOS',PADR(a.descripcion,40),PADR(s.observaciones,40)))  as Descri,;
*!*		(iif(s.letra ='B',iif(e.articulo#'NC_ND_VARIOS',e.precio_SinIva,e.precio_SinIva),e.precio_SinIva )) as precio,;
*!*		(iif(s.letra ='B',iif(e.articulo#'NC_ND_VARIOS',e.precio_SinIva,e.precio_SinIva),e.precio_SinIva )* unidades) as totItem,;
*!*		unidades as cant,0 as DtoUni,;
*!*	  	vernotaalpie(s.tipo+s.letra+STR(s.sucursal,2)+STR(s.comprobante,7)) as pie,;
*!*		vercativa(s.iva_Cliente) as Cativa,ALLTRIM(c.razon_social)+' ('+TRANSFORM(c.Codigo,'99999')+')' as RazSoc1,' ' as RazSoc2,  ;
*!*		c.Domicilio as Domic1, ALLTRIM(localidad)+' '+ALLTRIM(p.descripcion) as Domic2,' ' as Domic3, ;
*!*		0 as OC, 0 as Remito, ;
*!*		G.descripcion as pago, SPACE(1) as DesMon, SPACE(1) as CodMon,' Retenciones' as timpint,0.00 as impint;
*!*	    FROM Subdiario S;
*!*	    INNER JOIN Estadistica E ON E.tipo+E.letra+STR(E.sucursal,2)+str(E.prefijo,4)+STR(E.comprobante,7)=s.tipo+s.letra+STR(s.sucursal,2)+str(S.prefijo,4)+STR(s.comprobante,7);
*!*	    INNER JOIN clientes C ON c.codigo    = s.cliente ;
*!*	    inner join provincias P on c.provincia = p.codigo ;
*!*	    INNER JOIN articulos a ON a.codigo    = e.articulo ;
*!*	    left  JOIN condicionpago G ON S.cond_pago = G.codigo;
*!*	    WHERE s.fe_clave=    1000117 ;
*!*	    INTO CURSOR pepe
*!*	    
*!*	    IF _tally > 0
*!*	    	SELECT pepe
*!*	    	brow
*!*	    ENDIF
    