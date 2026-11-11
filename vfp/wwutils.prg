************************************************************************
* FUNCTION Utils
******************
***    Author: Rick Strahl
***            (c) West Wind Technologies, 1995-98
***   Contact: (541) 386-2087  / rstrahl@west-wind.com
***  Modified: 12/22/97
***  Function: A set of utility classes and functions used by 
***            the various classes and processing code.
*************************************************************************
#INCLUDE FOXPRO.H
#INCLUDE WCONNECT.H
*** Carriage Return/Line Break
#DEFINE CR					CHR(13)+CHR(10)
#DEFINE CRLF				CHR(13)+CHR(10)


*************************************************************************
****
**** STANDALONE FUNCTIONS
****
*************************************************************************



************************************************************************
FUNCTION File2Var
******************
***  Function: Takes a file and returns the contents as a string or
***            Takes a string and stores it in a file if a second
***            string parameter is specified.
***      Pass: tcFilename  -  Name of the file
***            tcString    -  If specified the string is stored
***                           in the file specified in tcFileName
***    Return: file contents as a string
************************************************************************
LPARAMETERS tcFileName, tcString
LOCAL lcRetVal, lnHandle, lnSize

tcFileName=IIF(EMPTY(tcFileName),"",tcFileName)

IF VARTYPE(tcString) # "C"
   *** File to Text
   lcRetVal=""
   
   *** Make sure file exists and can be opened for READ operation
   lnHandle=FOPEN(tcFileName,0)
   IF lnHandle#-1
     lnSize = FSEEK(lnHandle,0,2)
     FSEEK(lnHandle,0,0)
     lcRetVal=FREAD(lnHandle,lnSize)
     =FCLOSE(lnHandle)
   ENDIF
ELSE
   tcString=IIF(EMPTY(tcString),"",tcString)
   
   *** Text to File
   lnHandle=FCREATE(tcFileName)
   IF lnHandle=-1
      RETURN .F.
   ENDIF
   =FWRITE(lnHandle,tcString)
   =FCLOSE(lnHandle)
   RETURN .T.
ENDIF

RETURN lcRetVal
*EOP File2Var

***********************************************************
FUNCTION CopyObject
*******************
***    Author: Rick Strahl, West Wind Technologies
***            http://www.west-wind.com/
***  Function: Function that copies an object and all of 
***            it child object into a totally new object 
***            reference that is not based on a previous 
***            reference
***    Assume: Support for 1 dimensional arrays only
***      Pass: loObject - Existing object reference to copy
***    Return: New Object reference
***********************************************************
LPARAMETER loInput
LOCAL loObject, laFields[1], x, lcField, lcType, llClass, ;
      lnCount, z, lnLength

#DEFINE COPYOBJECT_PROPERTYEXCLUSIONLIST ;
 ",classlibrary,baseclass,comment,controls,objects,"+;
 "class,name,helpcontextid,whatsthishelpid," +;
 "tag,picture,onetomany,childalias,childorder,"+;
 "parent,parentclass,relationalexpr,controlcount,"

*** Check if we can instantiate input object class
IF TYPE("loInput.Class") = "C"
   *** If we have a class create it
   loObject = CREATEOBJECT(loInput.CLASS)
ELSE
   *** SCATTER NAME Objects don't have a class
   loObject = CREATE("Relation")
ENDIF

