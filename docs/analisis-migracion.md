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

Varias de los utilitarios **no hacen falta en web** (reindexar, ZAP, backup de DBF): los reemplaza MySQL y el backup del hosting.

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

El VFP **no** habla con AFIP directamente: graba el comprobante en `subdiario` y llama a un **programa externo** (`sucursales.pathElectronica`, clase `metodosexpo`) que pide el CAE y lo escribe en `fe_ccae` / `fe_dvtocae`. Ese ejecutable no está en el repo.

En web hay que **programar WSAA + WSFEv1 en PHP**: firma del ticket de acceso con el certificado (`openssl`) y SOAP a ARCA, más el QR de la RG 4892. Se reutiliza el **mismo certificado y clave privada** que usa hoy el programa externo, si el CUIT y el punto de venta son los mismos.

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
| 3 | Factura electrónica ARCA | WSAA + WSFEv1 (homologación y producción), CAE, QR, reintento ante caídas, consulta de último número |
| 4 | Cta. cte. y cheques | Recibos con retenciones y valores, imputación manual y automática, ajustes, cartera de cheques |
| 5 | Listados | Subdiario de ventas, cobranzas, cta. cte. por fecha / comprobante, deuda pendiente, resumen de deuda, cheques diferidos, clientes, Libro IVA digital; todo a Excel |
| 6 | Pase a producción | Migración de datos históricos, controles de saldos contra VFP, capacitación |

## 4. Estimación (Claude, 11/11/2026)

Solo el menú de facturación (`menu1`). Producción textil, aparte.

| | Humano (dev solo) | Con IA |
|---|---|---|
| Desarrollo | 160–240 h | 22–34 h de sesión |
| Pruebas | 40–60 h | 6–10 h (automáticas) |
| Entorno por sesión | — | ~15 min × 10–14 sesiones |
| Tiempo de Hernán (revisar, probar, ARCA homologación, desplegar, cliente) | — | 30–45 h |
| **Horas hombre reales** | **200–300 h** | **30–45 h de Hernán** |
| Calendario | 2–3 meses | 4–6 semanas, según las pruebas de Hernán y del cliente |

Por etapa (con IA, horas de sesión): 1) 4–6 h · 2) 6–9 h · 3) 5–8 h · 4) 6–9 h · 5) 4–6 h · 6) 3–5 h.

- Mis horas son menos confiables que las humanas. Lo que puede duplicarlas: **ARCA** (el sandbox no llega a los servicios de AFIP, así que la homologación la prueba Hernán), reglas escondidas en `facturacion_ped` (8.800 líneas), y datos viejos inconsistentes en los DBF.
- **Cuello de botella:** pruebas de homologación con ARCA y el control de saldos migrados contra el VFP, no el desarrollo.

## 5. Preguntas abiertas

1. ¿Entra **producción textil** (`menuprod`)? ¿Es del mismo cliente?
2. ¿Es para **un cliente** o un **producto** para varios (multi-empresa / multi-CUIT)?
3. ¿Qué comprobantes emiten hoy: A, B, C, **E (exportación)**, NC, ND, remitos, tickets con **controlador fiscal**? (`sucursales` tiene campos de controlador fiscal y comprobante manual.)
4. ¿Se usan **lista de precios** por cliente, descuentos, precios en dólares (`cotizacion_dolar`, `valor_dolar`)?
5. ¿Qué **retenciones** se cargan en los recibos (`cobra.COD_RT1..5`)?
6. Certificado de ARCA: ¿lo tienen (`.crt` + `.key`) y en qué CUIT y punto de venta?
7. ¿Cuántos **usuarios y sucursales** van a usar el web a la vez?
8. ¿Se migra **todo el histórico** o solo saldos de cta. cte. + maestros?
