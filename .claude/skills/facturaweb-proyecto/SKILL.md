---
name: facturaweb-proyecto
description: Contexto, decisiones y estado del proyecto FacturaWeb (facturador web de O&S que reemplaza al sistema de facturación VFP9, primer cliente OYS; producto por suscripción con una instalación por cliente). Cargar en cualquier tarea de este proyecto (etapas, migración DBF, factura electrónica, despliegue, presupuesto).
---

# FacturaWeb — contexto del proyecto

## Qué es

- Facturador web de **Organización & Sistemas (O&S)** que reemplaza el menú de facturación (`menu1`) del sistema VFP9 de O&S.
  Producción textil (`menuprod`) **no** entra (decisión de Hernán, 11/11/2026).
- **Producto por suscripción** para varios clientes. **Primer cliente: OYS**, con migración de todo el histórico.
- Repo `hernancespedes-droid/facturaweb`. Fuentes VFP de referencia en `vfp/`. Análisis completo: `docs/analisis-migracion.md`.
- Comprobantes a cubrir: A, B, C, E (exportación, WSFEX, etapa aparte), M y FCE MiPyME (FC/NC/ND). Sin controlador fiscal.

## Decisiones (todas de Hernán, 11/11/2026)

- **PHP 8.2+ y MySQL 8 sin framework**, tomando como base el núcleo de `hernancespedes-droid/oesteparts`.
- **Una instalación por cliente** (no multi-empresa): carpeta, base, certificado y punto de venta de ARCA propios. Docroot `public/`.
  Si un cliente tiene varios CUIT: una instalación por CUIT o "emisores" más adelante.
- **Esquema O&S**: tablas y campos con los nombres largos de `datos.dbc` (clientes, articulos, sucursales, subdiario, iva, fe_parametros…)
  para migrar 1 a 1 y usar `lib/afip_fe` (adaptador `OysSubdiario`) casi sin cambios.
- **Regla de letra (ARCA actual)**: emisor RI → A a RI y monotributistas, B al resto (exentos incluidos, aunque el VFP tenga "Exento (A)").
- Equivalencias de condición de IVA del cliente (código VFP → Id ARCA): 1→1, 3→5, 4→4, 5→4, 6→10, 7→6, 8→4, 9→4 (2 "No inscripto" ya no existe).
  En OYS `equivalencias_afip` no tiene filas `Equivaliencia`: hay que cargarlas antes de emitir.
- ARCA: **certificado propio para la web** (idealmente "Agregar certificado" en el alias que ya funciona, mismo DN) y **punto de venta propio**
  (RECE). Configuración y certificados **fuera de `public_html`**. Se entrega en modo prueba (`factura_grabar = false`); la facturación real la activa Hernán.
  Claude no entra a ARCA ni escribe claves: prepara clave privada, `.csr` e instrucciones.
- Cambios de base: agregar está bien; **borrar lo autoriza Hernán**.

## Etapas y estado

| # | Etapa | Estado |
|---|---|---|
| 1 | Base: login, usuarios y roles, 12 tablas, clientes, artículos, migrador DBF de maestros | **Hecha: versión 0.2.0** (nota `docs/entregas/0.2.0-etapa1.md`), falta la prueba de Hernán |
| 2 | Facturación FC/NC/ND A, B, C (E si exportan), ítems, IVA por alícuota, numeración, impresión, mail | **Hecha: versión 0.3.0** (nota `docs/entregas/0.3.0-etapa2.md`), **sin CAE**; falta la prueba de Hernán |
| 3 | Factura electrónica con `lib/afip_fe` (A, B, C, M, FCE); WSFEX (E) en otra etapa | **Hecha: versión 0.4.0** (nota `docs/entregas/0.4.0-etapa3.md`) con ARCA **simulado** probado; falta que Hernán haga certificado/PV (`docs/ARCA-PUESTA-EN-MARCHA.md`) y confirme las decisiones (`docs/decisiones-pendientes.md`) |
| 4 | Cta. cte. y cheques (recibos, imputación, ajustes, cartera) | Pendiente |
| 5 | Listados (subdiario, cobranzas, deuda, Libro IVA digital) a Excel | **En curso**: subdiario de IVA ventas hecho (0.4.2); faltan los demás |
| 6 | Pase a producción OYS: histórico completo, control de saldos contra VFP, capacitación | Pendiente |
| 7 | Producto: alta de instalaciones, licencia de suscripción, actualización masiva | Pendiente |

