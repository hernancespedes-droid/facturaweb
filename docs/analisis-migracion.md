# Facturador web — análisis de la migración desde VFP9

Estimación y análisis de Claude, 11/11/2026, sobre el código en `vfp/` (1.013 archivos, 147 tablas).

## 1. Qué hay en el sistema VFP

El proyecto `OYS.pjx` tiene **dos sistemas** con menús separados:

| Menú | Qué es | ¿Entra en el facturador web? |
|---|---|---|
| `menu1.mnx` | **Facturación**: tablas, clientes, artículos, facturación electrónica, cta. cte., cheques, listados, AFIP | **Sí, es el alcance** |
| `menuprod.mnx` | **Producción textil**: telas, cortes, talleres, materias primas, fórmulas, órdenes de compra, liquidación a talleres | **No** (decisión de Hernán, 11/11/2026) |

### Menú de facturación (`menu1`), 44 opciones

- **Tablas (12):** sucursales, rubros, subrubros, provincias, parámetros, bancos, cotización del dólar, vendedores, alícuotas de IVA, condiciones de pago, transportes, formas de pago.
- **Maestros (2):** clientes, artículos.
- **Cargas:** facturación (`facturacion_ped`, el form más grande: ~8.800 líneas), envío masivo de facturas por mail (deshabilitado), consulta de comprobantes.
- **Cta. cte. (11):** recibos (`cta_recibos`), imputación manual, ajustes de cta. cte. y de cheques, cta. cte. por fecha y por comprobante, cheques diferidos, búsqueda de cheques, deuda pendiente, resumen de deuda, modificación de cheques.
- **Listados (5):** subdiario de ventas, cobranzas, remitos, clientes, archivo de ventas para AFIP (Libro IVA digital).
- **Utilitarios:** auditoría de usuarios, control de errores, backup, reindexar, ZAP, modificar reporte FE, notas de crédito (`notas_credito.prg`).

Varios de los utilitarios **no hacen falta en web** (reindexar, ZAP, backup de DBF): los reemplaza MySQL y el backup del hosting.

### Tablas que importan (con datos en la copia)

| Tabla VFP | Registros | Qué guarda | En MySQL |
|---|---|---|---|
| `subdiario` | 3.396 | Cabecera de cada comprobante (FC/NC/ND A/B/C/E), totales, IVA, CAE, vto. CAE, comprobante asociado, mail enviado | `subdiario` (misma tabla; clave con fecha: ver `docs/migracion-dbf.md`) |
| `iva` | 3.383 | IVA por alícuota de cada comprobante | `iva` |
| `estadistica` | 3.821 | Renglones de los comprobantes (confirmado en la etapa 2; `DET` es una lista de precios de otra instalación y `ITEMS` está vacía) | `estadistica` (con `subdiario_id`) |
| `ctact` | 3.489 | Movimientos de cuenta corriente | `ctacte_movimientos` |
| `caja` | 3.340 | Valores de cada cobro/factura (forma de pago, cheque: banco, número, vencimiento) | `valores` |
| `cobra`, `imputaciones` | 30 / 54 | Recibos, retenciones, imputación a facturas | `recibos`, `imputaciones` |
| `clientes` | 531 | Maestro de clientes (lista de precios, vendedor, cond. de pago, transporte, mail de factura) | `clientes` |
| `articulos` | 69 | Artículos | `articulos` |
| `sucursales` | 1 | Puntos de venta y **numeradores** (última factura A, B, E, recibo, remito…) | `puntos_venta`, `numeradores` |
| `fe_Configuracion`, `fe_Parametros` | 1 / 74 | Configuración de factura electrónica | `fe_config` |
| `cla_usuarios`, `cla_opciones`, `cla_accesos` | 23 / 101 / 7.639 | Usuarios y permisos por opción del menú | `usuarios`, `roles`, `rol_permisos` |
| `auditoria` | 9.736 | Quién entró a qué opción | `auditoria` |
| tablas chicas | — | bancos, provincias, formas de pago, tasas de IVA, condiciones de pago, vendedores, transportes, rubros | catálogos |