*** Grab member properties and loop through 'em
lnCount = AMEMBERS(laFields, loInput)
FOR x=1 TO lnCount
   lcField = LOWER(laFields[x])
   
   *** Ignore stock properties
   IF AT("," + lcField + ",","'" + ;
         COPYOBJECT_PROPERTYEXCLUSIONLIST) > 0
      LOOP
   ENDIF
   
   *** Grab the type
   lcType = TYPE("loInput."+lcField)

   DO CASE
      *** Must check for array properties first
      CASE TYPE([ALEN(loInput.] + lcField + [)]) = "N"
         *** Add property if it doesn't exist
         IF TYPE("loObject." + lcField) = "U"
            loObject.ADDPROPERTY(lcField + "[1]")
         ENDIF

         *** Create the array then run through
         *** NOTE: only 1d supported
         lnLength = ALEN(loInput.&lcField)
         DIMENSION loObject.&lcField[lnLength]
         FOR z=1 TO lnLength
            IF TYPE("loInput." +lcField + "[z]")="O"
               loObject.&lcField[z] = ;
                  CopyObject(EVAL( "loInput." + ;
                                   lcField + "[z]"))
            ELSE
               loObject.&lcField[z] = EVAL("loInput." + ;
                                           lcField)
            ENDIF
         ENDFOR
         
      *** Recursive calls for objects
      CASE lcType = "O"
         IF TYPE("loObject." + lcField) = "U"
            loObject.ADDPROPERTY(lcField)
         ENDIF

         loObject.&lcField = ;
                  CopyObject(EVAL("loInput."+lcField))
      OTHERWISE
         *** Check if property exists
         IF TYPE("loObject." + lcField) = "U"
            loObject.ADDPROPERTY(lcField)
         ENDIF

         *** Straight Assignment
         loObject.&lcField = EVAL("loInput." + lcField)
   ENDCASE
ENDFOR

RETURN loObject
* EOF CopyObject

************************************************************************
FUNCTION CursorToObjectArray
****************************
***  Function: Creates an array of objects of the currently open
***            cursor. The result is an object that has two properties
***            the count and the array of objects that contain the
***            records. The object has properties for each field
***            value (except general fields)
*** Parameter: lcClass  -  Optional class that has aRows and nCount 
***                        members         
***    Return: Object or .NULL. if no records in cursor
************************************************************************
LPARAMETER lcObjName
LOCAL x, laLItems[1]

*** Create object and set count and array properties
IF !EMPTY(lcObjName)
   loResult = CREATE("lcObjName")
ELSE   
	loResult = CREATE("RELATION")
	loResult.ADDPROPERTY("nCount")
	loResult.AddProperty("aRows(1)",1)
ENDIF

x = 0
SCAN
   x=x+1
   DIMENSION loResult.aRows[x]
   SCATTER NAME loResult.aRows[x] MEMO
ENDSCAN

loResult.nCount = x

RETURN loResult
*EOP CursorToObjectArray



************************************************************************
FUNCTION Extract
******************
***  Function: Extracts a text value between two delimiters
***    Assume: Delimiters are not checked for case!
***            The first instance only is retrieved. Idea is
***            to translate the delims as you go...
***      Pass: lcString   -  Entire string
***			   lcDelim1   -  The starting delimiter
***            lcDelim2	  -  Ending delimiter
***            lcDelim3	  -  Alternate ending delimiter
***            llEndOk	  -  End of line is OK
***    Return: Text between delimiters or ""
*************************************************************************
PARAMETERS lcString,lcDelim1,lcDelim2,lcDelim3, llEndOk
PRIVATE x,lnLocation,lcRetVal,lcChar,lnNewString,lnEnd

lcDelim1=IIF(LEN(lcDelim1)=0,",",lcDelim1)
lcDelim2=IIF(LEN(lcDelim2)=0,"z!x",lcDelim2)
lcDelim3=IIF(EMPTY(lcDelim3),"z!x",lcDelim3)

lnLocation=ATC(lcDelim1,lcString)
IF lnLocation=0
   RETURN ""
ENDIF

lnLocation=lnlocation+len(lcDelim1)

*** Crate a new string of remaining text
lcNewString=SUBSTR(lcString,lnLocation)

lnEnd=ATC(lcDelim2,lcNewString)
IF lnEnd>0
   RETURN SUBSTR(lcNewString,1,lnEnd-1)
ENDIF   
*!*	IF lnEnd = 0
*!*	   *** Empty Delimited string
*!*	   RETURN ""
*!*	ENDIF
   
lnEnd=ATC(lcDelim3,lcNewString)
IF lnEnd>0
   RETURN SUBSTR(lcNewString,1,lnEnd-1)
ENDIF   

