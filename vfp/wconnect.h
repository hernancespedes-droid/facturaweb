************************************************************************
* WCONNECT Header File
**********************
***    Author: Rick Strahl
***            (c) West Wind Technologies, 1995-2000
***   Contact: http://www.west-wind.com/
***  Function: Global DEFINEs used by Web Connection.
***
*** IMPORTANT: Any changes made here require a recompile of
***            all files that use this header file! I suggest
***            you build a project for this purpose and recompile
************************************************************************

#DEFINE WWVERSION "Version 3.20"
#DEFINE WWVERSIONDATE "May 26, 2000"

*** DEBUGMODE effects how errors are handled.
*** If .T. errors are not handled and the server stops on errors.
*** If .F. the Web Connection error handlers kick in and
*** provide error pages and logging
#DEFINE DEBUGMODE .T.

*** Use this flag to handle different configurations
*** You can set up conditional DEFINES for applications
*** to easily switch configurations. (Optional - not used by framework)
*** 1 -  Development Server
*** 2 -  Live Server
#DEFINE LOCALSITE 1

*** When .T. server runs as a Top Level while running file based
#DEFINE SERVER_IN_DESKTOP	.F.

*** Carriage Return/Line Break
#DEFINE CR					CHR(13)+CHR(10)
#DEFINE CRLF				CHR(13)+CHR(10)

*** Customizable default HTTP Header
#DEFINE DEFAULT_CONTENTTYPE_HEADER ;
    "HTTP/1.0 200 OK" + CR + ;
	"Content-type: text/html" + CR

#DEFINE DEFAULT_HTTP_VERSION	"1.0"

#DEFINE POST_BOUNDARY    		CHR(13)+CHR(10)+ "#@$ FORM VARIABLES $@#" + CHR(13)+CHR(10)
#DEFINE MULTIPART_BOUNDARY		"-----------------------------7cf2a327f01ae"

*** SQL Connect String to database containing
*** wwSession and RequestLog files
#DEFINE WW_SQL_CONNECT_STRING		""

*** Visual FoxPro Version Number Macro
#DEFINE wwVFPVERSION  VAL(SUBSTR(Version(),ATC("FoxPro",VERSION())+7,2))

*** Maximum String size for the wwResponseString class
#DEFINE MAX_STRINGSIZE  			5000

*** Maximum number of cells that ShowCursor generates
*** before reverting to <pre> list
#DEFINE MAX_TABLE_CELLS 			5000

*** Special 'NULL' String to differentiate none from empty strings
#DEFINE WWC_NULLSTRING "*#*"

*** Class Names - These classes are defined here and used in the code
***               so if you subclass an essential class you can change
***               the class used here and automatically have the framework
***               inherit from your subclass
#DEFINE WWC_SERVER				wwServer
#DEFINE WWC_SERVERFORM 			wwServerForm

#DEFINE WWC_PROCESS         	wwProcess
#DEFINE WWC_SESSION 			wwSession

#DEFINE WWC_REQUEST				wwRequest
#DEFINE WWC_REQUESTASP			wwASPRequest

#DEFINE WWC_RESPONSE			wwResponse
#DEFINE WWC_RESPONSEFILE    	wwResponseFile
#DEFINE WWC_RESPONSESTRING		wwResponseString
#DEFINE WWC_RESPONSEASP			wwASPResponse
#DEFINE WWC_RESPONSEBEHAVIOR 	wwResponseFileBehavior
#DEFINE WWC_HTTPHEADER			wwHTTPHeader

#DEFINE WWC_wwEval 				wwEval
#DEFINE WWC_wwHTMLControl 		wwHTMLControl
#DEFINE WWC_WWVFPSCRIPT     	wwVFPScript
#DEFINE WWC_WWPDF				wwPDF


*** Class Include flags - Use these to make the install lighter   -  New 07/05/97
#DEFINE WWC_LOAD_DYNAMICHTML_FORMRENDERING  .T.
#DEFINE WWC_LOAD_WWSESSION 					.T.
#DEFINE WWC_LOAD_WWBANNER 					.T.
#DEFINE WWC_LOAD_WWDBFPOPUP 				.T.
#DEFINE WWC_LOAD_WWIPSTUFF 					.T.
#DEFINE WWC_LOAD_WWVFPSCRIPT 				.T.
#DEFINE WWC_LOAD_WWSQL						.T.
#DEFINE WWC_LOAD_WWPDF						.T.
#DEFINE WWC_LOAD_WWXML						.T.  && Don't change!
#DEFINE WWC_LOAD_WWMSMQ						.F.


*** VERSION CONSTANTS
#DEFINE SHAREWARE 		.F.
#DEFINE WWC_DEMO		.T.
#DEFINE SWTIMEOUT 		1800
#DEFINE HTMLCLASSONLY 	.F.

#DEFINE SHOWSQLERRORS 	.F.
#DEFINE FOXISAPI 		.F.
#DEFINE VISUALWEBBUILDER .F.

*** wwHTMLForm options