Tablas con nombre `Copia de…`, `tmp_*`, `temp*`, `pp`, `gig`, `ABC` parecen restos de pruebas: no se migran.

### Factura electrónica hoy

El VFP **no** habla con ARCA directamente: graba el comprobante en `subdiario` y llama a un **programa externo** (`sucursales.pathElectronica`, clase `metodosexpo`) que pide el CAE y lo escribe en `fe_ccae` / `fe_dvtocae`.

### Factura electrónica en la web: módulo `afip_fe` (ya probado en Reciclados)

Se reutiliza el módulo PHP `afip_fe` v2 (10/11/2026), copiado en `lib/afip_fe/` (carpeta no pública, `lib/.htaccess` niega el acceso). Lineamientos en `lib/afip_fe/GUIA_NUEVO_PROYECTO.md`.

- Este facturador VFP usa **el mismo esquema O&S** que el módulo espera: `subdiario` con los campos `fe_*`, `iva`, `tributos`, `fe_parametros`, `fe_actualizacion` y `equivalencias_afip` (con `TABLA`, `CODIGOAFIP`, `CODIGOOYS`). Por eso conviene **mantener esos nombres de tablas y campos en MySQL**: el adaptador `OysSubdiario` funciona casi sin cambios y la migración de datos es casi 1 a 1.
- Flujo de pantalla del módulo: formulario → **Revisar** → **Emitir y pedir CAE**; si ARCA rechaza no se graba nada; protección contra doble envío; impresión A4 con QR (`FacturaHtml`).
- Pruebas: `tests/prueba_facturador.php` pasa acá (ARCA simulado). `prueba.php` y `prueba_abm.php` necesitan la base de prueba de Reciclados, que no está en el paquete.

**Puesta en marcha (según la guía), con cambios respecto de lo que dije antes:**
- **Certificado propio para la web**, no el mismo del programa externo (ARCA da un solo ticket por certificado y se trabarían hasta 12 h). Lo ideal: "Agregar certificado" en el alias que ya funciona, con el mismo DN. Claude genera la clave y el `.csr`; el trámite en ARCA lo hace Hernán.
- **Punto de venta propio para la web** (RECE para aplicativo y web services), para no competir por los números con el escritorio mientras convivan.
- `config_afip.php`, `.crt`, `.key` y `cacert.pem` **fuera de `public_html`** (por ejemplo `<raíz del hosting>/afip_facturador/`).
- Se entrega en **modo prueba** (`factura_grabar = false`); la facturación real la activa Hernán.
- Antes de emitir: `probar_conexion.php` tiene que dar OK (FEDummy, TA, puntos de venta, último número, condiciones de IVA).

## 1 bis. Decisiones de Hernán (11/11/2026)

- **Alcance:** solo facturación (`menu1`). Producción textil no entra.
- **Producto por suscripción** para varios clientes.
- **Comprobantes:** A, B, C, **E** (exportación), **M** y **FCE MiPyME** (FC/NC/ND). Sin controlador fiscal.
- **ARCA:** cada cliente con su **punto de venta** y su **certificado** nuevos.
- **Primer cliente: OYS**, con migración de **todo el histórico**.

### Multi-empresa o una copia por cliente: propuesta

**Un solo código, una instalación por cliente** (no multi-empresa en una sola base):

| | Una instalación por cliente (propuesta) | Multi-empresa en una base |
|---|---|---|
| Datos fiscales | Cada cliente en su base: imposible que uno vea al otro | Todo junto; un error de filtro muestra datos ajenos |
| Certificado y PV de ARCA | Su carpeta privada propia | Varios certificados en una sola configuración |
| Backup / restaurar / dar de baja | Por cliente, simple | Hay que separar filas |
| Migración de cada cliente (DBF) | Casi 1 a 1 con el esquema O&S (`subdiario`, `iva`…) | Hay que agregar `empresa_id` a todo y adaptar `afip_fe` |
| Actualizar versión | Un `git pull` por instalación + "Aplicar cambios" (se automatiza con un script) | Una sola vez |
| Costo de hosting | Una base y una carpeta por cliente (Ferozo lo permite) | Una sola |