IF llEndOk
  *** Return to the end of the line
  RETURN SUBSTR(lcNewString,1)
ENDIF

RETURN ""
*EOP RetValue

****************************************************
FUNCTION GoUrl
******************
***    Author: Rick Strahl
***            (c) West Wind Technologies, 1996
***   Contact: rstrahl@west-wind.com
***  Modified: 03/14/96
***  Function: Starts associated Web Browser
***            and goes to the specified URL.
***            If Browser is already open it
***            reloads the page.
***    Assume: Works only on Win95 and NT 4.0
***      Pass: tcUrl  - The URL of the site or
***                     HTML page to bring up
***                     in the Browser
***    Return: 2  - Bad Association (invalid URL)
***            31 - No application association
***            29 - Failure to load application
***            30 - Application is busy 
***
***            Values over 32 indicate success
***            and return an instance handle for
***            the application started (the browser) 
****************************************************
LPARAMETERS tcUrl, tcAction, tcDirectory

tcUrl=IIF(type("tcUrl")="C",tcUrl,;
          "http://www.west-wind.com/")
          
tcAction=IIF(type("tcAction")="C",tcAction,"OPEN")

tcDirectory=IIF(EMPTY(tcDirectory),SYS(2023),tcDirectory)

DECLARE INTEGER ShellExecute ;
    IN SHELL32.dll ;
    INTEGER nWinHandle,;
    STRING cOperation,;
    STRING cFileName,;
    STRING cParameters,;
    STRING cDirectory,;
    INTEGER nShowWindow

DECLARE INTEGER FindWindow ;
   IN WIN32API ;
   STRING cNull,STRING cWinName

RETURN ShellExecute(FindWindow(0,_SCREEN.caption),;
                    tcAction,tcUrl,;
                    "",tcDirectory,1)


************************************************************************
FUNCTION ShowHTML
*****************
***  Function: Takes an HTML string and displays it in the default
***            browser. 
***    Assume: Uses a file to store HTML temporarily.
***            For this reason there may be concurrency issues
***            unless you change the file for each use
***      Pass: lcHTML       -   HTML to display
***            lcFile       -   Temporary File to use (Optional)
***            loWebBrowser -   Web Browser control ref (Optional)
************************************************************************
LPARAMETERS lcHTML, lcFile, loWebBrowser

lcHTML=IIF(EMPTY(lcHTML),"",lcHTML)
lcFile=IIF(EMPTY(lcFile),SYS(2023)+"\ww_HTMLView.htm",lcFile)

File2Var(lcFile,lcHTML)

IF TYPE("loWebBrowser") = "O"
   loWebBrowser.Navigate(lcFile)
ELSE
   IF TYPE("_oscreenx") = "O"
      _oscreenx.Navigate(lcFile)
   ENDIF
*!*	   IF lower(JUSTEXT(lcFile)) = "txt"
*!*	      MODI COMM (lcFile) IN MACDESKTOP
*!*	   ELSE
      =GoUrl(lcFile)
*!*	   ENDIF
ENDIF   

RETURN
*EOP ShowHTML

************************************************************************
FUNCTION ShowXML
*****************
***  Function: Takes an XML string and displays it in the default
***            browser. 
***    Assume: Uses a file to store HTML temporarily.
***            For this reason there may be concurrency issues
***            unless you change the file for each use
***      Pass: lcHTML       -   HTML to display
***            lcFile       -   Temporary File to use (Optional)
***            loWebBrowser -   Web Browser control ref (Optional)
************************************************************************
LPARAMETERS lcHTML, lcFile, loWebBrowser
IF EMPTY(lcFile)
  lcFile=IIF(EMPTY(lcFile),SYS(2023)+"\ww_HTMLView.xml",lcFile)
ENDIF
RETURN ShowHTML(lcHTML,lcFile,loWebBrowser)

