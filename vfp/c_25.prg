Set SAFETY OFF
Set TALK OFF
Set DELE ON
Close TABLES ALL
Close DATA ALL
Open DATA datos EXCLUSIVE

Create TABLE 'modelos.DBF' NAME 'modelos' ( ;
	CODIGO               C(4) NOT NULL , ;
	DESCRIPCION    	 	 C(40) NOT NULL, ;
	talle_minimo 		 n(2) not null ,;
	talle_maximo 		 n(2) not null ,;	
	talle_prmedio 		 n(2) not null ,;	
	incremento_por_talle n(1) not null ,;	
	variacion_por_talle  n(8,4) not null ;	
	)
Set COLLATE TO 'MACHINE'

Alter Table modelos add primary key codigo  for .NOT.DELETED() TAG CODIGO 
Index ON descripcion TAG DESCRIP  for .NOT.DELETED()

Create view V_modelos as ;
	select * from DATOS!modelos ;
		order by modelos.descripcion   