**Aprobado por Hernán (11/11/2026): una instalación por cliente.** Se deja preparado para pasar a multi-empresa más adelante si se llega a muchos clientes. Si un cliente tiene **varios CUIT**, se le da una instalación por CUIT o se suma "emisores" dentro de la instalación cuando haga falta.

Cada instalación: `facturador.<dominio>` → carpeta propia → base propia → `afip_<cliente>/` fuera de `public_html`. Un archivo de **licencia** por instalación (vencimiento de la suscripción) bloquea la emisión si no se renovó, con aviso previo.

### Factura E (exportación)

`afip_fe` cubre A, B, C, M y FCE (WSFEv1), pero **no la E**: ARCA la autoriza con otro servicio, **WSFEX** (país de destino, Incoterms, idioma, moneda y cotización, permisos de embarque). Hay que sumarlo al módulo (sirve después para todos los sistemas O&S). El certificado necesita también la relación con "Facturación Electrónica de Exportación".

## 2. Propuesta técnica

- **PHP 8 + MySQL 8, sin framework**, igual que oesteparts: mismo hosting, mismo deploy (integración Git de Ferozo), se reaprovechan el login con bloqueo, los roles, la multi-empresa, los catálogos AFIP, la exportación a Excel y las pruebas.
- **Base propia** (`a0021735_factura`) con usuario propio.
- **Carpeta** `public_html/facturador_web`; excepción en `public_html/.htaccess`; subdominio `facturador.oys.com.ar` (DNS de oys.com.ar → IP del hosting + dominio adicional en Ferozo) con SSL.
- **Migración de datos**: migrador en PHP dentro del sistema (`bin/migrar-dbf.php` o subiendo un .zip en Sistema), con simulación y opción de generar el SQL. Se corre una vez para pruebas y otra el día del pase. Ver `docs/migracion-dbf.md`.
- **Impresión**: HTML imprimible / PDF con el diseño de `IMPRESION_FACTURA_A/B/E.FRX`.
- **Mail de facturas**: hoy usa CDO de Windows; en web, SMTP del hosting.
- El prototipo Node (`src/`, `public/`) queda como referencia y se retira cuando arranque la versión PHP.

## 3. Etapas

| # | Etapa | Contenido |
|---|---|---|
| 1 | Base y estructura | Login, usuarios y permisos, empresa, puntos de venta, tablas (12), clientes, artículos; script de migración de maestros. **Hecha: 0.2.0** (`docs/entregas/0.2.0-etapa1.md`) |
| 2 | Facturación | FC/NC/ND A, B, C (y E si exportan), ítems, IVA por alícuota, formas de pago, comprobante asociado en NC, numeración, impresión, mail. **Hecha: 0.3.0** (`docs/entregas/0.3.0-etapa2.md`), sin CAE (etapa 3) |
| 3 | Factura electrónica ARCA | Integrar `lib/afip_fe` (A, B, C, M, FCE): configuración, ABM de equivalencias de IVA, Revisar → Emitir, QR. La **E (WSFEX) queda para otra etapa** (los clientes actuales son todos nacionales) |
| 4 | Cta. cte. y cheques | Recibos con retenciones y valores, imputación manual y automática, ajustes, cartera de cheques |
| 5 | Listados | Subdiario de ventas, cobranzas, cta. cte. por fecha / comprobante, deuda pendiente, resumen de deuda, cheques diferidos, clientes, Libro IVA digital; todo a Excel |
| 6 | Pase a producción OYS | Migración de todo el histórico, controles de saldos contra VFP, capacitación |
| 7 | Producto | Script de alta de una instalación nueva (base, carpeta, config, usuario inicial), licencia de suscripción, actualización de todas las instalaciones, migrador DBF reutilizable |

## 4. Estimación (Claude, 11/11/2026)

Solo el menú de facturación (`menu1`). Producción textil, aparte.

| | Humano (dev solo) | Con IA |
|---|---|---|
| Desarrollo | 185–275 h | 23–35 h de sesión |
| Pruebas | 45–65 h | 6–10 h (automáticas) |
| Entorno por sesión | — | ~15 min × 10–14 sesiones |
| Tiempo de Hernán (revisar, probar, certificado y PV en ARCA, desplegar, cliente) | — | 30–45 h |
| **Horas hombre reales** | **230–340 h** | **30–45 h de Hernán** |
| Calendario | 3–4 meses | 5–6 semanas, según las pruebas de Hernán y del cliente |