************************************************************************
FUNCTION ShowText
*****************
***  Function: Takes an XML string and displays it in the default
***            browser. 
***    Assume: Uses a file to store HTML temporarily.
***            For this reason there may be concurrency issues
***            unless you change the file for each use
***      Pass: lcHTML       -   HTML to display
***            lcFile       -   Temporary File to use (Optional)
***            loWebBrowser -   Web Browser control ref (Optional)
************************************************************************
LPARAMETERS lcHTML, lcFile, loWebBrowser
IF EMPTY(lcFile)
  lcFile=IIF(EMPTY(lcFile),SYS(2023)+"\ww_HTMLView.txt",lcFile)
ENDIF
RETURN ShowHTML(lcHTML,lcFile,loWebBrowser)


************************************************************************
FUNCTION IsWinnt
*****************
***      Pass: llReturnVersionNumber
***    Return: .t. or .f.   or Version Number or -1 if not NT
*************************************************************************
LPARAMETER llReturnVersionNumber

loAPI=CREATE("wwAPI")
lcVersion = loAPI.ReadRegistryString(HKEY_LOCAL_MACHINE,;
           "SOFTWARE\Microsoft\Windows NT\CurrentVersion",;
           "CurrentVersion")
                          
IF !llReturnVersionNumber
  IF ISNULL(lcVersion)
     RETURN .F.
  ELSE
     RETURN .T.
  ENDIF
ENDIF

IF ISNULL(lcVersion)
   RETURN -1
ENDIF

RETURN VAL(lcVersion)   
* IsWinNt


**************************************************
FUNCTION IsCOMObject
*********************
*** Function: Checks to see if a COM object 
***           or ActiveX control exists
***   Assume: Uses wwAPI
***     Pass: lcProgId   - Prog Id of the Class
***           lcClassId  - (Optional) If passed in 
***                        by reference gets ClassId
***           lcClassDescript - (Optional) by ref
***   Return: .T. or .F.
*****************************************************
LPARAMETER lcProgId,lcClassId, lcClassDescript

IF EMPTY(lcProgId)
   RETURN .F.
ENDIF

loAPI = CREATE("wwAPI")

*** Retrieve ClassId and Server Name
lcClassId =  ;
   loAPI.ReadRegistryString(HKEY_CLASSES_ROOT,;
                            lcProgId + "\CLSID",;
                            "")
IF ISNULL(lcClassId)
   lcClassId = ""
   lcClassDescription = ""
   RETURN .F.
ENDIF                                       
                            
lcClassDescript = ;
   loAPI.ReadRegistryString(HKEY_CLASSES_ROOT,;
                            lcProgId,"")

IF ISNULL(lcClassDescript)
   lcClassDescript = ""
ENDIF
                                    
RETURN .T.

************************************************************************
FUNCTION RegisterOleServer
**************************
***    Author: Rick Strahl, West Wind Technologies
***            http://www.west-wind.com/
***  Function: Registers an OLE server or OCX control
***      Pass: lcServerPath  -  Full path and filename of OCX/OLE Server
***            llUnregister  -  .T. to unregister
***            lcClassId     -  (optional) check for class Id - if 
***                                        exist don't reregister 
***    Return: .T. or .F.
*************************************************************************
LPARAMETER lcServerPath, llUnRegister, llSilent
LOCAL llRetVal, lcPath, lcOldPath

IF !FILE(lcServerPath)
    RETURN .f.
ENDIF

llRetVal=.F.
IF !llUnregister
   lcOldPath = SYS(5) + curdir()
   lcPath = JUSTPATH(lcServerPath)
   CD (lcPath)
   
   DECLARE INTEGER DllRegisterServer ;
      IN (lcServerPath)

   IF DllRegisterServer() = 0
      If !llSilent
         wait window nowait lcServerPath + " has been registered..."
      endif
      llRetVal=.T.
   ELSE
*!*	      DECLARE INTEGER GETLASTERROR IN WIN32API
*!*	      lnError = GetLastError()
*!*	      wait window STR(lnError)
      wait window lcserverPath +  " could not be registered..." TIMEOUT 5
   ENDIF
   
   cd (lcOldPath)   
