# Facturador web — análisis de la migración desde VFP9

Estimación y análisis de Claude, 11/11/2026, sobre el código en `vfp/` (1.013 archivos, 147 tablas).

## 1. Qué hay en el sistema VFP

El proyecto `OYS.pjx` tiene **dos sistemas** con menús separados:

| Menú | Qué es | ¿Entra en el facturador web? |
|---|---|---|
| `menu1.mnx` | **Facturación**: tablas, clientes, artículos, facturación electrónica, cta. cte., cheques, listados, AFIP | **Sí, es el alcance** |
| `menuprod.mnx` | **Producción textil**: telas, cortes, talleres, materias primas, fórmulas, órdenes de compra, liquidación a talleres | A confirmar (propuesta: no, o etapa aparte) |

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
| `subdiario` | 3.396 | Cabecera de cada comprobante (FC/NC/ND A/B/C/E), totales, IVA, CAE, vto. CAE, comprobante asociado, mail enviado | `comprobantes` |
| `iva` | 3.383 | IVA por alícuota de cada comprobante | `comprobante_iva` |
| `DET` / `ITEMS` | 906 / 0 | Ítems de los comprobantes | `comprobante_items` |
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

## 2. Propuesta técnica

- **PHP 8 + MySQL 8, sin framework**, igual que oesteparts: mismo hosting, mismo deploy (integración Git de Ferozo), se reaprovechan el login con bloqueo, los roles, la multi-empresa, los catálogos AFIP, la exportación a Excel y las pruebas.
- **Base propia** (`a0021735_factura`) con usuario propio.
- **Carpeta** `public_html/facturador_web`; excepción en `public_html/.htaccess`; subdominio `facturador.oys.com.ar` (DNS de oys.com.ar → IP del hosting + dominio adicional en Ferozo) con SSL.
- **Migración de datos**: script que lee los DBF (Python `dbfread` en la PC de Hernán) y genera el SQL de carga. Se corre una vez para pruebas y otra el día del pase.
- **Impresión**: HTML imprimible / PDF con el diseño de `IMPRESION_FACTURA_A/B/E.FRX`.
- **Mail de facturas**: hoy usa CDO de Windows; en web, SMTP del hosting.
- El prototipo Node (`src/`, `public/`) queda como referencia y se retira cuando arranque la versión PHP.

## 3. Etapas

| # | Etapa | Contenido |
|---|---|---|
| 1 | Base y estructura | Login, usuarios y permisos, empresa, puntos de venta, tablas (12), clientes, artículos; script de migración de maestros |
| 2 | Facturación | FC/NC/ND A, B, C (y E si exportan), ítems, IVA por alícuota, formas de pago, comprobante asociado en NC, numeración, impresión, mail |
| 3 | Factura electrónica ARCA | Integrar `lib/afip_fe` (ya hecho y probado): configuración, ABM de equivalencias de IVA, Revisar → Emitir, QR, certificado y punto de venta de la web |
| 4 | Cta. cte. y cheques | Recibos con retenciones y valores, imputación manual y automática, ajustes, cartera de cheques |
| 5 | Listados | Subdiario de ventas, cobranzas, cta. cte. por fecha / comprobante, deuda pendiente, resumen de deuda, cheques diferidos, clientes, Libro IVA digital; todo a Excel |
| 6 | Pase a producción | Migración de datos históricos, controles de saldos contra VFP, capacitación |

## 4. Estimación (Claude, 11/11/2026)

Solo el menú de facturación (`menu1`). Producción textil, aparte.

| | Humano (dev solo) | Con IA |
|---|---|---|
| Desarrollo | 150–225 h | 18–28 h de sesión |
| Pruebas | 38–55 h | 5–8 h (automáticas) |
| Entorno por sesión | — | ~15 min × 8–12 sesiones |
| Tiempo de Hernán (revisar, probar, certificado y PV en ARCA, desplegar, cliente) | — | 26–40 h |
| **Horas hombre reales** | **190–280 h** | **26–40 h de Hernán** |
| Calendario | 2–3 meses | 4–5 semanas, según las pruebas de Hernán y del cliente |

Por etapa (con IA, horas de sesión): 1) 4–6 h · 2) 6–9 h · 3) **1,5–3 h** (antes 5–8 h, gracias a `afip_fe`) · 4) 6–9 h · 5) 4–6 h · 6) 3–5 h.

Estimación actualizada el 11/11/2026 al sumar el módulo `afip_fe`. Un humano también ahorra: el módulo ya resuelve WSAA/WSFE, QR, bloqueo de números y recuperación ante cortes.

- Mis horas son menos confiables que las humanas. Lo que puede alargarlas: reglas escondidas en `facturacion_ped` (8.800 líneas), datos viejos inconsistentes en los DBF y el trámite del certificado en ARCA (el sandbox no llega a ARCA: lo prueba Hernán).
- **Cuello de botella:** pruebas de homologación con ARCA y el control de saldos migrados contra el VFP, no el desarrollo.

## 5. Preguntas abiertas

1. ¿Entra **producción textil** (`menuprod`)? ¿Es del mismo cliente?
2. ¿Es para **un cliente** o un **producto** para varios (multi-empresa / multi-CUIT)?
3. ¿Qué comprobantes emiten hoy: A, B, C, **E (exportación)**, NC, ND, remitos, tickets con **controlador fiscal**? (`sucursales` tiene campos de controlador fiscal y comprobante manual.)
4. ¿Se usan **lista de precios** por cliente, descuentos, precios en dólares (`cotizacion_dolar`, `valor_dolar`)?
5. ¿Qué **retenciones** se cargan en los recibos (`cobra.COD_RT1..5`)?
6. ARCA: ¿qué CUIT y alias usa hoy el programa externo? ¿Se puede crear un punto de venta nuevo para la web?
7. ¿Cuántos **usuarios y sucursales** van a usar el web a la vez?
8. ¿Se migra **todo el histórico** o solo saldos de cta. cte. + maestros?
