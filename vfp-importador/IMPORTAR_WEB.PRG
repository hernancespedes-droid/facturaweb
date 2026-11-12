*==============================================================================
* IMPORTAR_WEB.PRG  -  Graba en el VFP lo que se emitio en FacturaWeb
*
* El agente (FacturaWebAgente.ps1) baja de la web los lotes pendientes a  <intercambio>\entrada\lote_*.txt .
* Este programa los lee y graba, con las reglas del VFP (asi los indices .cdx quedan bien):
*   SUBDIARIO, IVA, ESTADISTICA, CAJA, CTACT (comprobantes)  y  STOCK + MVSTK_CABECERA + MVSTK_ITEMS (movimientos de stock).
* Despues deja el resultado de cada comprobante en  <intercambio>\salida\resultado_<lote>.txt  (OK o ERR + motivo) y el agente se lo devuelve a la web.
*
* USO (con la base de datos del sistema abierta, igual que cualquier otra opcion del menu):
*     DO IMPORTAR_WEB                              importa todos los lotes de la carpeta de intercambio
*     DO IMPORTAR_WEB WITH .T.                     SIMULA: revisa todo y muestra que haria, sin grabar nada
*     DO IMPORTAR_WEB WITH .F., "D:\otra\carpeta"  usa otra carpeta de intercambio
*
* PROBAR PRIMERO EN UNA COPIA DE LOS DATOS (y con SIMULAR). Hace una transaccion por comprobante: si algo falla, ese comprobante no queda grabado a medias.
* Requisitos: VFP 9, tablas dentro de la base de datos (datos.dbc), SET MULTILOCKS ON (el programa lo activa).
*==============================================================================
LPARAMETERS plSimular, pcCarpeta

#DEFINE CARPETA_POR_OMISION  "C:\FacturaWebAgente\intercambio"
#DEFINE TIPO_BAJA  "BS"
#DEFINE TIPO_ALTA  "AS"

LOCAL lcCarpeta, lcPuntoAntes, lcMultiAntes, lnArchivos, lnOk, lnErr, lnYa, i, laArch[1], lcArch
lcCarpeta = IIF(EMPTY(pcCarpeta), CARPETA_POR_OMISION, pcCarpeta)
lcCarpeta = ADDBS(lcCarpeta)
IF !DIRECTORY(lcCarpeta + "entrada")
	MESSAGEBOX("No encuentro la carpeta " + lcCarpeta + "entrada" + CHR(13) + "Revisa la carpeta de intercambio del agente.", 48, "Importar de la web")
	RETURN .F.
ENDIF
IF !DIRECTORY(lcCarpeta + "entrada\procesados")
	MKDIR (lcCarpeta + "entrada\procesados")
ENDIF
IF !DIRECTORY(lcCarpeta + "salida")
	MKDIR (lcCarpeta + "salida")
ENDIF

* Los numeros del archivo vienen con punto decimal: VAL() depende de SET POINT.
lcPuntoAntes = SET("POINT")
lcMultiAntes = SET("MULTILOCKS")
SET POINT TO "."
SET MULTILOCKS ON

PRIVATE pcLog, pcUltimoError
pcLog = ""
pcUltimoError = ""
lnOk = 0
lnErr = 0
lnYa = 0

TRY
	IF !AbrirTablas()
		pcLog = pcLog + "No se pudieron abrir las tablas del VFP." + CHR(13)
	ELSE
		lnArchivos = ADIR(laArch, lcCarpeta + "entrada\lote_*.txt")
		IF lnArchivos = 0
			pcLog = pcLog + "No hay lotes para importar." + CHR(13)
		ENDIF
		FOR i = 1 TO lnArchivos
			lcArch = laArch[i, 1]
			ImportarLote(lcCarpeta, lcArch, plSimular, @lnOk, @lnErr, @lnYa)
		ENDFOR
	ENDIF
CATCH TO loEx
	pcLog = pcLog + "ERROR GENERAL: " + loEx.Message + CHR(13)
ENDTRY

CerrarTablas()
SET POINT TO (lcPuntoAntes)
IF lcMultiAntes = "OFF"
	SET MULTILOCKS OFF
ENDIF

