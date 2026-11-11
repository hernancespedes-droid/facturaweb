CLOSE ALL
OPEN DATABASE datos EXCLUSIVE
nn=2000000
USE mvstk_cabecera IN 0
USE mvstk_items IN 0
USE parametros IN 0


replace ALL numero_mvstk WITH numero_mvstk+nn IN mvstk_cabecera
replace ALL numero_mvstk WITH numero_mvstk+nn IN mvstk_items
replace  ultimo_mvstk WITH ultimo_mvstk+nn IN parametros
