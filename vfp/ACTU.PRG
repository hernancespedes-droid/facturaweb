PARAMETERS P_tabla
LOCAL lin1, lin2

lin1 = 'REPLACE ' + P_tabla + '.actualizacion WITH DATETIME()'
lin2 = 'TABLEUPDATE(2,.T.,"' + P_tabla + '")'

&lin1
&lin2