Estimación con IA (horas de sesión): 1) 4–6 · 2) 6–9 · 3) 1,5–3 + WSFEX 3–5 · 4) 6–9 · 5) 4–6 · 6) 3–5 · 7) 2–4.
Humano: 230–340 h. Cuello de botella: homologación con ARCA y control de saldos migrados.

## Cómo quedó la etapa 1 (lo que hay que saber para seguir)

- Sin `empresas`: `usuarios.rol_id` (un rol por usuario). Roles de fábrica: Master (O&S), Administrador, Operador
  (Operador = maestros y tablas menos Sucursales y Parámetros).
- Permisos = opciones del menú del VFP: `tablas.<tabla>` (12), `clientes.ver/editar`, `articulos.ver/editar`, `usuarios.administrar`, `configuracion.administrar`.
- Las 12 tablas se declaran en `src/Modules/Tablas/Definiciones.php` (pantalla genérica). La clave O&S no se edita; no se borra un código en uso.
- `parametros` (fila 1) = datos de la empresa; `configuracion` = ajustes de la web.
- Clientes: código automático = mayor entre `parametros.ultimo_cliente` y el último código + 1, con la fila bloqueada. CUIT obligatorio salvo
  consumidor final / no inscripto; CUIT repetido se confirma. No se borran: se desactivan (`clientes.activo`, agregado en la web).
- Artículos: código lo escribe el usuario (mayúsculas, hasta 15, no se cambia). Baja = `estado` 'BA'. URL `/articulos/ficha?codigo=`.
- Migrador DBF en PHP (`src/Modules/Migracion/`): lee DBF/FPT/DBC sin librerías, nombres largos desde `datos.dbc`, una transacción,
  simulación, `--sql`, no pisa sin `--reemplazar`, agrega códigos faltantes "(DEL VFP)". Por consola `bin/migrar-dbf.php` o subiendo
  un .zip en Sistema (Master). Usuarios del VFP: desactivados y sin contraseña (se resetea desde Usuarios).
- Instalación sin SSH (Ferozo): `instalar_token` en `config/local.php` → `/instalar` crea tablas y Master.

## Cómo quedó la etapa 2 (lo que hay que saber para seguir)

- Emisión en `Modules/Comprobantes` (ver `CLAUDE.md`): `armar()` valida y calcula, `emitir()` numera con la sucursal bloqueada y graba `subdiario`, `iva`,
  `estadistica`, `caja` y `ctact` en una transacción. Letra con `Tipos::letra` (RI: A a inscriptos y monotributistas, B al resto; monotributo/exento: C),
  condición del cliente → Id de ARCA por `equivalencias_afip` ('Equivaliencia', ya sembrada con lo aprobado).
- **Hallazgo con los datos reales**: el número interno de los comprobantes del VFP **no es único** (ARCA renumera: 28 pares repetidos y 3.281 de 3.371
  con número interno ≠ número de ARCA). Clave = tipo + letra + sucursal + número + **fecha**; hijos con `subdiario_id`; mostrar `numero_visible()`.