*** Images in forms are pathed relative to the Web request
*** and must be located in the directory specified here
#DEFINE WWFORM_IMAGEPATH "formimages/"

*** wwList ActiveX Control settings - Changed 10/20/97
#DEFINE WWLIST_CLASSID "36E500EB-8219-11D1-A398-00600889F23B"
#DEFINE WWLIST_CODEBASE "wwCTLS.cab"


*** General WinINET Constants
#DEFINE INTERNET_OPEN_TYPE_PRECONFIG    		0
#DEFINE INTERNET_OPEN_TYPE_DIRECT       		1
#DEFINE INTERNET_OPEN_TYPE_PROXY                3

#DEFINE INTERNET_OPTION_CONNECT_TIMEOUT         2
#DEFINE INTERNET_OPTION_CONNECT_RETRIES         3
#DEFINE INTERNET_OPTION_DATA_SEND_TIMEOUT       7
#DEFINE INTERNET_OPTION_DATA_RECEIVE_TIMEOUT    8
#DEFINE INTERNET_OPTION_LISTEN_TIMEOUT          11

#DEFINE INTERNET_SERVICE_FTP				    1
#DEFINE INTERNET_DEFAULT_FTP_PORT				21

#DEFINE ERROR_INTERNET_EXTENDED_ERROR           12003

*** HTTP WinInet Service Flags
#DEFINE INTERNET_SERVICE_HTTP         			3
#DEFINE INTERNET_DEFAULT_HTTP_PORT      		80
#DEFINE INTERNET_DEFAULT_HTTPS_PORT   		  	443
#DEFINE HTTP_QUERY_RAW_HEADERS_CRLF             22  

*** FTP WinInet Service Flags
#DEFINE INTERNET_FLAG_RELOAD            		2147483648
#DEFINE INTERNET_FLAG_SECURE            		8388608
#DEFINE FTP_TRANSFER_TYPE_ASCII     			1
#DEFINE FTP_TRANSFER_TYPE_BINARY    			2


*** Win32 API Constants
#DEFINE ERROR_SUCCESS               			0

*** Access Flags
#DEFINE GENERIC_READ                     		0x80000000
#DEFINE GENERIC_WRITE                    		0x40000000
#DEFINE GENERIC_EXECUTE                  		0x20000000
#DEFINE GENERIC_ALL                      		0x10000000

*** File Attribute Flags
#DEFINE FILE_ATTRIBUTE_NORMAL               	0x00000080
#DEFINE FILE_ATTRIBUTE_READONLY             	0x00000001
#DEFINE FILE_ATTRIBUTE_HIDDEN               	0x00000002
#DEFINE FILE_ATTRIBUTE_SYSTEM               	0x00000004

*** Values for FormatMessage API
#DEFINE FORMAT_MESSAGE_FROM_SYSTEM     			4096
#DEFINE FORMAT_MESSAGE_FROM_HMODULE    			2048

*** Registry roots
#DEFINE HKEY_CLASSES_ROOT           -2147483648  && (( HKEY ) 0x80000000 )
#DEFINE HKEY_CURRENT_USER           -2147483647  && (( HKEY ) 0x80000001 )
#DEFINE HKEY_LOCAL_MACHINE          -2147483646  && (( HKEY ) 0x80000002 )
#DEFINE HKEY_USERS                  -2147483645  && (( HKEY ) 0x80000003 )

*** Registry Value types
#DEFINE REG_NONE					0    && Undefined Type (default)
#DEFINE REG_SZ						1	 && Regular Null Terminated String
#DEFINE REG_BINARY					3    && ??? (unimplemented)
#DEFINE REG_DWORD					4    && Long Integer value
#DEFINE MULTI_SZ					7	 && Multiple Null Term Strings (not implemented)

*** Generic File Access Rights for NT ACLs
#define     FILERIGHTS_READ             1179785
#define 	FILERIGHTS_READEXECUTE		1179817
#define 	FILERIGHTS_CHANGE			1245631
#define		FILERIGHTS_FULL				2032127


**** CUSTOMIZE AND OVERRIDE SETTINGS INDEPENDENTLY
**** OF THE WC INSTALLATION

*** Include custom settings here that undefine above settings
*** and reset them to my custom values
*!*#INCLUDE Westwind.h

*!*  Westwind.h would contain:
*!*			#UNDEFINE DEBUGMODE
*!*			#UNDEFINE SERVER_IN_DESKTOP 
*!*			#UNDEFINE DEFAULT_CONTENTTYPE_HEADER 
*!*			#UNDEFINE DEFAULT_HTTP_VERSION	

*!*			#DEFINE DEBUGMODE .T.
*!*			#DEFINE SERVER_IN_DESKTOP .T.
*!*			#DEFINE DEFAULT_CONTENTTYPE_HEADER ;
*!*			    "HTTP/1.1 200 OK" + CR + ;
*!*				"Content-type: text/html" + CR
*!*			#DEFINE DEFAULT_HTTP_VERSION	"1.1"
