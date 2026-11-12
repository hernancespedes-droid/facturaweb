<#
 Agente de sincronización FacturaWeb: manda a la web los comprobantes, clientes y artículos del VFP. Ver LEEME.md.
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

    $carpeta = [string]$cfg.carpeta_vfp
    if (-not (Test-Path (Join-Path $carpeta 'subdiario.dbf'))) { throw "No encuentro subdiario.dbf en $carpeta (revisá carpeta_vfp en config.json)." }

    # Copia los DBF a una carpeta temporal (el VFP puede tenerlos abiertos: se abren solo para leer), los comprime y los manda si cambiaron.
    function Enviar([string]$titulo, [string[]]$archivos, [string]$ruta, [string]$estadoNombre, [scriptblock]$resumen) {
        $tmp = Join-Path ([IO.Path]::GetTempPath()) ('fwagente_' + [Guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Path $tmp | Out-Null
        try {
            $copiados = 0
            foreach ($a in $archivos) {
                $o = Join-Path $carpeta $a
                if (Test-Path $o) {
                    $in = [IO.File]::Open($o, 'Open', 'Read', 'ReadWrite')   # compartido: no molesta al VFP
                    try { $out = [IO.File]::Create((Join-Path $tmp $a)); try { $in.CopyTo($out) } finally { $out.Dispose() } } finally { $in.Dispose() }
                    $copiados++
                }
            }
            if ($copiados -eq 0) { Escribir "${titulo}: no encuentro los archivos en $carpeta."; return }
            # ¿Cambió algo desde el último envío?
            $huella = (Get-ChildItem $tmp -File | Sort-Object Name | ForEach-Object { '{0}:{1}' -f $_.Name, (Get-FileHash $_.FullName -Algorithm SHA256).Hash }) -join ';'
            $estado = Join-Path $aqui $estadoNombre
            $huellaHash = [BitConverter]::ToString([Security.Cryptography.SHA256]::Create().ComputeHash([Text.Encoding]::UTF8.GetBytes($huella))).Replace('-', '')
            if (-not $Forzar -and (Test-Path $estado) -and ((Get-Content $estado -Raw).Trim() -eq $huellaHash)) { Escribir "${titulo}: sin cambios desde el último envío, no se manda nada."; return }
            # Zip y envío (cuerpo crudo)
            $zip = Join-Path ([IO.Path]::GetTempPath()) ('fwagente_' + [Guid]::NewGuid().ToString('N') + '.zip')
            Compress-Archive -Path (Join-Path $tmp '*') -DestinationPath $zip -Force
            try {
                $mb = [math]::Round((Get-Item $zip).Length / 1MB, 1)
                Escribir "${titulo}: enviando $mb MB..."
                $r = Invoke-RestMethod -Uri "$url$ruta" -Headers $cabeceras -Method Post -InFile $zip -ContentType 'application/zip' -TimeoutSec 900
            } catch {
                $detalle = $_.Exception.Message
                if ($_.Exception.Response) {
                    $rd = New-Object IO.StreamReader($_.Exception.Response.GetResponseStream())
                    $detalle = $rd.ReadToEnd()
                }
                throw "La web no aceptó el envío de ${titulo}: $detalle"
            } finally { Remove-Item $zip -Force -ErrorAction SilentlyContinue }
            if (-not $r.ok) { throw "La web respondió con error en ${titulo}: $($r.error)" }
            Set-Content -Path $estado -Value $huellaHash -Encoding ASCII
            Escribir ("${titulo}: " + (& $resumen $r))
        } finally { Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue }
    }

    # 2) Comprobantes (solo suma los que la web no tenía)
    Enviar 'Comprobantes' @('subdiario.dbf', 'subdiario.fpt', 'iva.dbf', 'estadistica.dbf', 'estadistica.fpt', 'caja.dbf', 'caja.fpt', 'ctact.dbf', 'ctact.fpt', 'datos.dbc', 'datos.dct') '/api/v1/vfp/comprobantes' 'ultimo_envio.txt' {
        param($r) "listo: $($r.nuevos) comprobante(s) nuevo(s) de $($r.leidos) leídos."
    }
    # 3) Clientes y artículos (suma los nuevos y actualiza los que cambiaron en el VFP; lo que cargó la web aparte no se pisa)
    Enviar 'Clientes y artículos' @('clientes.dbf', 'clientes.fpt', 'articulos.dbf', 'articulos.fpt', 'datos.dbc', 'datos.dct') '/api/v1/vfp/maestros' 'ultimo_envio_maestros.txt' {
        param($r) "listo: clientes $($r.detalle.clientes.grabadas) nuevo(s) y $($r.detalle.clientes.actualizadas) actualizado(s); artículos $($r.detalle.articulos.grabadas) nuevo(s) y $($r.detalle.articulos.actualizadas) actualizado(s)."
    }
} catch {
    Escribir "ERROR: $($_.Exception.Message)"
    exit 1
}
