<#
 Agente de sincronización FacturaWeb: manda a la web los comprobantes nuevos del VFP. Ver LEEME.md.
 Windows PowerShell 5.1 (viene con Windows 10). Solo LEE los DBF del VFP.
   .\FacturaWebAgente.ps1 -Probar   comprueba la conexión y la clave, sin enviar nada
   .\FacturaWebAgente.ps1 -Forzar   envía aunque nada haya cambiado
#>
param([switch]$Probar, [switch]$Forzar)
$ErrorActionPreference = 'Stop'
$aqui = Split-Path -Parent $MyInvocation.MyCommand.Path
$log = Join-Path $aqui 'agente.log'
function Escribir([string]$m) {
    $linea = '{0:yyyy-MM-dd HH:mm:ss}  {1}' -f (Get-Date), $m
    Write-Host $linea
    Add-Content -Path $log -Value $linea -Encoding UTF8
}
# TLS 1.2 (Windows 10 con PowerShell 5.1 puede arrancar con una versión vieja)
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

try {
    $cfgArchivo = Join-Path $aqui 'config.json'
    if (-not (Test-Path $cfgArchivo)) { throw "Falta config.json (copiá config.ejemplo.json y completalo)." }
    $cfg = Get-Content $cfgArchivo -Raw -Encoding UTF8 | ConvertFrom-Json
    $url = ([string]$cfg.url).TrimEnd('/')
    $cabeceras = @{ 'Authorization' = "Bearer $($cfg.clave)"; 'X-FW-Clave' = [string]$cfg.clave }
    if ($url -notmatch '^https://') { throw "La dirección tiene que empezar con https:// (por seguridad no se envía por http)." }

    # 1) Conexión y clave
    try {
        $p = Invoke-RestMethod -Uri "$url/api/v1/ping" -Headers $cabeceras -Method Get -TimeoutSec 60
    } catch {
        $codigo = if ($_.Exception.Response) { [int]$_.Exception.Response.StatusCode } else { 0 }
        if ($codigo -eq 401) { throw "Clave inválida o revocada. Creá otra en la web (Sistema → Agente)." }
        throw "No se pudo conectar con $url ($($_.Exception.Message))."
    }
    if ($Probar) { Escribir "Conexión correcta con $($p.sistema) versión $($p.version) (clave '$($p.clave)')."; exit 0 }

    # 2) Copia de los DBF a una carpeta temporal (el VFP puede tenerlos abiertos: se abren solo para leer)
    $carpeta = [string]$cfg.carpeta_vfp
    if (-not (Test-Path (Join-Path $carpeta 'subdiario.dbf'))) { throw "No encuentro subdiario.dbf en $carpeta (revisá carpeta_vfp en config.json)." }
    $tmp = Join-Path ([IO.Path]::GetTempPath()) ('fwagente_' + [Guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $tmp | Out-Null
    try {
        $archivos = @('subdiario.dbf', 'subdiario.fpt', 'iva.dbf', 'estadistica.dbf', 'estadistica.fpt', 'caja.dbf', 'caja.fpt', 'ctact.dbf', 'ctact.fpt', 'datos.dbc', 'datos.dct')
        foreach ($a in $archivos) {
            $o = Join-Path $carpeta $a
            if (Test-Path $o) {
                $in = [IO.File]::Open($o, 'Open', 'Read', 'ReadWrite')   # compartido: no molesta al VFP
                try { $out = [IO.File]::Create((Join-Path $tmp $a)); try { $in.CopyTo($out) } finally { $out.Dispose() } } finally { $in.Dispose() }
            }
        }
        # 3) ¿Cambió algo desde el último envío?
        $huella = (Get-ChildItem $tmp -File | Sort-Object Name | ForEach-Object { '{0}:{1}' -f $_.Name, (Get-FileHash $_.FullName -Algorithm SHA256).Hash }) -join ';'
        $estado = Join-Path $aqui 'ultimo_envio.txt'
        $huellaHash = [BitConverter]::ToString([Security.Cryptography.SHA256]::Create().ComputeHash([Text.Encoding]::UTF8.GetBytes($huella))).Replace('-', '')
        if (-not $Forzar -and (Test-Path $estado) -and ((Get-Content $estado -Raw).Trim() -eq $huellaHash)) { Escribir 'Sin cambios desde el último envío: no se manda nada.'; exit 0 }

        # 4) Zip y envío (cuerpo crudo)
        $zip = Join-Path ([IO.Path]::GetTempPath()) ('fwagente_' + [Guid]::NewGuid().ToString('N') + '.zip')
        Compress-Archive -Path (Join-Path $tmp '*') -DestinationPath $zip -Force
        try {
            $mb = [math]::Round((Get-Item $zip).Length / 1MB, 1)
            Escribir "Enviando $mb MB..."
            $r = Invoke-RestMethod -Uri "$url/api/v1/vfp/comprobantes" -Headers $cabeceras -Method Post -InFile $zip -ContentType 'application/zip' -TimeoutSec 900
        } catch {
            $detalle = $_.Exception.Message
            if ($_.Exception.Response) {
                $rd = New-Object IO.StreamReader($_.Exception.Response.GetResponseStream())
                $detalle = $rd.ReadToEnd()
            }
            throw "La web no aceptó el envío: $detalle"
        } finally { Remove-Item $zip -Force -ErrorAction SilentlyContinue }
        if (-not $r.ok) { throw "La web respondió con error: $($r.error)" }
        Set-Content -Path $estado -Value $huellaHash -Encoding ASCII
        Escribir "Listo: $($r.nuevos) comprobante(s) nuevo(s) de $($r.leidos) leídos."
    } finally { Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue }
} catch {
    Escribir "ERROR: $($_.Exception.Message)"
    exit 1
}
