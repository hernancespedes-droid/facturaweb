cc = "LUIS E RUBIO"

cc = ALLTRIM(cc)
ini = 200 + INT(RAND() * 15)
osi = INT(RAND() * 50)
par = ""
imp = ""
res = ""
tot = ""

FOR ii = 1 TO LEN(cc)
    IF MOD(ii,2) <> 0
       imp = imp + SUBSTR(cc,ii,1)
    ELSE
       par = par + SUBSTR(cc,ii,1)
    ENDIF
NEXT

IF MOD(osi,2) <> 0
   tot = imp + par
ELSE   
   tot = par + imp 
ENDIF
   
base = 0
FOR ii = 1 TO LEN(tot)
    base = ASC(SUBSTR(tot,ii,1)) + osi + ii
    base = IIF(base > 255,base - 256,base)
    
    res = res + CHR(base)
NEXT

res = res + CHR(osi)
INSERT INTO cur VALUES (res)