Por etapa (con IA, horas de sesión): 1) 4–6 h · 2) 6–9 h (con E, M y FCE) · 3) 1,5–3 h + **WSFEX 3–5 h** · 4) 6–9 h · 5) 4–6 h · 6) 3–5 h (todo el histórico) · 7) 2–4 h.

Estimación actualizada el 11/11/2026 con las decisiones de Hernán (E, M, FCE, histórico completo, producto por suscripción).

- Mis horas son menos confiables que las humanas. Lo que puede alargarlas: reglas escondidas en `facturacion_ped` (8.800 líneas), datos viejos inconsistentes en los DBF y el trámite del certificado en ARCA (el sandbox no llega a ARCA: lo prueba Hernán).
- **Cuello de botella:** pruebas de homologación con ARCA y el control de saldos migrados contra el VFP, no el desarrollo.

## 5. Preguntas abiertas

2. ¿Se usan **listas de precios** por cliente, descuentos, precios en dólares (`cotizacion_dolar`, `valor_dolar`)?
3. ¿Qué **retenciones** se cargan en los recibos (`cobra.COD_RT1..5`)? ¿Percepciones de IIBB en las facturas (`tributos`)?
4. ¿Cuántos **usuarios y sucursales** de OYS van a usarlo a la vez?
5. Para migrar el histórico: una **copia de los DBF con datos** de OYS (fuera del repo, por ejemplo en Drive).

## 6. Datos de OYS (copia del 11/11/2026, `datos_facturador_web…rar` en Drive)

283 archivos, 18 MB. **No se suben al repo.**

- **Comprobantes (`subdiario`):** 3.371, del 02/03/2010 al 07/02/2025 (casi todo 2010–2019; 2021 y 2025, uno cada uno). Un solo punto de venta: **0004**.
  - FC A 3.207 · NC A 77 · FC B 69 · NC B 16 · ND A 1 · ND B 1. **No hay C, M, E ni FCE** en el histórico.
  - Todos tienen CAE salvo 2.
- **Otros:** `ctact` 810 · `caja` 731 · `DET` 906 ítems · clientes 519 · usuarios 8 · formas de pago 28.
- **`fe_parametros` (71) es exactamente el esquema que lee `OysSubdiario`** (nombres de tabla y campo, URLs WSAA/WSFE/WSFEX, datos legales de la cabecera, impresión). Se migra tal cual.
- **`equivalencias_afip`:** tiene Comprobante (21), Iva (4), Moneda (50) y Tributo (5), pero **ninguna fila `Equivaliencia`** (condición de IVA del receptor, RG 5616). Hay que cargarlas antes de emitir.
- **Condición de IVA del cliente en este sistema** (combo de `clientes.scx`, distinto del `catg_iva` de Reciclados):

| Código VFP | Condición | Clientes | Id ARCA propuesto |
|---|---|---|---|
| 1 | Inscripto | 186 | 1 |
| 2 | No Inscripto | 0 | — (ya no existe) |
| 3 | Consumidor Final | 317 | 5 |
| 4 | Exento (B) | 11 | 4 |
| 5 | Exento (A) | 1 | 4 |
| 6 | Liberado | 0 | 10 |
| 7 | Monotributo | 4 | 6 |
| 8 | Exento Especial (A) | 0 | 4 |
| 9 | Exento Especial (B) | 0 | 4 |

  - **Decisión de Hernán (11/11/2026): regla actual de ARCA.** RI emite **A** a RI y monotributistas, **B** al resto (exentos incluidos, aunque el VFP tenga "Exento (A)"). Equivalencias aprobadas como en la tabla.
- **`empresas.dbf`** tiene el nombre de otra empresa (restos de una instalación anterior) y `sucursales` dice "Organizacion & Sistemas": **son los datos del facturador de OYS** (confirmado por Hernán). Se usan como instalación de prueba para la aprobación del CEO.
