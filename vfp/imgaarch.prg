LPARAMETERS lcFicheroDestino

DECLARE Sleep IN Win32API INTEGER nMilliseconds
   DECLARE INTEGER keybd_event IN Win32API ;
      INTEGER, INTEGER, INTEGER, INTEGER
   VK_SNAPSHOT = 44    && from the winuser.h
   VK_LMENU = 164
   KEYEVENTF_KEYUP = 2
   KEYEVENTF_EXTENDEDKEY = 1



* espera 1 segundo
nSleepSeconds = 2
nMilliseconds = nSleepSeconds * 1000
=Sleep(nMilliSeconds)

DOEVENTS
keybd_event(VK_SNAPSHOT, 1, 0, 0 )
keybd_event(VK_SNAPSHOT, 1, KEYEVENTF_KEYUP, 0 )
DOEVENTS

DOEVENTS
keybd_event( VK_LMENU,    0, KEYEVENTF_EXTENDEDKEY, 0 )  && key down
keybd_event( VK_SNAPSHOT, 0, KEYEVENTF_EXTENDEDKEY, 0 )
keybd_event( VK_SNAPSHOT, 0, KEYEVENTF_EXTENDEDKEY + KEYEVENTF_KEYUP, 0 )
keybd_event( VK_LMENU,    0, KEYEVENTF_EXTENDEDKEY + KEYEVENTF_KEYUP, 0 )  
DOEVENTS

DECLARE INTEGER GetActiveWindow IN user32
DECLARE INTEGER GetClipboardData IN user32 INTEGER uFormat
DECLARE INTEGER OpenClipboard IN user32 INTEGER hwnd
DECLARE INTEGER CloseClipboard IN user32
DECLARE INTEGER DeleteObject IN gdi32 INTEGER hObject
DECLARE INTEGER GetWindowDC IN user32 INTEGER hwnd
DECLARE INTEGER CreateCompatibleDC IN gdi32 INTEGER hdc
DECLARE INTEGER DeleteDC IN gdi32 INTEGER hdc
DECLARE INTEGER ReleaseDC IN user32 ;
                INTEGER hwnd,;
                INTEGER hdc
DECLARE INTEGER GlobalFree IN kernel32 INTEGER hMem
DECLARE INTEGER GetObjectType IN gdi32 INTEGER h
DECLARE INTEGER CloseHandle IN kernel32 INTEGER hObject
DECLARE INTEGER GlobalAlloc IN kernel32 ;
                INTEGER wFlags,;
                INTEGER dwBytes
                
DECLARE INTEGER GetObject IN gdi32 AS GetObjectA;
                INTEGER handleGrafico,;
                INTEGER cbBuffer,;
                STRING @lpvObject
                

DECLARE RtlZeroMemory IN kernel32 As ZeroMemory;
                INTEGER dest,;
                INTEGER numBytes
DECLARE INTEGER GetDIBits IN gdi32;
                INTEGER hdc,;
                INTEGER hbmp,;
                INTEGER uStartScan,;
                INTEGER cScanLines,;
                INTEGER lpvBits,;
                STRING @lpbi,;
                INTEGER uUsage
                
DECLARE INTEGER CreateFile IN kernel32;
                STRING lpFileName,;
                INTEGER dwDesiredAccess,;
                INTEGER dwShareMode,;
                INTEGER lpSecurityAttr,;
                INTEGER dwCreationDisp,;
                INTEGER dwFlagsAndAttrs,;
                INTEGER hTemplateFile
*
#DEFINE CF_BITMAP 2
#DEFINE OBJ_BITMAP 7
*
LOCAL hClipBmp, lcFicheroDestino
*
* espera 1 segundo
nSleepSeconds = 4
nMilliseconds = nSleepSeconds * 1000
=Sleep(nMilliSeconds)
*
DOEVENTS FORCE

= OpenClipboard (0)
*
hClipBmp = GetClipboardData ( CF_BITMAP )
*
= CloseClipboard( )
*
IF hClipBmp = 0 Or GetObjectType(hClipBmp) <> OBJ_BITMAP
    RETURN .F.