ELSE
   DECLARE INTEGER DllUnregisterServer ;
      IN (lcServerPath)

   IF DllUnregisterServer() = 0
      if !llSilent
        wait window nowait lcServerPath + " has been unregistered..."
      ENDIF
      llRetVal=.T.
   ENDIF
ENDIF      

RETURN llRetVal

************************************************************************
FUNCTION DCOMCnfgServer
***********************
***  Function: Sets the security attributes of an Automation server
***            to Interactive User
***    Assume: Only works for Interactive user for now
***      Pass: lcProgId  -  Program ID for the server (wcdemo.wcdemoserver)
***            lcRunAs   -  User Account (Default: Interactive User)
***    Return: nothing
*************************************************************************
LPARAMETER lcProgId, lcRunAs
LOCAL lcProgId, loAPI, lcClassId, lcServerName

lcRunAs=IIF(type("lcRunAs")="C",lcRunAs,"Interactive User")
lcProgId=IIF(type("lcProgId")="C",lcProgId,"")
loAPI = CREATE("wwAPI")

*** Retrieve ClassId and Server Name
lcClassId = loAPI.ReadRegistryString(HKEY_CLASSES_ROOT,;
                                     lcProgId + "\CLSID",;
                                     "")
lcServerName = loAPI.ReadRegistryString(HKEY_CLASSES_ROOT,;
                                        lcProgId + "","")

IF ISNULL(lcClassId) or ISNULL(lcServerName)
  wait window nowait "Invalid Class Id..."
  RETURN
ENDIF
  
wait window "Configuring server security for "+CR+;
             lcProgId + CR + lcServerName  nowait

