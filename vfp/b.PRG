CLEAR

@ 1,1 SAY CHR(32) + " 32"
@ 2,1 SAY CHR(34) + " 34"
@ 3,1 SAY CHR(35) + " 35"
@ 4,1 SAY CHR(36) + " 36"
@ 5,1 SAY CHR(38) + " 38"
@ 6,1 SAY CHR(39) + " 39"
@ 7,1 SAY CHR(40) + " 40"
@ 8,1 SAY CHR(42) + " 42"
@ 9,1 SAY CHR(41) + " 41"
@ 10,1 SAY CHR(43) + " 43"
@ 11,1 SAY CHR(44) + " 44"
@ 12,1 SAY CHR(45) + " 45"
@ 13,1 SAY CHR(46) + " 46"
@ 14,1 SAY CHR(47) + " 47"
@ 15,1 SAY CHR(92) + " 92"
@ 16,1 SAY CHR(126) + " 126"
@ 17,1 SAY CHR(165) + " 165"
@ 18,1 SAY CHR(167) + " 167"
@ 19,1 SAY CHR(181) + " 181"
@ 20,1 SAY CHR(190) + " 190"
@ 21,1 SAY CHR(214) + " 214"
@ 22,1 SAY CHR(233) + " 233"
@ 23,1 SAY CHR(245) + " 245"


*SELECT * FROM articulos where fun_(articulos.descripcion)


FUNCTION fun_
PARAMETERS des
LOCAL i, ret

ret = .F.
des = ALLTRIM(des)

FOR i = 1 TO LEN(des)
    IF !(ASC(SUBSTR(des,i,1)) >= 65 AND ASC(SUBSTR(des,i,1)) <= 90) AND ;
       !(ASC(SUBSTR(des,i,1)) >= 97 AND ASC(SUBSTR(des,i,1)) <= 122) AND ;
       !INLIST(ASC(SUBSTR(des,i,1)),32,34,35,36,38,39,40,42,41,43,44,45,46,47,92,126,165,167,181,190,214,233,245) AND;
       !(ASC(SUBSTR(des,i,1)) >= 48 AND ASC(SUBSTR(des,i,1)) <= 57)
       ret = .T.
    ENDIF   
NEXT

RETURN ret