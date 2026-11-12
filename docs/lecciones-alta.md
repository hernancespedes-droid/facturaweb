# Lecciones de las altas

Cada alta suma acá lo que salió distinto de lo esperado, **sin datos del cliente**. Se lee antes de empezar el alta siguiente.
Formato: qué pasó → qué hacer.

## De OYS (alta 1, noviembre de 2026)
**Datos y migración**
- Datos del VFP de O&S llegan en `.rar` (RAR5): `unrar` los abre. En el contenedor hay que instalarlo (`apt-get install unrar-free`). → Pedir `.rar`/`.zip` por Drive y abrirlos solo en el scratchpad.
- El **número interno de un comprobante del VFP no es único** (ARCA renumera: casi todos difieren del número de ARCA). → Clave = tipo + letra + sucursal + número + **fecha**; mostrar siempre el número de ARCA. Las tablas hijas (`iva`, `estadistica`, `caja`, `ctact`) llevan su propio `FE_CLAVE` autonumérico y se relacionan por tipo/letra/sucursal/número.
- Aparecen **huérfanos** en el VFP (renglones, caja y cta. cte. sin comprobante): no se migran y se informan.
- `empresas.dbf`/`sucursales` pueden traer nombres de otra instalación del sistema: confirmar con el cliente qué datos son los suyos. `DET.DBF` no son ítems: los ítems están en `estadistica.dbf`.
- Códigos de catálogo usados y no existentes (rubro, vendedor, provincia…): la migración los agrega como «(DEL VFP)»; hay que ponerles nombre real en Tablas.
- Clientes con CUIT inválido: se migran igual, se informan.
- Siempre `--simular` primero y comparar cantidades y saldos contra el VFP antes de dar por buena la migración.

**ARCA**
- Mejor **certificado propio para la web** y **punto de venta propio** (el del VFP no se toca). Idealmente «Agregar certificado» en el alias que ya funciona (mismo DN).
- La condición de IVA del cliente se traduce con `equivalencias_afip` (tipo `Equivaliencia`, con ese error de tipeo del VFP): cargarla antes de emitir.
- Primero modo simulado y homologación; en producción, recién con el cliente presente.

**Hosting**
- DonWeb (Ferozo) con GIT, **sin SSH**: instalar con `/instalar` y `instalar_token`; actualizar con GIT y **Sistema → Aplicar cambios**. No activar el webhook de auto-despliegue.
- El `.htaccess` de `public/` tiene que pasar el encabezado `Authorization` (el agente lo necesita).
- Límite de subida del hosting: el agente manda todo comprimido junto; si el hosting corta, avisa.

**Agente y VFP en paralelo**
- En `config.json` del agente la barra `\` va doble (`"x:\\carpeta"`) o con `/`. → El error de PowerShell («Secuencia de escape no reconocida») lo muestra.
- La **clave del agente nunca se pega en el chat** (si pasa: revocarla y crear otra en Sistema → Agente).
- Mientras el VFP y la web conviven, el VFP manda sobre clientes y artículos (el agente los pisa); no editar en la web lo que el VFP también carga.
- El importador `IMPORTAR_WEB.PRG` hay que probarlo **primero en una copia de los datos** y con la opción de simulación.

**Trabajo con Claude**
- Los fuentes de `vfp/` en el repo pueden ser de otra instalación: para escribir algo que toque el VFP del cliente, mirar **sus** DBF.
- Las decisiones de negocio que se toman sin consultar se marcan `DECISIÓN D-xx` y van a `docs/decisiones-pendientes.md`.
- MySQL del contenedor se cae al reiniciar: `bash bin/setup-dev.sh`.