*** Now add AppId key to the ClsID entry
if !loAPI.WriteRegistryString(HKEY_LOCAL_MACHINE,;
        "SOFTWARE\Classes\CLSID\"+lcClassId,"AppId",lcClassID,.t.)
   wait window "Unable to write AppID value..."  nowait
   RETURN
ENDIF                                     


*** Create a AppID Entry if it doesn't exist
if !loAPI.WriteRegistryString(HKEY_CLASSES_ROOT,;
        "AppID\"+lcClassId,CHR(0),CHR(0),.t.)
   wait window "Unable to write AppID key..."  nowait
   RETURN
ENDIF                                     

*** Write the Server Name into the Default key
loAPI.WriteRegistryString(HKEY_CLASSES_ROOT,;
                          "AppID\"+lcClassId,"",;
                          lcServerName,;
                          .t.)

*** Write Interactive User (or user Accounts)                          
loAPI.WriteRegistryString(HKEY_CLASSES_ROOT,;
                          "AppID\"+lcClassId,"RunAs",;
                          lcRunAS,;
                          .t.)
wait window "DCOM security context set to: " + lcRunAs nowait                          
RETURN 

************************************************************************
FUNCTION StripHTML
*******************
***  Function: Removes HTML tags from the passed text and converts
***            it to plain text. Note formatting is totally removed!
***    Assume: only <br> and <p> are translated
***            any < or > in the HTML besides tags will break this
***            function.
***      Pass: lcText  -   HTML Text to strip
***            lcLTag  -   Left Tag value ("<")
***            lcRTag  -   Right Tag Value (">")
***    Return: Stripped HTML text
*************************************************************************
LPARAMETER lcHTMLText, lcLTag, lcRTag

lcLTag=IIF(EMPTY(lcLTag),"<",lcLTag)
lcRTag=IIF(EMPTY(lcRTag),">",lcRTag)

IF ATC(lcLTag,lcHTMLText) = 0
   RETURN lcHTMLText
ENDIF

*** Start by breaking line breaks
lcHTMLText = STRTRAN(lcHTMLText,lcLTag + "BR" + lcRTag,CRLF)
lcHTMLText = STRTRAN(lcHTMLText,lcLTag + "P" + lcRTag,CRLF+CRLF)
lcHTMLText = STRTRAN(lcHTMLText,lcLTag + "br" + lcRTag,CRLF)
lcHTMLText = STRTRAN(lcHTMLText,lcLTag + "p" + lcRTag,CRLF+CRLF)
lcHTMLText = STRTRAN(lcHTMLText,"&nbsp;"," ")

lcExtract = "x"   
DO WHILE !EMPTY(lcExtract)
   lcExtract = Extract(lcHTMLText,lcLTag,lcRTag)

   IF EMPTY(lcExtract)
      EXIT
   ENDIF
   lcHTMLText = STRTRAN(lcHTMLText,lcLTag+lcExtract+lcRTag,"")
ENDDO

lcHTMLText = STRTRAN(lcHTMLText,"&lt;","<")
lcHTMLText = STRTRAN(lcHTMLText,"&gt;",">")

RETURN lcHTMLText

************************************************************************
FUNCTION HTMLColor
*********************************
***  Function: Converts a FoxPro Color to an HTML Hex color value
***      Pass: lnRGBColor   -  FoxPro RGB color number - RGB(255,255,255)
***            llNoOutput
***    Return: Hex HTML Color String "#FFFFFF"
************************************************************************
LPARAMETER lnRGBColor

lcColor=RIGHT(TRANSFORM(lnRGBColor,"@0"),6)

*** Fox color is BBGGRR, HTML is RRGGBB

RETURN "#" + SUBSTR(lcColor,5,2) + SUBSTR(lcColor,3,2) + LEFT(lcColor,2)
* HTMLColor

************************************************************************
FUNCTION PropertyDump
**********************
***  Function: Dumps all of an objects properties to a string separated
***            by Carriage Returns. Note Long strings will be truncated
***            at 80 characters
***      Pass: loObject  -  Object to work with
***    Return: string of key value pairs
*************************************************************************
LPARAMETER loObject
LOCAL x, lnCount, lcOutput

lnCount = AMEMBERS(laFields, loObject)
lcOutput = ""
FOR x=1 to lnCount
   lcType = TYPE("loObject."+laFields[x])
   IF ATC(lcType,"UO") = 0
      lvValue = EVAL("loObject."+laFields[x])
      IF lcType="C" AND LEN(lvValue) > 80
         lvValue = LEFT(lvValue,80)
      ENDIF
      lcOutput = lcOutput  + CHR(13) +  CHR(10) + lower(laFields[x]) + " = " + ALLTRIM(TRANSFORM(lvValue,""))
   ELSE
      lcOutput = lcOutput + CHR(13) + CHR(10) +Lower(laFields[x]) + " = " + IIF(TYPE("loObject."+laFields[x])="O","Object","NULL")
   ENDIF
ENDFOR

RETURN lcOutput
* EOF PropertyDump



************************************************************************
PROCEDURE StrTranC
******************
***  Function: Like Strtran but case insensitive
***      Pass: lcString   -  Entire string
***			   lcDelim1   -  String to replace
***            lcDelim2	  -  String to replace with
***    Return: translated string
*************************************************************************
LPARAMETER lcString, lcSource, lcReplace
LOCAL lnAt
lnAt = 1
lnReplaceSize = LEN(lcSource)
DO while .T.
   lnAt = ATC(lcSource,lcString)
   IF lnAT = 0
      RETURN lcString
   ENDIF
   
   lcString = STUFF(lcString,lnAt,lnReplaceSize,lcReplace)
ENDDO

RETURN lcString
ENDPROC


************************************************************************
FUNCTION GetAppStartPath
*********************************
***  Function: Returns the FoxPro start path
***            of the *APPLICATION*
***            under all startmodes supported by VFP.
***            Returns the path of the starting EXE, 
***            DLL, APP, PRG/FXP
***    Return: Path as a string with trailing "\"
************************************************************************

DO CASE 
   *** VFP 6 provides ServerName property for COM servers EXE/DLL/MTDLL
   CASE INLIST(Application.StartMode,2,3,5) 
         lcPath = JustPath(Application.ServerName)

   *** Active Document
   CASE ATC(".APP",SYS(16,0)) > 0
       lcPath = JustPath(SYS(16,0))

  *** Standalone EXE or VFP Development
  OTHERWISE
       lcPath = JustPath(SYS(16,0))
       IF ATC("PROCEDURE",lcPath) > 0
         lcPath = SUBSTR(lcPath,RAT(":",lcPath)-1)
       ENDIF
ENDCASE

RETURN AddBs(lcPath)
* EOF GetAppStartPath      

************************************************************************
FUNCTION AParseString
**********************
***  Modified: 07/03/97
***  Function: Parses a delimited string into an array
***      Pass: laResult    -   Array containing the result strings (@)
***            lcString    -   The full string
***            lcDelimiter -   The delimiter string
***    Return: Count of strings or 0 if null string is passed
*************************************************************************
LPARAMETER laResult, lcString, lcDelimiter
LOCAL lnLastPos, lnItemCount, i

lnItemCount = OCCURS(lcDelimiter,lcString) + 1
DIMENSION laResult[lnItemCount]
lnLastPos=1

FOR i=1 to lnItemCount
   IF i < lnItemCount
     laResult[i] = SUBSTR(lcString,lnLastPos, ;
                          ATC(lcDelimiter,lcString,i) - lnLastPos )
   ELSE
     laResult[i] = SUBSTR(lcString,lnLastPos)
   ENDIF
   lnLastPos = ATC(lcDelimiter,lcString,i) + LEN(lcDelimiter)
ENDFOR

RETURN lnItemCount
* EOF AParseString

************************************************************************
FUNCTION URLDecode
******************
***  Function: URLDecodes a text string to normal text.
***    Assume: Uses wwIPStuff.dll
***      Pass: lcText    -   Text string to decode
***    Return: Decoded string or ""
************************************************************************
LPARAMETERS lcText
LOCAL lnSize, lnLoc, lcHex, lcRetval

*** Use wwIPStuff for large buffers
IF LEN(lcText) > 256
   DECLARE INTEGER URLDecode ;
      IN WWIPSTUFF AS API_URLDecode ;
      STRING @cText

   lnSize=API_URLDecode(@lcText)

   IF lnSize > 0
      lcText = SUBSTR(lcText,1,lnSize)
   ELSE
      lcText = ""
   ENDIF

   RETURN lcText
ENDIF

*** First convert + to spaces
lcText=STRTRAN(lcText,"+"," ")

*** Handle Hex Encoded Control chars

lcRetval = ""
DO WHILE .T.
   *** Format: %0A  ( CHR(10) )
   lnLoc = AT('%',lcText)

   *** No Hex chars
   IF lnLoc > LEN(lcText) - 2 OR lnLoc < 1
      lcRetval = lcRetval + lcText
      EXIT
   ENDIF

   *** Now read the next 2 characters
   *** Check for digits - at this point we must have hex pair!
   lcHex=SUBSTR(lcText,lnLoc+1,2)

   *** Now concat the string plus the evaled hex code
   lcRetval = lcRetval + LEFT(lcText,lnLoc-1) + ;
      CHR( EVAL("0x"+lcHex) )

   *** Trim out the input string
   IF LEN(lcText) > lnLoc + 2
      lcText = SUBSTR(lcText,lnLoc+3)
   ELSE
      EXIT
   ENDIF
ENDDO

RETURN lcRetval
ENDFUNC
* EOF URLDecode

************************************************************************
FUNCTION GetURLEncodedKey
*********************************
***  Function: Retrieves a 'parameter' from the query string that
***            is encoded with standard CGI/ISAPI URL encoding.
***            Typical URL encoding looks like this:
***
***    "User=Rick+Strahl&ID=0011&Address=400+Morton%0A%0DHood+River"
***
***      Pass: lcVal   -   Form Variable to retrieve
***    Return: Value or ""
************************************************************************
LPARAMETERS tcURLString, lcKey
LOCAL lnLoc,c2, cStr, lcURLString, lcRetval

lcURLString=IIF(EMPTY(tcURLString),"","&"+tcURLString)
lcKey=IIF(EMPTY(lcKey),"  ",lcKey)
lcKey=STRTRAN(lcKey," ","+")

lcRetval=Extract(@lcUrlString,"&"+lcKey+"=","&",,.T.)

RETURN URLDecode(lcRetval)
ENDFUNC


********************************************************
FUNCTION URLEncode
*******************
***  Function: Encodes a string in URL encoded format
***            for use on URL strings or when passing a
***            POST buffer to wwIPStuff::HTTPGetEx
***      Pass: tcValue  -   String to encode
***    Return: URLEncoded string or ""
********************************************************
LPARAMETER tcValue
LOCAL lcResult, lcChar, lnSize, x

*** Large Buffers use the wwIPStuff function 
*** for quicker response
if LEN(tcValue) > 50
   lnSize=LEN(tcValue)
   tcValue=PADR(tcValue,lnSize * 3)

   DECLARE INTEGER VFPURLEncode ;
      IN WWIPSTUFF ;
      STRING @cText,;
      INTEGER cInputTextSize
   
   lnSize=VFPUrlEncode(@tcValue,lnSize)
   
   IF lnSize > 0
      RETURN SUBSTR(TRIM(tcValue),1,lnSize)
   ENDIF
   RETURN ""
ENDIF   
   
*** Do it in VFP Code
lcResult=""

FOR x=1 to len(tcValue)
   lcChar = SUBSTR(tcValue,x,1)
   IF ATC(lcChar,"ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789") > 0
      lcResult=lcResult + lcChar
      LOOP
   ENDIF
   IF lcChar=" "
      lcResult = lcResult + "+"
      LOOP
   ENDIF
   *** Convert others to Hex equivalents
   lcResult = lcResult + "%" + RIGHT(transform(ASC(lcChar),"@0"),2)
ENDFOR && x=1 to len(tcValue)

RETURN lcResult
* EOF URLEncode


************************************************************************
FUNCTION WCSCompile
*******************
***  Modified: 12/31/97
***  Function: Compiles WCS script files
***    Assume: Requires Runtime version
***            Called by wwMaint using VisualFoxpro.Application
***            Automation object if Runtime is installed.
***      Pass: lcFileSpec    -   Filespec of files to compile
***            llSilent      -   No error display
***    Return: "" on success or Error String
*************************************************************************
LPARAMETER lcFileSpec, llSilent
lcFileSpec=IIF(type("lcFileSpec")="C",lcFileSpec,CURDIR() + "*.wcs")

lcPath = justpath(lcFileSpec)
lcFile = justfname(lcFileSpec)

IF EMPTY(lcPath)
  lcPath = CURDIR()
ENDIF
IF EMPTY(lcFile)
  lcFile = "*.WCS"
ENDIF
lcPath = ADDBS(lcPath)

lcFileSpec = lcPath + lcFile


DIMENSION laFiles[1]
lnFiles = ADIR(laFiles,lcFileSpec)
IF lnFiles < 1
   RETURN "No files to compile..."
ENDIF
oScript = CREATE("wwVFPScript",laFiles[1])
IF TYPE("oScript") <> "O"
  Return "Error: Couldn't create wwVFPScriptObject"
ENDIF
oScript.lDeleteGeneratedCode = .F.   && Erase WCT files

FOR x = 1 to lnFiles
         lcFileName = lcPath+laFiles[x,1]
         wait window nowait "Compiling "+lcFileName
         
         *** WCS - Script Text   WCX - Compiled   WCT - Intermediate
         oScript.cFileName = lcFileName
         oScript.ConvertPage()
         oScript.CompilePage() 
ENDFOR

wait window nowait LTRIM(STR(lnFiles))+ " Web Connection Script file(s) compiled."

lcErrors = ""
IF !EMPTY(oScript.cCompileErrors)
   File2Var(lcPath + "WCS_Script.err",oScript.cCompileErrors)
   IF !llSilent
      MODI COMM (lcPath + "WCS_Script.err")
   ENDIF
   lcErrors = File2Var(lcPath + "WCS_Script.err")
ENDIF

RETURN lcErrors
* EOF WCSCompile

