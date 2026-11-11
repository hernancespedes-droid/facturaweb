Set Safety Off
Set Talk Off
Set Dele On
Close Tables All
Close Data All
Open Data datos Exclusive

Create Table 'ROLLOS_TELAS.DBF' Name 'ROLLOS_TELAS' ( ;
	NUMERO           C(20) Not Null,;
	FECHA		     D Not Null,;
	CODIGO_TELA	     C(15)  Not Null,;
	CANTIDAD         N(8,2) Not Null,;
	SALDO            N(8,2) Not Null,;
	numero_mvstk	 N(10) NOT NULL,;
	CODIGO_MP		 C(15) NOT NULL,;
	ACTUALIZACION    T Not Null Default Datetime() ;
	)

Set Collate To 'MACHINE'

Index On NUMERO For .Not.Deleted() Tag NUMERO
Index On CODIGO_TELA  For .Not.Deleted() Tag COD_TELA  

Create Table 'ROLLOS_TELAS_DET.DBF' Name 'ROLLOS_TELAS_DET' ( ;
	NUMERO           C(20) Not Null,;
	FECHA		     D Not Null,;
	CANTIDAD         N(8,2) Not Null,;
	NRO_ORDEN		 N(10)  Not Null,;
	ACTUALIZACION    T Not Null Default Datetime() ;
	)
Index On NUMERO For .Not.Deleted() Tag NUMERO

Create View V_ROLLOS_TELAS_SALDOS AS;
	SELECT ROLLOS_TELAS.NUMERO ,;
	ROLLOS_TELAS.CODIGO_TELA   ,;
	TELAS.DESCRIPCION          ,;
	ROLLOS_TELAS.SALDO          ;
	FROM ROLLOS_TELAS;
	INNER JOIN TELAS ON ROLLOS_TELAS.CODIGO_TELA = TELAS.CODIGO;
	WHERE ROLLOS_TELAS.SALDO > 0;
	ORDER BY TELAS.DESCRIPCION
	
Create View V_ROLLOS_TELAS AS;
	SELECT ROLLOS_TELAS.NUMERO ,;
	ROLLOS_TELAS.CODIGO_TELA   ,;
	TELAS.DESCRIPCION          ,;
	ROLLOS_TELAS.SALDO          ;
	FROM ROLLOS_TELAS;
	INNER JOIN TELAS ON ROLLOS_TELAS.CODIGO_TELA = TELAS.CODIGO;
	ORDER BY TELAS.DESCRIPCION	
	
	

