# Agente de sincronización FacturaWeb (Windows 10)

Manda a la web los comprobantes, clientes y artículos del sistema VFP. **No instala nada**: es un script de PowerShell (viene con Windows 10).
Solo lee los DBF del VFP (no los modifica) y se conecta él a la web por HTTPS; no hace falta abrir puertos.

## Instalación (una vez)
1. En la web: **Sistema → Agente de sincronización → Crear clave** (nombre: «PC del VFP»). **Copiá la clave** (se muestra una sola vez).
2. En la PC del VFP, copiá esta carpeta `agente` a `C:\FacturaWebAgente\`.
3. Copiá `config.ejemplo.json` como `config.json` y completá:
   - `url`: la dirección de la web, sin barra final (ej. `https://facturador.empresa.com.ar`).
   - `clave`: la que copiaste.
   - `carpeta_vfp`: la carpeta de los DBF del VFP (donde está `subdiario.dbf`).
4. Probá: abrí PowerShell en esa carpeta y ejecutá
   ```
   powershell -ExecutionPolicy Bypass -File .\FacturaWebAgente.ps1 -Probar
   ```
   Tiene que decir «Conexión correcta». Después, sin `-Probar`, hace el primer envío (los comprobantes que la web ya tiene se saltean).
5. Programalo: **Programador de tareas → Crear tarea básica** → diaria (o cada hora) → acción «Iniciar un programa»:
   - Programa: `powershell.exe`
   - Argumentos: `-NoProfile -ExecutionPolicy Bypass -File C:\FacturaWebAgente\FacturaWebAgente.ps1`

## Qué hace en cada ejecución
Copia `subdiario`, `iva`, `estadistica`, `caja` y `ctact` (y `datos.dbc`) —y, en un segundo envío, `clientes` y `articulos`— a una carpeta temporal (así no interfiere con el VFP que está abierto), las comprime y las manda. Cada envío se salta si
no cambió nada desde el último. Los clientes y artículos **nuevos se suman y los que cambiaron en el VFP se actualizan** en la web (por código); lo que la web agrega por su cuenta —cliente desactivado, EAN, SKU, bultos, control de stock— no se pisa. Nada se borra. La web suma solo los comprobantes que no tenía: repetir un envío no duplica nada. Todo queda en `agente.log`.

## Si algo falla
- «Clave inválida»: la clave está mal copiada o fue revocada (Sistema → Agente).
- «No se pudo conectar»: revisá la dirección y que la PC tenga internet.
- «El envío es demasiado grande»: el hosting limita el tamaño de subida; avisá a O&S para subir el límite.
- Los envíos se ven en la web, en **Sistema → Agente de sincronización → Últimos envíos**.
