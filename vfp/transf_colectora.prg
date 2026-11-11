mens = 'Este Proceso Generará un Archivo de Texto con '+CHR(13)+;
  'el detalle de todos los Artículos que posean'+CHR(13)+;
  'Código de Barras y no estén Duplicados.  Los'+CHR(13)+;
  'mismos serán utilizados por la colectora de '+CHR(13)+;
  'datos para inventariar Stock.'+CHR(13)+;
  'Confirma este Proceso?'
r=MESSAGEBOX(mens,4+32+0,'Colectora de Datos')
IF r # 6  && 6 = si
  RETURN
ENDIF

=seteos()
OPEN data datos
SELECT 0
CREATE CURSOR detalle (barras N(13), descri C(30))
INDEX ON barras to barras

SELECT 0
USE articulos
SCAN
  IF !EMPTY(codigo_de_barras)
    IF !SEEK(codigo_de_barras,"DETALLE","BARRAS")
      INSERT INTO detalle (barras, descri);
        VALUES (articulos.codigo_de_barras,LEFT(articulos.descripcion,30))
    ENDIF
  ENDIF
ENDSCAN

SELECT detalle
COPY TO maestro.yuk DELIMITED WITH CHARACTER ";"

CLOSE DATA

mens = 'Proceso finalizado exitosamente !!!'
r=MESSAGEBOX(mens,0+48+0,'Colectora de Datos')

