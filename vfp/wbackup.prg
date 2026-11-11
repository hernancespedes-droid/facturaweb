mens = 'Este Proceso no es automático, deberá realizarlo desde'+CHR(13)+;
       'Programas\Accesorios\Herramientas del Sistema\Copia de Seguridad'+CHR(13)+;
       'con la opción Copia de Seguridad. Ante cualquier duda comunicarse'+CHR(13)+;
       'con Organización & Sistemas.'
r=MESSAGEBOX(mens,0+48+0,'Copia de Datos')
RETURN

xdir=CURDIR()
*!*	W95 en Español
xrun='run /n "c:\archivos de programa\accesorios\backup.exe" backup.set'
*!*	W95 en Ingles
*!*	xrun='run /n "c:\program files\accesories\backup.exe" backup.set'

=fireWinTask(xdir,xrun,'Microsoft Backup')

WAIT WINDOW ' Copia de seguridad finalizada.<ENTER> '

************************************************************
*  FUNCTION findWindowHandle()
************************************************************
*  Description.......: Returns the Window handle for window with exact
*                      matching title or 0 if not found
*  Parameter List....: lcWinTitle - window title to search for
FUNCTION findWindowHandle()
LPARAMETER tcWinTitle

DECLARE integer FindWindow in WIN32API string cNull, string cWinName
RETURN FindWindow(0,tcWinTitle)

ENDFUNC

************************************************************
*  FUNCTION setWindowFocus()
************************************************************
*  Description.......: Activates Window specified with the window handle
*  Parameter List....: thWinHandle - window handle to activate
FUNCTION setWindowFocus()
LPARAMETERS thWinHandle
LOCAL llWinNT, llWin31, llWin95, lhOldHandle

llWinNT=iif(file(getenv('WINDIR')+'\SYSTEM32\USER32.DLL'),.T.,.F.)
llWin31=iif(file(getenv('WINDIR')+'\SYSTEM\WIN32s\W32SCOMB.DLL'),.T.,.F.)
llWin95=iif(!llWinNT and file(getenv('WINDIR')+'\SYSTEM\USER32.DLL'),.T.,.F.)

IF llWin31
   DECLARE integer SetFocus in WIN32API integer hWinHandle
   lhOldHandle=SetFocus(thWinHandle)
ELSE
   DECLARE integer SetForegroundWindow in WIN32API integer hWinHandle
   lhOldHandle=SetForegroundWindow(thWinHandle)
ENDIF

RETURN lhOldHandle
ENDFUNC

************************************************************
*  FUNCTION fireWinTask()
************************************************************
*  Description.......: save pathing, setup for 'run' execution, reset pathing
*                    : and wait for syncronized completion by using findWindowHandle
*  Parameter List....: use newpath, newDefaultPath, run tcRunCommand and wait
*                      on winTitle
FUNCTION fireWinTask()
LPARAMETERS tcTargetsPath, tcRunCommand, tcWaitWindowTitle
LOCAL lcPath, lhWaitWindowHandle

lcPath=set('path')
SET path to &tcTargetsPath
WAIT WINDOW ' Se procedera a realizar una Copia de seguridad '+CHR(13)+;
            '    tenga a mano uno o mas diskettes. <ENTER>   ' AT 08,34

&tcRunCommand
=inkey(2) && allow target to get started

SET PATH TO &lcPath
                         
tcWaitWindowTitle1='Sin Título - Microsoft Backup'
tcWaitWindowTitle2='Backup - Microsoft Backup'
tcWaitWindowTitle3='Backup'

DO WHILE findWindowHandle(tcWaitWindowTitle)<>0 OR;
      findWindowHandle(tcWaitWindowTitle1)<>0 OR;
      findWindowHandle(tcWaitWindowTitle2)<>0 OR;
      findWindowHandle(tcWaitWindowTitle3)<>0
      *!*WAIT WINDOW NOWAIT ' Procesando... ' AT 16,35
   =inkey(1)
ENDDO

WAIT CLEAR

*-- Set window focus back to us
=setWindowFocus(findWindowHandle(_screen.caption))
RETURN .T.

ENDFUNC
