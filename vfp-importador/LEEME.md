# Importar de la web (dentro del VFP)

`IMPORTAR_WEB.PRG` graba en el VFP lo que se emitió en FacturaWeb: **subdiario, IVA, estadística, caja y cuenta corriente** de cada comprobante y los **movimientos de stock** (`STOCK`, `MVSTK_CABECERA`, `MVSTK_ITEMS`).
Lo graba el propio VFP (no se escriben los DBF a mano) para que los índices `.cdx` queden bien.

> **Es la primera versión y no se pudo probar con un VFP real.** Probala primero **en una copia de los datos** y con la opción de simulación.

## Cómo funciona
1. El agente (`FacturaWebAgente.ps1`) baja de la web a `<intercambio>\entrada\lote_….txt` lo emitido en la web con validez fiscal que el VFP no tiene (hasta 50 comprobantes por lote).
2. Vos ejecutás **IMPORTAR_WEB** en el VFP (ver abajo). Cada comprobante se graba en una transacción: o queda completo o no queda.
3. El programa deja `<intercambio>\salida\resultado_….txt` (OK o ERR con el motivo) y mueve el lote a `entrada\procesados`.
4. En la próxima corrida del agente, el resultado vuelve a la web: los grabados dejan de estar pendientes; los rechazados aparecen en **Sistema → Agente → Web → VFP** con el motivo (hay un botón para reintentar).

## Instalación
1. Copiá `IMPORTAR_WEB.PRG` a la carpeta del sistema VFP e incluilo en el proyecto (o dejalo suelto en el `SET PATH`).
2. Si querés, agregalo al menú del sistema como «Importar de la web» (`DO IMPORTAR_WEB`).
3. Confirmá la carpeta de intercambio: por omisión `C:\FacturaWebAgente\intercambio` (la misma `carpeta_intercambio` del `config.json` del agente).

## Uso (con la base de datos abierta, como cualquier opción del sistema)
```
DO IMPORTAR_WEB WITH .T.                 && SIMULA: revisa todo y avisa qué haría, sin grabar nada (probalo primero)
DO IMPORTAR_WEB                          && importa los lotes pendientes
DO IMPORTAR_WEB WITH .F., "D:\otra"      && con otra carpeta de intercambio
```

## Qué hace con cada cosa
- **Comprobante**: se agrega a `SUBDIARIO` con sus filas de `IVA`, `ESTADISTICA`, `CAJA` y `CTACT`. Si ya existe (mismo tipo, letra, sucursal, punto de venta, número y fecha) no lo repite y lo da por grabado. Los números internos (`FE_CLAVE`) los asigna el VFP.
- **Sucursal**: tiene que existir en `SUCURSALES` del VFP (el comprobante trae su sucursal y punto de venta). Si no, lo rechaza con ese motivo: cargala en Tablas → Sucursales y apretá «Reintentar» en la web.
- **Stock**: cada comprobante con artículos genera **un movimiento de stock** del VFP (tipo `BS` baja para facturas, `AS` alta para notas de crédito), actualiza `STOCK` (por sucursal + artículo) y deja el número en `SUBDIARIO.MVSTK`. Los ingresos, egresos, ajustes y transferencias cargados en la web se graban igual (uno por movimiento). Necesita los tipos `BS` y `AS` en `TIPOS_MVSTK` y `PARAMETROS.ULTIMO_MVSTK`.
- **Qué sucursal del VFP usa el stock**: la que se configura por depósito en la web (Stock → Depósitos → «Sucursal VFP»; por omisión 1).
- Los campos que el VFP de la instalación no tenga se ignoran.

## Si algo falla
- «No pude abrir una tabla»: abrí primero la base de datos del sistema (`OPEN DATABASE …\datos`) o ejecutalo desde dentro del sistema.
- Un comprobante con error queda sin grabar (la transacción se deshace) y su motivo vuelve a la web.
- Los números salen con punto decimal: el programa lo fuerza mientras corre (`SET POINT TO "."`) y lo deja como estaba.
