*----Levantar a Array----
Public gaMenu
Dimension gaMenu(1,2)
Store "" To gaMenu
If !Used('CLA_ACCESOS')
	Select 0					&&Esta aca por si algun form lo cerro
	Use CLAVES!CLA_ACCESOS
Else
	Select CLA_ACCESOS
Endif
*---Proc---
Scan For nombre=_usuario
	gaMenu(Alen(gaMenu,1),1)=Alltrim(CLA_ACCESOS.programa)
	gaMenu(Alen(gaMenu,1),2)=!CLA_ACCESOS.acceso
	Dimension gaMenu(Alen(gaMenu,1)+1,2)
Endscan
*---------------