ENDIF
*
= imagen_a_fichero( hClipBmp, lcFicheroDestino )
*
= DeleteObject( hClipBmp )
*
RETURN
*
**************
* FUNCIONES. *
**************
*
*****************************************
* PASO DE IMAGEN PORTAPAPELES A FICHERO *
*****************************************
*
PROCEDURE imagen_a_fichero( hBitmap, lcFicheroDestino )
    *
    #DEFINE DIB_RGB_COLORS 0
   #DEFINE BFHDR_SIZE 14 && BITMAPFILEHEADER
   #DEFINE BHDR_SIZE 40 && BITMAPINFOHEADER
   #DEFINE GENERIC_WRITE 1073741824 && 0x40000000
   #DEFINE FILE_SHARE_WRITE 2
   #DEFINE CREATE_ALWAYS 2
   #DEFINE FILE_ATTRIBUTE_NORMAL 128
   #DEFINE INVALID_HANDLE_VALUE -1
    *
    PRIVATE pnWidth, pnHeight, pnBitsSize, pnRgbQuadSize, pnBytesPerScan
   LOCAL lpBitsArray, lcBInfo
   LOCAL lnhwnd, hdc, hMemDC
   LOCAL hFile, lnFileSize, lnOffBits, lcBFileHdr
    *
    STORE 0 TO pnWidth, pnHeight, pnBytesPerScan, pnBitsSize, pnRgbQuadSize
    *
    = GetBitmapDimensions( hBitmap, @pnWidth, @pnHeight )
    *
    lcBInfo = InitBitmapInfo( )
   lpBitsArray = InitBitsArray( )
   lnhwnd = GetActiveWindow( )
   hdc = GetWindowDC( lnhwnd )
   hMemDC = CreateCompatibleDC(hdc )
    *
    = ReleaseDC( lnhwnd, hdc )
    *
    = GetDIBits( hMemDC, hBitmap, 0, pnHeight, lpBitsArray, @lcBInfo, DIB_RGB_COLORS )
    *
    lnFileSize = BFHDR_SIZE + BHDR_SIZE + pnRgbQuadSize + pnBitsSize
    *
    lnOffBits = BFHDR_SIZE + BHDR_SIZE + pnRgbQuadSize
    *
    lcBFileHdr = "BM" + num2DWord( lnFileSize ) + num2DWord( 0 ) + num2DWord( lnOffBits )
    *
    hFile = CreateFile ( lcFicheroDestino,;
                               GENERIC_WRITE,;
                               FILE_SHARE_WRITE, 0,;
                               CREATE_ALWAYS,;
                               FILE_ATTRIBUTE_NORMAL, 0 ) 
    *
    IF hFile <> INVALID_HANDLE_VALUE
        *
        
      *
        = String2File( hFile, @lcBFileHdr )
       = String2File( hFile, @lcBInfo )
       = Ptr2File( hFile, lpBitsArray, pnBitsSize )
       = CloseHandle( hFile )
      *
    ELSE
         *
        MESSAGEBOX( "No se puede crear el fichero: " + lcFicheroDestino )
      *
    ENDIF
    *
    = GlobalFree( lpBitsArray )
   = DeleteDC( hMemDC )
    *
RETURN
*
************************
* GETBITMAPDIMENSIONS. *
************************
*
PROCEDURE GetBitmapDimensions( hBitmap, lnWidth, lnHeight )
    *
    #DEFINE BITMAP_STRU_SIZE 24
    *
    LOCAL lcBuffer
    *
    lcBuffer = REPLICATE( CHR( 0 ), BITMAP_STRU_SIZE )
    *
    IF GetObjectA( hBitmap, BITMAP_STRU_SIZE, @lcBuffer ) <> 0
        *
        lnWidth = buffer2DWord ( SUBSTR( lcBuffer, 5,4 ) )
       lnHeight = buffer2DWord ( SUBSTR( lcBuffer, 9,4 ) )
        *
    ENDIF
    *
RETURN
*
*****************
* BUFFER2DWORD. *
*****************
*
FUNCTION buffer2DWord (lcBuffer)
RETURN ASC(SUBSTR(lcBuffer, 1,1)) + ;
           ASC(SUBSTR(lcBuffer, 2,1)) * 256 +;
           ASC(SUBSTR(lcBuffer, 3,1)) * 65536 +;
           ASC(SUBSTR(lcBuffer, 4,1)) * 16777216