- Impresión: página A4 y PDF propio (`Core\Pdf`, `ComprobantePdf`), "COMPROBANTE INTERNO" mientras no haya CAE. Mail: `Core\Mailer` (SMTP propio).
- Migrador con comprobantes: 3.371 de OYS coinciden al centavo; huérfanos del VFP (42 renglones, 28 caja, 28 ctact, 17 iva) y 56 IVA vinculados por neto.
- Diferencias con el VFP decididas por Claude, **a confirmar con Hernán**: NC no puede superar la factura; descuento solo general; crédito 0 = sin límite;
  un solo medio de pago por comprobante (el mixto y los cheques de terceros van con la etapa 4); sin anulación (se corrige con NC).
- Etapa 3 (hecha en 0.4.0): modos `interno|simulado|homologacion|produccion` en `config/local.php` (`afip`); `Modules/Arca` (Arca, ArcaSimulador, ArcaController); `afip_operaciones` registra cada pedido antes de hablar con ARCA y permite recuperar lo aprobado y no grabado; `es_prueba` marca lo no fiscal; Sistema → ARCA. Decisiones de Claude marcadas `D-xx` en el código y listadas en `docs/decisiones-pendientes.md`. Pendiente: pantalla de equivalencias de condición de IVA. Diseño original: el comprobante nace con el número de ARCA; arma el `Comprobante` de `lib/afip_fe` desde lo calculado; punto de venta y certificado propios de la web.

## Regla de interfaz: buscar y cargar artículos y clientes (decisión de Hernán, 12/11/2026)

**Siempre que una pantalla pida un artículo o un cliente, el campo es un buscador**, no un texto libre ni un desplegable con todo:
- Se puede escribir (o leer con pistola) el código, el código de barras (EAN-13 / DUN-14), el SKU, el CUIT o el nombre.
- Si se escribe texto, **debajo del campo se muestran las coincidencias** (código · descripción · SKU/EAN/precio) y **el usuario siempre elige una**;
  nunca se adivina ni se toma la primera. Ni Enter ni salir del campo envían el formulario ni eligen solos si hay dudas.
- Un código exacto (pistola) se resuelve directo. Un código repetido en dos artículos no se elige solo: se muestran las opciones.
- Artículos: `Articulos\BuscadorArticulos::buscar()` (lo usan el renglón de la factura y las pantallas de stock; ruta propia por permiso) +
  `public/assets/buscador.js` (`<input data-buscador-url="…">`). Clientes: `Comprobantes → buscarCliente`. Además cada buscador lleva un ícono de lupa que abre la ventana «ver lista» (`public/assets/ventana-lista.js`: `VentanaLista.articulos/clientes`; servidor `?listar=1`, `BuscadorArticulos::listar`, `buscarCliente`) para recorrer y elegir: **toda pantalla nueva con artículos o clientes lleva buscador + lupa** (`icono_lista()`). **Al crear una pantalla nueva con estos
  datos hay que reutilizar estos buscadores, no hacer otro campo.** El servidor igual vuelve a validar lo que llega.

## Estado actual (12/11/2026, versión 0.8.0) y cómo se trabaja

- **Ya facturando en ARCA producción** (Hernán emitió factura y nota de crédito): alias OYS (CUIT 20-11499319-1), certificado propio de la web, **punto de venta 7**
  (el 4 es del VFP: no se toca). Config de ARCA fuera de `public_html` (`afip_oys/` en el hosting). Modo en `config/local.php` (`afip.modo`).
- **Hosting de prueba**: DonWeb (Ferozo) con GIT, **sin SSH**; repo en `public_html/facturador_web/`, subdominio `facturador.oesteparts.site`
  apuntando a `public/`. Se actualiza con GIT → botón `>_` y luego **Sistema → Aplicar cambios**. No activar el webhook de auto-despliegue.
- **Rama `main` + pull requests** (igual que oesteparts): cada cambio en una rama `claude/…`, PR a `main` y fusión. Versión en `VERSION` (0.4.0 etapa 3, 0.4.1
  códigos de artículo/buscador/texto por renglón, 0.4.2 subdiario de IVA ventas, 0.5.0 stock, 0.6.0 agente VFP→web, 0.6.1 buscadores en stock, 0.7.0 el agente sube clientes y artículos, 0.7.1 ventana «Lista» para buscar artículos, 0.7.2 lupa y lista de clientes, 0.8.0 web→VFP).