MESSAGEBOX(IIF(plSimular, "SIMULACION (no se grabo nada)" + CHR(13), "") + ;
	"Grabados: " + TRANSFORM(lnOk) + CHR(13) + "Ya estaban: " + TRANSFORM(lnYa) + CHR(13) + "Con error: " + TRANSFORM(lnErr) + CHR(13) + CHR(13) + pcLog, ;
	IIF(lnErr > 0, 48, 64), "Importar de la web")
RETURN (lnErr = 0)

*------------------------------------------------------------------------------
FUNCTION AbrirTablas()
	LOCAL llOk
	llOk = .T.
	TRY
		USE subdiario IN 0 SHARED AGAIN ALIAS w_sub
		USE iva IN 0 SHARED AGAIN ALIAS w_iva
		USE estadistica IN 0 SHARED AGAIN ALIAS w_est
		USE caja IN 0 SHARED AGAIN ALIAS w_caja
		USE ctact IN 0 SHARED AGAIN ALIAS w_ctact
		USE sucursales IN 0 SHARED AGAIN ALIAS w_suc
		USE stock IN 0 SHARED AGAIN ALIAS w_stock
		USE mvstk_cabecera IN 0 SHARED AGAIN ALIAS w_mvc
		USE mvstk_items IN 0 SHARED AGAIN ALIAS w_mvi
		USE tipos_mvstk IN 0 SHARED AGAIN ALIAS w_tmv
		USE parametros IN 0 SHARED AGAIN ALIAS w_par
	CATCH TO loEx
		pcLog = pcLog + "No pude abrir una tabla: " + loEx.Message + CHR(13)
		llOk = .F.
	ENDTRY
	RETURN llOk
ENDFUNC

FUNCTION CerrarTablas()
	LOCAL i, laA[11]
	laA[1] = "w_sub"
	laA[2] = "w_iva"
	laA[3] = "w_est"
	laA[4] = "w_caja"
	laA[5] = "w_ctact"
	laA[6] = "w_suc"
	laA[7] = "w_stock"
	laA[8] = "w_mvc"
	laA[9] = "w_mvi"
	laA[10] = "w_tmv"
	laA[11] = "w_par"
	FOR i = 1 TO 11
		IF USED(laA[i])
			USE IN (laA[i])
		ENDIF
	ENDFOR
	IF USED("c_reg")
		USE IN c_reg
	ENDIF
ENDFUNC