*
*************
* PTR2FILE. *
*************
*
PROCEDURE Ptr2File( hFile, lnPointer, lnBt2Write )
    *
    DECLARE INTEGER WriteFile IN kernel32;
                   INTEGER hFile ,;
                   INTEGER lpBuffer ,;
                   INTEGER nBt2Write ,;
                   INTEGER @lpBtWritten ,;
                   INTEGER lpOverlapped
    *
    = WriteFile( hFile, lnPointer, lnBt2Write, 0, 0 )
    *
RETURN
*
*******************
* INITBITMAPINFO. *
*******************
*
PROCEDURE InitBitmapInfo( lcBIHdr )
    **
    TRY 
	   #DEFINE BI_RGB 0
	   #DEFINE RGBQUAD_SIZE 4
	   #DEFINE BHDR_SIZE 40
	CATCH 
	ENDTRY 
    *
    LOCAL lnBitsPerPixel, lcBIHdr, lcRgbQuad
    *
    lnBitsPerPixel = 24
   pnBytesPerScan = Int((pnWidth * lnBitsPerPixel)/8)
    *
    IF MOD( pnBytesPerScan, 4 ) <> 0
        *
        pnBytesPerScan = pnBytesPerScan + 4 - MOD( pnBytesPerScan, 4 )
        *
    ENDIF
    *
    lcBIHdr = num2DWord( BHDR_SIZE ) + num2DWord( pnWidth ) +;
   num2DWord( pnHeight ) + num2word( 1 ) + num2word( lnBitsPerPixel ) +;
   num2DWord( BI_RGB ) + REPLICATE( CHR( 0 ), 20 )
    *
    IF lnBitsPerPixel <= 8
        *
        pnRgbQuadSize = ( 2^lnBitsPerPixel ) * RGBQUAD_SIZE
       lcRgbQuad = REPLICATE( CHR( 0 ), pnRgbQuadSize )
        *
    ELSE
        *
       lcRgbQuad = SPACE( 0 )
        *
    ENDIF
*
RETURN lcBIHdr + lcRgbQuad
*
******************
* INITBITSARRAY. *
******************
    *
    PROCEDURE InitBitsArray( )
    *
    #DEFINE GMEM_FIXED 0
    *
    LOCAL lnPTR
    *
    pnBitsSize = pnHeight * pnBytesPerScan
   lnPTR        = GlobalAlloc( GMEM_FIXED, pnBitsSize )
    *
    = ZeroMemory( lnPTR, pnBitsSize )
    *
RETURN lnPTR
*
****************
* STRING2FILE. *
****************
*
PROCEDURE String2File( hFile, lcBuffer )
    *
    DECLARE INTEGER WriteFile IN kernel32;
   INTEGER hFile ,;
   STRING @lpBuffer ,;
   INTEGER nBt2Write ,;
   INTEGER @lpBtWritten ,;
   INTEGER lpOverlapped
    *
    = WriteFile( hFile, @lcBuffer, LEN( lcBuffer ), 0, 0 )
    *
RETURN
*
*************
* NUM2WORD. *
*************
*
FUNCTION num2Word( lnValor )
RETURN CHR( MOD ( m.lnValor, 256 ) ) + CHR( INT( m.lnValor/256 ) )
*
**************
* NUM2DWORD. *
**************
*
FUNCTION num2DWord( lnValor )
    *
    #DEFINE lnM0 256
   #DEFINE lnM1 65536
   #DEFINE lnM2 16777216
    *
    LOCAL lnV0, lnV1, lnV2, lnV3
    *
    lnV3 = INT( lnValor/lnM2 )
   lnV2 = INT( ( lnValor - lnV3 * lnM2 ) / lnM1 )
   lnV1 = INT( ( lnValor - lnV3 * lnM2 - lnV2 * lnM1 ) / lnM0 )
   lnV0 = MOD( lnValor, lnM0 )
    *
RETURN CHR( lnV0 ) + CHR( lnV1 ) + CHR( lnV2 ) + CHR( lnV3 )
*