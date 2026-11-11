Set Dele On
Set Collate To "MACHINE"
Close Tables All
Close Data All
Open Data datos Exclusive

Create Table recepcion_TALLER_CAB NAME recepcion_TALLER_CAB (;
	numero			N(10)	NOT NULL ,;
	fecha			D		not null ,;
	taller			C(3)	not null ,;
	estado			C(1)	not null ,;
	actualizacion  	T		not null DEFAULT Datetime() ,;
	observaciones	M		not null )
	
Index On  numero for not Deleted() tag numero candidate 

Create Table recepcion_TALLER_det NAME recepcion_TALLER_det (;
	numero			N(10)	NOT NULL ,;
	estado			C(1)	not null ,;
	articulo		C(15)	not null ,;
	unidades		n(11,2)	not null ,;
	saldo			n(11,2)	not null ,;	
	actualizacion  	T		not null DEFAULT Datetime() )
	
Index On  numero for not Deleted() tag numero 
Index On Str(NUMERO)+ARTICULO TAG ARTICULO for not Deleted()

Alter Table sucursales add ultimo_recepcion_TALLER n(10) not null