*------------------------------------------------------------------------------
* Importa un lote: arma un cursor con sus registros y graba comprobante por comprobante.
FUNCTION ImportarLote(tcCarpeta, tcArchivo, tlSimular, tnOk, tnErr, tnYa)
	LOCAL lcTexto, laL[1], nL, i, laP[1], nP, lcRes, lcLote, nId, nSub, cTipo, lnRes, llYa
	lcLote = JUSTSTEM(tcArchivo)
	lcTexto = STRCONV(FILETOSTR(tcCarpeta + "entrada\" + tcArchivo), 11)   && el archivo es UTF-8; el VFP trabaja en ANSI
	nL = ALINES(laL, lcTexto, 1 + 2)                                       && sin espacios sobrantes ni lineas vacias
	IF nL = 0 OR LEFT(laL[1], 12) <> "#FACTURAWEB"
		pcLog = pcLog + tcArchivo + ": no es un lote de FacturaWeb." + CHR(13)
		tnErr = tnErr + 1
		RETURN .F.
	ENDIF

	* Cursor con los registros: tipo (S I E J T M), id del comprobante (o del movimiento), id del comprobante al que cuelga (M) y la linea.
	IF USED("c_reg")
		USE IN c_reg
	ENDIF
	CREATE CURSOR c_reg (seq N(6), tipo C(1), id N(12), subid N(12), linea M)
	FOR i = 2 TO nL
		nP = ALINES(laP, laL[i], 0, "|")
		IF nP < 2
			LOOP
		ENDIF
		nId = VAL(laP[2])
		nSub = nId
		IF laP[1] = "M"
			nSub = VAL(Valor(laL[i], "subdiario_id"))
		ENDIF
		INSERT INTO c_reg (seq, tipo, id, subid, linea) VALUES (i, laP[1], nId, nSub, laL[i])
	ENDFOR

	lcRes = ""
	* 1) Comprobantes con sus hijas y su stock
	SELECT c_reg
	SCAN FOR tipo = "S"
		nId = c_reg.id
		llYa = .F.
		lnRes = GrabarComprobante(nId, tlSimular, @llYa)
		IF lnRes = 0
			lcRes = lcRes + "OK|S|" + TRANSFORM(nId) + CHR(13) + CHR(10)
			IF llYa
				tnYa = tnYa + 1
			ELSE
				tnOk = tnOk + 1
			ENDIF
		ELSE
			lcRes = lcRes + "ERR|S|" + TRANSFORM(nId) + "|" + Limpio(pcUltimoError) + CHR(13) + CHR(10)
			pcLog = pcLog + "Comprobante web " + TRANSFORM(nId) + ": " + pcUltimoError + CHR(13)
			tnErr = tnErr + 1
		ENDIF
		SELECT c_reg
	ENDSCAN
	* 2) Movimientos de stock sueltos (ingresos, egresos, ajustes, transferencias)
	SELECT c_reg
	SCAN FOR tipo = "M" AND subid = 0
		nId = c_reg.id
		lnRes = GrabarMovimientoSuelto(c_reg.linea, nId, tlSimular)
		IF lnRes = 0
			lcRes = lcRes + "OK|M|" + TRANSFORM(nId) + CHR(13) + CHR(10)
			tnOk = tnOk + 1
		ELSE
			lcRes = lcRes + "ERR|M|" + TRANSFORM(nId) + "|" + Limpio(pcUltimoError) + CHR(13) + CHR(10)
			pcLog = pcLog + "Movimiento web " + TRANSFORM(nId) + ": " + pcUltimoError + CHR(13)
			tnErr = tnErr + 1
		ENDIF
		SELECT c_reg
	ENDSCAN

	IF !tlSimular
		STRTOFILE(lcRes, tcCarpeta + "salida\resultado_" + lcLote + ".txt")
		RENAME (tcCarpeta + "entrada\" + tcArchivo) TO (tcCarpeta + "entrada\procesados\" + tcArchivo)
	ENDIF
	USE IN c_reg
	RETURN .T.
ENDFUNC

*------------------------------------------------------------------------------
* Graba un comprobante (S) con sus hijas y el stock que movio, en una transaccion. Devuelve 0 si salio bien, 1 si no (pcUltimoError dice por que).
* Los registros se leen con consultas SQL a cursores propios: asi no se mueve el puntero del cursor c_reg que recorre quien llama.
FUNCTION GrabarComprobante(tnId, tlSimular, tlYa)
	LOCAL lcS, dFecha, cTipo, cLetra, nSuc, nPref, nCbte, nMv, laC[1], lcObs
	pcUltimoError = ""
	SELECT linea FROM c_reg WHERE tipo = "S" AND id = tnId INTO CURSOR c_s
	lcS = c_s.linea
	USE IN c_s
	cTipo = Valor(lcS, "tipo")
	cLetra = Valor(lcS, "letra")
	nSuc = VAL(Valor(lcS, "sucursal"))
	nPref = VAL(Valor(lcS, "prefijo"))
	nCbte = VAL(Valor(lcS, "comprobante"))
	dFecha = Convertir(Valor(lcS, "fecha"), "D")

	* La sucursal tiene que existir en el VFP (Tablas > Sucursales).
	SELECT w_suc
	LOCATE FOR codigo = nSuc
	IF !FOUND()
		pcUltimoError = "No existe la sucursal " + TRANSFORM(nSuc) + " en el VFP (cargala en Tablas > Sucursales)"
		RETURN 1
	ENDIF
	* Si ya esta (mismo tipo, letra, sucursal, punto de venta, numero y fecha) no se repite.
	SELECT w_sub
	LOCATE FOR tipo = cTipo AND letra = cLetra AND sucursal = nSuc AND prefijo = nPref AND comprobante = nCbte AND fecha = dFecha
	IF FOUND()
		tlYa = .T.
		RETURN 0
	ENDIF
	IF tlSimular
		RETURN 0
	ENDIF

	TRY
		BEGIN TRANSACTION
		* subdiario (queda como registro actual de w_sub)
		GrabarFila("w_sub", lcS)
		* hijas, en el orden del archivo
		SELECT * FROM c_reg WHERE id = tnId AND tipo IN ("I", "E", "J", "T") ORDER BY seq INTO CURSOR c_hij
		SELECT c_hij
		SCAN
			DO CASE
			CASE c_hij.tipo = "I"
				GrabarFila("w_iva", c_hij.linea)
			CASE c_hij.tipo = "E"
				GrabarFila("w_est", c_hij.linea)
			CASE c_hij.tipo = "J"
				GrabarFila("w_caja", c_hij.linea)
			CASE c_hij.tipo = "T"
				GrabarFila("w_ctact", c_hij.linea)
			ENDCASE
			SELECT c_hij
		ENDSCAN
		USE IN c_hij
		* stock del comprobante: un movimiento de stock del VFP con todos sus renglones
		SELECT COUNT(*) FROM c_reg WHERE tipo = "M" AND subid = tnId INTO ARRAY laC
		IF laC[1] > 0
			lcObs = cTipo + " " + cLetra + " " + PADL(nPref, 4, "0") + "-" + PADL(nCbte, 8, "0") + " (web)"
			nMv = GrabarMovimientos(tnId, 0, nCbte, cLetra, lcObs)
			* el comprobante del VFP apunta a su movimiento de stock
			IF TYPE("w_sub.mvstk") <> "U"
				REPLACE mvstk WITH nMv IN w_sub
			ENDIF
		ENDIF
		END TRANSACTION
	CATCH TO loEx
		IF TXNLEVEL() > 0
			ROLLBACK
		ENDIF
		IF USED("c_hij")
			USE IN c_hij
		ENDIF
		IF USED("c_mov")
			USE IN c_mov
		ENDIF
		pcUltimoError = loEx.Message + " (linea " + TRANSFORM(loEx.LineNo) + ")"
		RETURN 1
	ENDTRY
	RETURN 0
ENDFUNC

*------------------------------------------------------------------------------
* Un movimiento de stock que no es de un comprobante (ingreso, egreso, ajuste, transferencia).
FUNCTION GrabarMovimientoSuelto(tcLinea, tnIdMov, tlSimular)
	pcUltimoError = ""
	IF tlSimular
		RETURN 0
	ENDIF
	TRY
		BEGIN TRANSACTION
		GrabarMovimientos(0, tnIdMov, 0, "", "Web: " + LEFT(Valor(tcLinea, "origen") + " " + Valor(tcLinea, "motivo"), 34))
		END TRANSACTION
	CATCH TO loEx
		IF TXNLEVEL() > 0
			ROLLBACK
		ENDIF
		IF USED("c_mov")
			USE IN c_mov
		ENDIF
		pcUltimoError = loEx.Message + " (linea " + TRANSFORM(loEx.LineNo) + ")"
		RETURN 1
	ENDTRY
	RETURN 0
ENDFUNC

*------------------------------------------------------------------------------
* Graba un movimiento de stock del VFP (MVSTK_CABECERA + MVSTK_ITEMS) y actualiza STOCK.
*   tnSub > 0 : todos los renglones "M" que cuelgan del comprobante con ese id
*   tnSub = 0 : el unico renglon "M" cuyo id es tnIdMov (movimiento suelto)
* Devuelve el numero de movimiento del VFP. Si algo falla, levanta el error (quien llama deshace la transaccion).
FUNCTION GrabarMovimientos(tnSub, tnIdMov, tnCbte, tcLetra, tcObs)
	LOCAL nNum, nSuc, nCant, cArt, nRen, cTipoMv, cSigno, dFecha, lcM
	IF !SEEK_TIPO(TIPO_BAJA) OR !SEEK_TIPO(TIPO_ALTA)
		ERROR "Falta el tipo de movimiento de stock BS o AS en TIPOS_MVSTK."
	ENDIF
	* numero nuevo: PARAMETROS.ULTIMO_MVSTK + 1, con la fila bloqueada
	SELECT w_par
	GO TOP
	IF !RLOCK("w_par")
		ERROR "No pude bloquear PARAMETROS para numerar el movimiento de stock."
	ENDIF
	REPLACE ultimo_mvstk WITH ultimo_mvstk + 1 IN w_par
	nNum = w_par.ultimo_mvstk
	UNLOCK IN w_par

	IF tnSub > 0
		SELECT * FROM c_reg WHERE tipo = "M" AND subid = tnSub ORDER BY seq INTO CURSOR c_mov
	ELSE
		SELECT * FROM c_reg WHERE tipo = "M" AND id = tnIdMov AND subid = 0 ORDER BY seq INTO CURSOR c_mov
	ENDIF
	nRen = 0
	nSuc = 0
	cSigno = "+"
	dFecha = DATE()
	SELECT c_mov
	SCAN
		lcM = c_mov.linea
		nCant = VAL(Valor(lcM, "cantidad"))
		cArt = UPPER(Valor(lcM, "articulo"))
		IF nRen = 0
			nSuc = VAL(Valor(lcM, "sucursal"))
			cSigno = IIF(nCant < 0, "-", "+")
			dFecha = Convertir(LEFT(Valor(lcM, "fecha"), 10), "D")
		ENDIF
		nRen = nRen + 1
		* renglon del movimiento (siempre cantidades positivas: el signo lo da el tipo)
		SELECT w_mvi
		APPEND BLANK
		REPLACE numero_mvstk WITH nNum, articulo WITH cArt, cantidad_unidades WITH ABS(nCant), cantidad_kilos WITH 0 IN w_mvi
		IF TYPE("w_mvi.actualizacion") <> "U"
			REPLACE actualizacion WITH DATETIME() IN w_mvi
		ENDIF
		* saldo del VFP: STOCK por sucursal + articulo
		SELECT w_stock
		LOCATE FOR sucursal = nSuc AND articulo = cArt
		IF !FOUND()
			APPEND BLANK
			REPLACE sucursal WITH nSuc, articulo WITH cArt, unidades WITH 0, kilos WITH 0 IN w_stock
		ENDIF
		REPLACE unidades WITH unidades + nCant IN w_stock
		IF TYPE("w_stock.actualizacion") <> "U"
			REPLACE actualizacion WITH DATETIME() IN w_stock
		ENDIF
		SELECT c_mov
	ENDSCAN
	USE IN c_mov
	cTipoMv = IIF(cSigno = "-", TIPO_BAJA, TIPO_ALTA)

	SELECT w_mvc
	APPEND BLANK
	REPLACE numero_mvstk WITH nNum, fecha WITH dFecha, tipo WITH cTipoMv, sucursal WITH nSuc, numero WITH tnCbte, proveedor WITH 0, ;
		origen WITH nSuc, signo_origen WITH cSigno, destino WITH 0, signo_destino WITH "", observaciones WITH LEFT(tcObs, 40), renglones WITH nRen IN w_mvc
	IF TYPE("w_mvc.letra") <> "U"
		REPLACE letra WITH LEFT(tcLetra, 1) IN w_mvc
	ENDIF
	IF TYPE("w_mvc.actualizacion") <> "U"
		REPLACE actualizacion WITH DATETIME() IN w_mvc
	ENDIF
	RETURN nNum
ENDFUNC

FUNCTION SEEK_TIPO(tcCodigo)
	SELECT w_tmv
	LOCATE FOR ALLTRIM(codigo) == tcCodigo
	RETURN FOUND()
ENDFUNC

*------------------------------------------------------------------------------
* Agrega una fila a la tabla y completa los campos que vienen en la linea (campo=valor|campo=valor...). Los campos que el VFP no tiene se ignoran;
* los numeros internos autoincrementales (FE_CLAVE) los pone el VFP.
FUNCTION GrabarFila(tcAlias, tcLinea)
	LOCAL laP[1], nP, i, nPos, lcCampo, lcVal, lcTipo, xVal
	nP = ALINES(laP, tcLinea, 0, "|")
	SELECT (tcAlias)
	APPEND BLANK
	FOR i = 3 TO nP
		nPos = AT("=", laP[i])
		IF nPos = 0
			LOOP
		ENDIF
		lcCampo = LOWER(LEFT(laP[i], nPos - 1))
		lcVal = Decodificar(SUBSTR(laP[i], nPos + 1))
		lcTipo = TYPE(tcAlias + "." + lcCampo)
		IF lcTipo = "U"
			LOOP
		ENDIF
		xVal = Convertir(lcVal, lcTipo)
		REPLACE &lcCampo WITH xVal IN (tcAlias)
	ENDFOR
	IF TYPE(tcAlias + ".actualizacion") <> "U"
		REPLACE actualizacion WITH DATETIME() IN (tcAlias)
	ENDIF
ENDFUNC

* Valor de un campo de una linea del archivo (ya decodificado). Vacio si no esta.
FUNCTION Valor(tcLinea, tcCampo)
	LOCAL lcBusca, nPos, nFin, lcV
	lcBusca = "|" + tcCampo + "="
	nPos = ATC(lcBusca, tcLinea)
	IF nPos = 0
		RETURN ""
	ENDIF
	lcV = SUBSTR(tcLinea, nPos + LEN(lcBusca))
	nFin = AT("|", lcV)
	IF nFin > 0
		lcV = LEFT(lcV, nFin - 1)
	ENDIF
	RETURN Decodificar(lcV)
ENDFUNC

FUNCTION Decodificar(tcV)
	LOCAL lc
	lc = tcV
	lc = STRTRAN(lc, "%0D", CHR(13))
	lc = STRTRAN(lc, "%0A", CHR(10))
	lc = STRTRAN(lc, "%3D", "=")
	lc = STRTRAN(lc, "%7C", "|")
	lc = STRTRAN(lc, "%25", "%")
	RETURN lc
ENDFUNC

* Convierte el texto del archivo al tipo del campo del VFP.
FUNCTION Convertir(tcVal, tcTipo)
	LOCAL nA, nM, nD, nH, nMi, nS
	DO CASE
	CASE INLIST(tcTipo, "C", "M")
		RETURN tcVal
	CASE INLIST(tcTipo, "N", "F", "B", "Y")
		RETURN IIF(EMPTY(tcVal), 0, VAL(tcVal))
	CASE tcTipo = "I"
		RETURN IIF(EMPTY(tcVal), 0, INT(VAL(tcVal)))
	CASE tcTipo = "L"
		RETURN INLIST(LOWER(ALLTRIM(tcVal)), "1", "t", "true", ".t.")
	CASE tcTipo = "D"
		IF EMPTY(tcVal) OR LEN(tcVal) < 10
			RETURN {}
		ENDIF
		RETURN DATE(VAL(LEFT(tcVal, 4)), VAL(SUBSTR(tcVal, 6, 2)), VAL(SUBSTR(tcVal, 9, 2)))
	CASE tcTipo = "T"
		IF EMPTY(tcVal) OR LEN(tcVal) < 10
			RETURN {}
		ENDIF
		nH = 0
		nMi = 0
		nS = 0
		IF LEN(tcVal) >= 19
			nH = VAL(SUBSTR(tcVal, 12, 2))
			nMi = VAL(SUBSTR(tcVal, 15, 2))
			nS = VAL(SUBSTR(tcVal, 18, 2))
		ENDIF
		RETURN DATETIME(VAL(LEFT(tcVal, 4)), VAL(SUBSTR(tcVal, 6, 2)), VAL(SUBSTR(tcVal, 9, 2)), nH, nMi, nS)
	ENDCASE
	RETURN tcVal
ENDFUNC

* Texto del error para el resultado: una sola linea, sin | ni acentos (el resultado se manda a la web como texto simple).
FUNCTION Limpio(tcT)
	LOCAL lc, i, lcC, lcOut
	lc = STRTRAN(STRTRAN(STRTRAN(tcT, CHR(13), " "), CHR(10), " "), "|", "/")
	lc = STRTRAN(lc, "%", " ")
	lcOut = ""
	FOR i = 1 TO LEN(lc)
		lcC = SUBSTR(lc, i, 1)
		lcOut = lcOut + IIF(BETWEEN(ASC(lcC), 32, 126), lcC, "?")
	ENDFOR
	RETURN LEFT(lcOut, 240)
ENDFUNC
