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
| 2 | Facturación FC/NC/ND A, B, C (E si exportan), ítems, IVA por alícuota, numeración, impresión, mail | Pendiente |
| 3 | Factura electrónica con `lib/afip_fe` (A, B, C, M, FCE); WSFEX (E) en otra etapa | Pendiente |
| 4 | Cta. cte. y cheques (recibos, imputación, ajustes, cartera) | Pendiente |
| 5 | Listados (subdiario, cobranzas, deuda, Libro IVA digital) a Excel | Pendiente |
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

## Datos de OYS (copia 11/11/2026, en Drive, nunca al repo)

`datos_facturador_web20261111122233.rar` (RAR5, se abre con `unrar`). 3.371 comprobantes (2010–2025, PV 0004, casi todo FC A),
519 clientes, 65 artículos, 8 usuarios. `empresas.dbf`/`sucursales` tienen nombres de otra instalación: son los datos del facturador de OYS
(confirmado). `DET.DBF` no son ítems: son 906 precios de otra instalación (no se migran); los ítems parecen estar en `estadistica.dbf` (3.821). Clientes con CUIT inválido: 107, 245, 287.

## Preguntas abiertas para Hernán / OYS

- Listas de precios por cliente, descuentos, precios en dólares.
- Retenciones en recibos (`cobra.COD_RT1..5`) y percepciones de IIBB (`tributos`).
- Cuántos usuarios y sucursales a la vez.
- Nombre real del vendedor `01` y de la provincia `30` que se agregaron en la migración.
- Hosting/dominio de la instalación de OYS (`facturador.oys.com.ar`, base propia) y desde qué rama despliega Ferozo.