- **Hecho más allá de las etapas**: artículos con EAN-13/DUN-14/SKU/unidades por bulto; buscador de artículos en renglones y stock; texto adicional por renglón;
  Listados → Subdiario IVA ventas (pantalla y Excel); **Stock** (movimientos + saldos en la misma transacción, varios depósitos, bultos, se factura sin stock,
  `stock.controlar` apagado por omisión); **Agente** (`agente-windows/`, PowerShell, sube comprobantes del VFP a la web; API con claves en Sistema → Agente).
- **Decisiones de Claude a confirmar**: `docs/decisiones-pendientes.md` (D-01…D-20; D-01, D-02, D-11 son interruptores de Configuración).
- **Web → VFP (0.8.0, hecho pero sin probar en el VFP real)**: lo emitido en la web con validez fiscal + el stock quedan en cola (`subdiario.vfp_enviado/vfp_error`); el agente baja un lote a `intercambio\entrada` (`GET /api/v1/vfp/pendientes`), el programa `vfp-importador/IMPORTAR_WEB.PRG` lo graba dentro del VFP (SUBDIARIO, IVA, ESTADISTICA, CAJA, CTACT, STOCK, MVSTK_*) y deja `salida\resultado_*.txt` que el agente devuelve (`POST /api/v1/vfp/confirmar`). Formato en `Agente/ExportadorVfp`. El VFP de OYS tiene las tablas de stock (hoy vacías) y los tipos BS/AS. Decisiones D-22…D-25. **Pendiente: que Hernán pruebe el `.prg` en una copia de los datos (con `DO IMPORTAR_WEB WITH .T.`) y ajustarlo.** Los datos reales de OYS se abren en el scratchpad (RAR de Drive por `download_file_content`, que guarda el resultado en un archivo; `unrar`/`unrar-free` hay que instalarlo con apt).
- **Pendiente**: Libro IVA Digital de ARCA y PDF del subdiario; carga masiva del stock inicial; probar y afinar web→VFP;
  etapas 4 (cta. cte./cheques), 6 y 7; pantalla de equivalencias de condición de IVA; probar el agente en la PC con Windows.
- **Cuidados del contenedor**: MySQL local se cae al reiniciar el contenedor (`bash bin/setup-dev.sh`; si no arranca, borrar `/var/run/mysqld/*.lock` y `/var/lib/mysql/vm.pid`).
  Las claves, certificados y datos reales de clientes nunca van al repo ni al chat.

## Datos de OYS (copia 11/11/2026, en Drive, nunca al repo)

`datos_facturador_web20261111122233.rar` (RAR5, se abre con `unrar`). 3.371 comprobantes (2010–2025, PV 0004, casi todo FC A),
519 clientes, 65 artículos, 8 usuarios. `empresas.dbf`/`sucursales` tienen nombres de otra instalación: son los datos del facturador de OYS
(confirmado). `DET.DBF` no son ítems: son 906 precios de otra instalación (no se migran); los ítems están en `estadistica.dbf` (3.821, confirmado). Clientes con CUIT inválido: 107, 245, 287.

## Preguntas abiertas para Hernán / OYS

- Listas de precios por cliente, descuentos, precios en dólares.
- Retenciones en recibos (`cobra.COD_RT1..5`) y percepciones de IIBB (`tributos`).
- Cuántos usuarios y sucursales a la vez.
- Nombre real del vendedor `01` y de la provincia `30` que se agregaron en la migración.
- Si hace falta anular comprobantes sin CAE y qué hacer con el crédito 0.
- SMTP de la casilla que envía las facturas.
- Hosting/dominio de la instalación de OYS (`facturador.oys.com.ar`, base propia) y desde qué rama despliega Ferozo.
