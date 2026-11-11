&& CLASSLIB.PRG
&& Clase usada para Mostrar / Ocultar los ToolBar que estan definidos.
&& Los que no estan definidos, no son tratados
&& Autor : Ernesto

DEFINE CLASS MostOcultToolBars AS CUSTOM
   PROTECTED laToolBars[1]
   
   PROTECTED FUNCTION INIT()
      DIMENSION THIS.laToolBars[22]
      THIS.laToolBars[1]="Form Designer"
      THIS.laToolBars[2]="Standard"
      THIS.laToolBars[3]="Layout"
      THIS.laToolBars[4]="Query Designer"
      THIS.laToolBars[5]="View Designer"
      THIS.laToolBars[6]="Color Palette"
      THIS.laToolBars[7]="Form Controls"
      THIS.laToolBars[8]="Database Designer"
      THIS.laToolBars[9]="Report Designer"
      THIS.laToolBars[10]="Report Controls"
      THIS.laToolBars[11]="Print Preview"
      
      THIS.laToolBars[12]="Controles de formularios"
      THIS.laToolBars[13]="Controles de informes"
      THIS.laToolBars[14]="Diseñador de bases de datos"
      THIS.laToolBars[15]="Diseñador de consultas"
      THIS.laToolBars[16]="Diseñador de formularios"
      THIS.laToolBars[17]="Diseñador de informes"
      THIS.laToolBars[18]="Diseñador de vistas"
      THIS.laToolBars[19]="Distribución"
      THIS.laToolBars[20]="Estándar"
      THIS.laToolBars[21]="Paleta de colores"
      THIS.laToolBars[22]="Vista preliminar"      
   ENDFUNC

&& Por Default, oculto todos los toolbars, sino uno solo
   FUNCTION Ocultar(tcCualToolBar)
      LOCAL i, lnInd
      IF !EMPTY(tcCualToolBar)
         lnInd=ASCAN( THIS.laToolBars, tcCualToolBar)
         IF lnInd # 0
            IF WVISIBLE(THIS.laToolBars[lnInd])
               HIDE WINDOW (THIS.laToolBars[lnInd])
            ENDIF
         ENDIF
      ELSE
         FOR i= 1 TO ALEN( THIS.laToolBars, 1)
            IF WVISIBLE(THIS.laToolBars[i])
               HIDE WINDOW (THIS.laToolBars[i])
            ENDIF
         ENDFOR
      ENDIF
   ENDFUNC

&& Por Default, muestro todos los toolbars, sino uno solo
   FUNCTION Mostrar(tcCualToolBar)
      LOCAL i, lnInd
      IF !EMPTY(tcCualToolBar)
         lnInd=ASCAN( THIS.laToolBars, tcCualToolBar)
         IF lnInd # 0
            IF WEXIST(THIS.laToolBars[lnInd]) AND ;
              !WVISIBLE(THIS.laToolBars[lnInd])
               SHOW WINDOW (THIS.laToolBars[lnInd])
            ENDIF
         ENDIF
      ELSE
         FOR i= 1 TO ALEN( THIS.laToolBars, 1)
            IF WEXIST(THIS.laToolBars[i]) AND ;
              !WVISIBLE(THIS.laToolBars[i])
               SHOW WINDOW (THIS.laToolBars[i])
            ENDIF
         ENDFOR
      ENDIF
   ENDFUNC
   
ENDDEFINE
