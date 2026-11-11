*---Ver Accesso---
PARAMETERS opMenu
*--Control---
IF _usuario='SUPERVISOR'
	RETURN .F.
ENDIF	
*--Var--
Local lbRet,lnPos
*---Proceso---
lnPos=Ascan(gaMenu,opMenu)
If lnPos > 0
	*wait window gaMenu(asubscrip(gaMenu,lnPos,1),1)
	lbRet= gaMenu(Asubscrip(gaMenu,lnPos,1),2)
Endif
*--------------
RETURN lbRet