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
| 4 | Cta. cte. y cheques (recibos, imputación, ajustes, cartera) | **Hecha: versión 0.10.0** (nota `docs/entregas/0.10.0-etapa4-cuenta-corriente.md`); falta la prueba de Hernán, confirmar D-28…D-32 y que los recibos viajen al VFP |
| 5 | Listados (subdiario, cobranzas, deuda, Libro IVA digital) a Excel | **Casi hecha**: subdiario de IVA ventas en pantalla/Excel (0.4.2) e **impreso para el libro** (0.15.0), cobranzas/deuda/cheques (0.10.0), **Libro IVA Digital ventas (0.11.0)**; falta probar el libro en el Portal IVA |
| 6 | Pase a producción OYS: histórico completo, control de saldos contra VFP, capacitación | Pendiente |
| 7 | Producto: alta de instalaciones, licencia de suscripción, actualización masiva | **En curso (0.29.0)**: oys.orgysis.site = sitio master; Instalaciones de clientes (alta, cliente en el master para facturar, suscripción aviso/sin emitir/bloqueada, baja con copia, módulos por cliente, dos carpetas main/estable con «Pasar a clientes»); falta importador de Excel y renovación automática de licencias |

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

## Estado actual (17/11/2026, versión 0.22.0) y cómo se trabaja

- **Ya facturando en ARCA producción** (Hernán emitió factura y nota de crédito): alias OYS (CUIT 20-11499319-1), certificado propio de la web, **punto de venta 7**
  (el 4 es del VFP: no se toca). Config de ARCA fuera de `public_html` (`afip_oys/` en el hosting). Modo en `config/local.php` (`afip.modo`).
- **Hosting de prueba**: DonWeb (Ferozo) con GIT, **sin SSH**; repo en `public_html/facturador_web/`, subdominio `facturador.oesteparts.site`
  apuntando a `public/`. Se actualiza con GIT → botón `>_` y luego **Sistema → Aplicar cambios**. No activar el webhook de auto-despliegue.
- **Rama `main` + pull requests** (igual que oesteparts): cada cambio en una rama `claude/…`, PR a `main` y fusión. Versión en `VERSION` (0.4.0 etapa 3, 0.4.1
  códigos de artículo/buscador/texto por renglón, 0.4.2 subdiario de IVA ventas, 0.5.0 stock, 0.6.0 agente VFP→web, 0.6.1 buscadores en stock, 0.7.0 el agente sube clientes y artículos, 0.7.1 ventana «Lista» para buscar artículos, 0.7.2 lupa y lista de clientes, 0.8.0 web→VFP, 0.9.0 alta de clientes y licencia, 0.10.0 cuenta corriente y recibos, 0.11.0 Libro IVA Digital, 0.11.1 arreglo de GIT en Ferozo, 0.12.0 logo en los comprobantes,
  0.12.1 scripts de `bin/` congelados, 0.13.0 portal de clientes, 0.14.0/0.14.1 grillas con orden, Excel y alto de pantalla, 0.15.0 subdiario impreso para el libro, 0.15.1 imputador en dos paneles, 0.15.2 web a pantalla completa, 0.16.0 presupuesto y ventas diarias, 0.17.0 pago al final y vuelto, 0.18.0 caja diaria, 0.19.0 stock en los buscadores, 0.20.0 ticket de cambio, 0.21.0 varias formas de pago y presupuestos sin IVA, 0.22.0 modo Kiosco, 0.23.0 reservas de stock con autorización y foto del producto, 0.23.1 «Dev Senior» en lo visible, 0.24.0 impresión A4/ticket, 0.25.0 caja, 0.26.0 kiosco: orden, precio y venta sin stock, 0.27.0 Mercado Pago).
- **Scripts congelados**: no tocar `bin/package.sh`, `bin/test.sh`, `bin/setup-dev.sh`, `bin/dev-server.sh` ni `bin/env.sh` (si cambian, el GIT de Ferozo no
  actualiza). Los vivos son `bin/pruebas.sh` (pruebas) y `bin/empaquetar.sh` (paquete).
- **Hecho más allá de las etapas**: artículos con EAN-13/DUN-14/SKU/unidades por bulto; buscador de artículos en renglones y stock; texto adicional por renglón;
  Listados → Subdiario IVA ventas (pantalla y Excel); **Stock** (movimientos + saldos en la misma transacción, varios depósitos, bultos, se factura sin stock,
  `stock.controlar` apagado por omisión); **Agente** (`agente-windows/`, PowerShell, sube comprobantes del VFP a la web; API con claves en Sistema → Agente).
- **Decisiones de Claude a confirmar**: `docs/decisiones-pendientes.md` (D-01…D-36; D-01, D-02, D-11 son interruptores de Configuración).
- **Web → VFP (0.8.0, hecho pero sin probar en el VFP real)**: lo emitido en la web con validez fiscal + el stock quedan en cola (`subdiario.vfp_enviado/vfp_error`); el agente baja un lote a `intercambio\entrada` (`GET /api/v1/vfp/pendientes`), el programa `vfp-importador/IMPORTAR_WEB.PRG` lo graba dentro del VFP (SUBDIARIO, IVA, ESTADISTICA, CAJA, CTACT, STOCK, MVSTK_*) y deja `salida\resultado_*.txt` que el agente devuelve (`POST /api/v1/vfp/confirmar`). Formato en `Agente/ExportadorVfp`. El VFP de OYS tiene las tablas de stock (hoy vacías) y los tipos BS/AS. Decisiones D-22…D-25. **Pendiente: que Hernán pruebe el `.prg` en una copia de los datos (con `DO IMPORTAR_WEB WITH .T.`) y ajustarlo.** Los datos reales de OYS se abren en el scratchpad (RAR de Drive por `download_file_content`, que guarda el resultado en un archivo; `unrar`/`unrar-free` hay que instalarlo con apt).
- **Altas de clientes nuevos (0.9.0)**: manual `docs/alta-de-cliente.md`, lecciones `docs/lecciones-alta.md` (**leerlas antes de un alta**), instalaciones `docs/instalaciones.md`, plantillas `docs/clientes/`. `bin/alta-cliente.php` prepara `config/local.php` + `PASOS.md` + `ficha.md` (fuera del repo; las fichas con datos del cliente van a Drive). Suscripción mensual: licencia firmada Ed25519 (`bin/licencia.php clave|emitir|verificar`, `Licencia/Licencia`, Sistema → Licencia, `'licencia' => ['exigir' => true]` en `config/local.php`); la clave privada es de Hernán y nunca va al repo ni al chat; falta que genere el par y suba `config/licencia_publica.txt`. **Después de cada alta: completar la ficha y pasar lo generalizable a `docs/lecciones-alta.md`.** Cliente 2: VFP de O&S, 1 CUIT, 1 PV, mismo hosting, mensual.
- **Cuenta corriente y recibos (0.10.0)**: módulo `Modules/CuentaCorriente` (`CuentaCorrienteService`: recibos, imputación manual, ajustes AD/AC, anulación, cheques, deuda). tico de `ctact`: 1 FC, 2 ND, 3 AD | 7 RC, 8 NC, 9 AC; débito = tico < 7. Tablas `cobra` e `imputaciones` (nombres VFP) + `cobra_id` en `ctact`/`caja`. Permisos `ctacte.ver/cobrar/imputar/ajustar` (Operador: ver y cobrar). Buscador de clientes reutilizable: `public/assets/cliente-picker.js` + `views/ctacte/_picker.php`. El migrador lee `cobra` e `imputaciones`. Retenciones = tabla `retenciones` (Tablas → Retenciones, con provincia) + `cobra_retenciones` por recibo (decisión de Hernán 14/11); NC se aplica con imputación manual y la anulación está aprobada. Decisiones D-28…D-32. **Pendiente: que los recibos viajen al VFP por el agente.**
- **Libro IVA Digital (0.11.0)**: `Listados/LibroIvaDigital` genera `LIBRO_IVA_DIGITAL_VENTAS_CBTE` (266) y `_ALICUOTAS` (62) por mes, solo comprobantes con CAE y no de prueba; pantalla Listados → Libro IVA Digital (`/listados/libro-iva-digital`), controles previos y ZIP. Probado con los 3.371 de OYS (3.369 informables; 6 con datos viejos malos). D-33…D-35. **Falta la primera importación real en el Portal IVA.** Sin compras ni anulados.
- **Pendiente**: carga masiva del stock inicial; probar y afinar web→VFP; recibos al VFP;
  etapas 6 y 7; en el portal: recibos, resumen de cuenta y aviso por mail de comprobante nuevo; pantalla de equivalencias de condición de IVA; probar el agente en la PC con Windows.
- **Cuidados del contenedor**: MySQL local se cae al reiniciar el contenedor (`bash bin/setup-dev.sh`; si no arranca, borrar `/var/run/mysqld/*.lock` y `/var/lib/mysql/vm.pid`).
  Las claves, certificados y datos reales de clientes nunca van al repo ni al chat.

## Presupuesto: vender sin factura de ARCA (pedido de Hernán, 17/11/2026, versión 0.22.0)

**El presupuesto es la opción para poder vender algo sin hacer una factura en ARCA.** Reglas (decisiones D-37…D-42, nota `docs/entregas/0.16.0-presupuesto.md`):
- Tipo `PR` (Presupuesto), **letra X**, no fiscal, numeración propia por sucursal (`sucursales.ultimo_presupuesto`), precios con IVA incluido. Vive en `subdiario` con
  `es_prueba = 0` y se deja afuera **por tipo** (`Tipos::INTERNOS`, `Tipos::sqlSoloFiscales`) del subdiario de IVA, del Libro IVA Digital, del portal y del lote al VFP.
- **Actúa como una factura**: se cobra (caja) o va a cuenta corriente (`ctact.tico` 4, débito: se cobra con recibo, se imputa, sale en el extracto), **descuenta stock**
  (si `stock.controlar` está activo), sale en PDF/impresión («Documento no válido como factura»), mail y WhatsApp (`wa.me` + «Compartir el PDF»; `compartir.js`).
- **Genera UNA factura** (`subdiario.presupuesto_id` único; `/comprobantes/{id}/facturar`): mismos renglones/cliente/descuento, letra del cliente (A: precio con IVA → neto),
  CAE normal; **solo va al subdiario** (`solo_subdiario = 1`: sin caja, sin cuenta corriente, sin stock). Sus NC/ND también. No se factura un presupuesto con contrapartida.
- **Contrapartida**: tipo `NX` («Nota de crédito X», `tico` 10): repone stock, saldo a favor, no supera el presupuesto, corrige solo presupuestos.
- **Listados → Ventas diarias** (`Listados/VentasDiarias`): PR y FC mezclados, solo los que se cobraron o fueron a cuenta corriente (`solo_subdiario = 0`, así la factura de un
  presupuesto no se repite); además NC/NX restando (D-40, a confirmar).
- Falta: que el presupuesto viaje al VFP (D-41) y permisos propios (hoy `comprobantes.emitir`).

## Pedido de mostrador (Hernán, 18/11/2026) — hecho en entregas (completo)

1. **Pago al final + vuelto + Tarjeta/Billetera** — HECHA en 0.17.0 (D-43, D-44): la forma de pago se pide en la pantalla de revisión (con el total a la vista) para facturas, ND y presupuestos;
   `forma_de_pagos.acepta_vuelto` (ABM en Tablas → Formas de pago), `caja.p_recibido/p_vuelto`, tipo de pago 10 = Billetera virtual.
2. **Caja diaria** — HECHA en 0.18.0 (D-45): Listados → Caja diaria (`Listados/CajaDiaria`): cobrado por forma de pago (efectivo recibido/vuelto/neto, tarjeta, billeteras, cheques), vendido, facturado en cuenta corriente y movimientos. Sin arqueo por turno.
3. **Stock por depósito** en los buscadores de artículos — HECHA en 0.19.0 (D-46): sugerencias con el stock de cada depósito y ventana «ver lista» con una columna por depósito (`BuscadorArticulos::stockDe`, `stock-articulo.js`).
4. **Ticket de cambio** — HECHA en 0.20.0 (D-47; sale de presupuestos y de facturas): `parametros.ticket_cambio` («habilita modo ticket de cambio», Tablas → Parámetros); ticket PDF sin importes con código/QR `PR-X-0004-00000012` (`Cambios/TicketCambioPdf`);
   Facturación → Cambio de mercadería (`/cambios`, permiso `cambios.hacer`): se lee el código o el número, se elige qué devuelve y qué se lleva y **solo se cobra la diferencia**. El cambio es un comprobante interno `CM` (letra X, `ctact.tico` 5, `subdiario.cambio_de`, `estadistica.linea_origen`): devuelve stock, saca stock, no es fiscal.
5. **Modo Kiosco** — HECHA en 0.22.0 (D-50): `parametros.kiosco` («Habilitar modo Kiosco»), permiso `kiosco.usar` por rol (Roles → Kiosco), `/kiosco` con tarjetas de productos (stock por depósito, rubros, pistola) y carrito siempre visible (localStorage); «Cobrar» arma un presupuesto (`origen = kiosco`) y sigue el flujo de «Cómo se paga» del presupuesto; `/kiosco/emitir` solo emite presupuestos del kiosco. **Con esto el pedido de mostrador está completo.**

## Apariencia por cliente (Hernán, 21/11/2026, versión 0.31.0)

- Pedido: que cada cliente personalice su cuenta y su logo se vea en todo el sistema. **Decisión de Hernán: la marca del cliente arriba (logo, nombre, color) y «FacturaWeb · Organización & Sistemas» al pie.**
- Hecho en 0.31.0: Sistema → Configuración → Apariencia (`Configuracion/Apariencia`), migración 0031. Sin ramas ni copias: todo en la base de cada instalación. Pendiente: verla o fijarla desde el panel del master.
- Numeración coordinada entre hilos del 21/11: 0.30.0 actualización automática de base (0030), 0.31.0 apariencia (0031), 0.32.0 importadores Excel/CSV/TXT (0032).

## Pedido de Hernán del 19/11 (orden: 0.23.1 renombre → 0.24.0 ticket/A4 → 0.25.0 caja → 0.26.0 kiosco orden y precio → 0.27.0 Mercado Pago → 0.28.0 padrón IIBB → 0.29.0 alta por CUIT)

- Respuestas de Hernán: renombre solo visible (HECHO 0.23.1); precio en kiosco con privilegio; ticket con QR+CAE solo si es ARCA, internos «no válido como factura»; **caja**: permiso por rol para movimientos manuales (por omisión Master y Administrador), **tope por omisión de $ 10.000.000 en Parámetros**, arqueos por turno y cambio de cajero; **Mercado Pago**: cuenta del cliente por instalación, QR y link de pago, y **transferencia** como forma de pago tipo billetera que diga «transferencia»; **padrón IIBB**: ARBA y AGIP, aplica percepciones automáticamente si están cargadas, con una pregunta en Parámetros «¿percibe?» (el cliente puede dejar de ser agente aunque tenga datos cargados).
- **ARCA ya factura en real** (aviso de Hernán 19/11; el QR de ARCA verificado OK). Falta probar el ticket 80/50 con la impresora térmica y el Libro IVA Digital en el Portal IVA a fin de mes.
- 0.27.0 HECHA (D-57, D-58): `Modules/MercadoPago` (adaptador `MercadoPago` con modos apagado/simulado/produccion, `MercadoPagoService`: el cobro queda pendiente y la venta se emite recién al acreditarse; webhook firmado `/api/v1/mercadopago/webhook`), formas de pago `MP` y `TR` (tipo 10), permisos `mercadopago.cobrar/administrar`, credenciales solo en `config/local.php`. **Sin probar con cuenta real.** Siguen: 0.28.0 padrón IIBB (ARBA y AGIP, aplica percepciones si «percibe» en Parámetros), 0.29.0 alta por CUIT.
- 0.26.0 HECHA (D-55, D-56): `articulos.orden_kiosco`, permisos `kiosco.precio` / `kiosco.sin_stock` / `stock.vender_sin_stock` (`ReservasService::autorizaPorPermiso`), `KioscoService::fijarPrecios` (sin permiso, precio de lista siempre), informe Stock → Ventas sin stock. 
- 0.25.0 HECHA (D-54): `Modules/Caja` (`CajaService`: turnos con arqueo, movimientos manuales con tope `parametros.caja_tope`, anulación por contra-asiento, `caja_auditoria` con hash encadenado), permisos `caja.turno`/`caja.mover`/`caja.auditar`, `caja.usuario_id/creado_en/turno_id` en cada cobro (`CajaService::contexto()`).
- 0.24.0 HECHA (D-53): `parametros.impresion` a4|t80|t50, `imprimir_ticket.php`, `ComprobanteTicketPdf`; el PDF enviado sigue en A4.

## Reservas de stock y fotos (Hernán, 18/11/2026, versión 0.23.0)

- **D-51**: `Stock/ReservasService`: reservas blandas por «venta en curso» (token de 32 hex en `reserva`; `stock_reservas`, vencen a `stock.reserva_minutos` = 15, `reservas.js` las sincroniza y renueva). `disponible` = saldo del depósito principal − reservas de otros. Al emitir (`armar`/`grabar`) los faltantes piden autorización (`autoriza_yo` con permiso `stock.autorizar`, o supervisor usuario+clave) y se registran en `stock_autorizaciones`; el control definitivo es con filas bloqueadas dentro de la transacción (`StockNoDisponible`). Con CAE ya dado no se aborta. Solo FC/PR con stock controlado; no NC/ND ni CM.
- **D-52**: `Articulos/Foto`: una foto por artículo en `articulos_fotos` (grande 1600 / chica 360, GD), ruta `/articulos/foto?codigo=&t=g|c`, permiso `articulos.editar` para subir/quitar. Visor con zoom en `app.js` (`window.ampliarFoto`, `a[data-ampliar]`, `[data-ampliar-src]`).
- Estado: hecha en 0.23.0, pendiente de que Hernán confirme D-51 y D-52.

## Varias formas de pago y presupuestos sin IVA (Hernán, 18/11/2026, versión 0.22.0)

- **Varias formas de pago** (D-49, reemplaza D-04): «Cómo se paga» admite hasta 8 líneas (forma, importe, recibido, cheque) que suman el total; una línea puede ser cuenta corriente (queda como deuda; el crédito se controla sobre esa parte);
  una fila de `caja` por línea; vuelto por línea. `ComprobantesService::resolverPagos` (también lo usa el cambio de mercadería), `views/comprobantes/_pago.php`, `pago.js`. `subdiario.forma_pago` queda 2 si algo quedó a cuenta, y
  `forma_pago_texto()` arma «Mixto: …». Ventas diarias y Caja diaria separan contado y cuenta corriente por las filas de caja.
- **«Presupuestos sin IVA»** (D-48): marca en Configuración (`presupuestos.sin_iva`); con la marca el presupuesto no calcula IVA (precio final, como una C), `subdiario.sin_iva` guarda cómo se hizo, la NX hereda, y la factura que sale de él sí calcula IVA.

## Regla de grillas (Hernán, 17/11/2026)

Todas las grillas: **orden por columna**, botón **Excel** (todo lo filtrado) y que **entren en la pantalla** con scroll propio vertical y a la derecha
(0.14.0); también las grillas con campos para completar, como «Qué se cancela» del recibo (0.14.1).
- Con páginas: orden en el servidor (`Core\Orden::sql()` con un mapa clave => SQL fijo + `th_orden()` en la vista; el filtro lleva `orden`). Sin páginas: `public/assets/grilla.js`.
- Excel genérico: `?excel=grilla&tabla=N` → `App::handle` marca `Core\GrillaExcel::$pedido`, `Configuracion::porPagina()` devuelve el máximo y la tabla del HTML
  sale en .xlsx (importes como número; códigos/CUIT/números de comprobante como texto; sin columnas de botones ni columnas de campos vacías).
- Solo los renglones del comprobante (se agregan y se quitan) llevan `data-grilla="no"`. En celular siguen las tarjetas.

## Subdiario impreso para el libro (0.15.0)

Listados → Subdiario IVA ventas → **Imprimir para el libro** (`/listados/iva-ventas/pdf`, `Listados\SubdiarioPdf` + `IvaVentas::libro()`): A4 apaisado
(`new Pdf(true)`), datos de la empresa, período y número de hoja (hoja inicial elegible) en cada hoja; una fila por comprobante con neto e IVA por
alícuota, exento, «otros» (solo si hay diferencias) y total; transporte entre hojas; totales y resumen por alícuota. En los datos del VFP la columna
«Otros» marca algunas NC cuyo IVA quedó vinculado por el neto: revisarlas con el contador.

## Portal de clientes (0.13.0)

`/portal`: el cliente entra con un link que le llega al mail (sin contraseña, 30 min, una vez) y baja sus comprobantes y ve su saldo. Se prende en
Configuración (`portal.activo`) y necesita el mail de salida. Código: `Modules/Portal`, vistas `views/portal` (layout propio, sin el menú del sistema).
Entran los mails de «Mails para las facturas» y «Mails para listados»; solo comprobantes vigentes y fiscales (D-36); tabla `portal_accesos` (hash del link).
El mail de cada factura agrega la dirección del portal cuando está activo.

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

## Listados de estadística, stock y costos (0.32.0, 21/11/2026)
Hernán aprobó la propuesta (estadística con ranking, rentabilidad, costos y márgenes, movimientos de stock con trazabilidad, existencias a una fecha) y las decisiones D-62 a D-65:
el costo se graba al vender (`estadistica.costo`), permiso nuevo `listados.costos` (Administrador y Master), presupuestos incluidos con filtro «Solo fiscales».
Quedan para después: historial de cambios de costo (tabla nueva) y comparativo contra el año anterior.

## Tablero de inicio con gráficos (0.40.0, 21/11/2026)
Hernán aprobó la maqueta (https://claude.ai/artifact/6Qdh1shkrW2owbXnS47vcn) y pidió fusionar al terminar. El inicio muestra la venta del período (hoy, mes, mes pasado, 12 meses; por sucursal)
contra el período anterior, tarjetas (hoy, ticket promedio, margen, cobrado, deuda, turno), venta acumulada del mes contra el anterior, venta por mes, lo más vendido, mejores clientes,
cómo te pagaron, avisos para atender y accesos rápidos; abajo, «Todo el menú». Decisiones D-90 a D-92 a confirmar. Compras suma su propia tarjeta con `Tablero::EXTRAS`.

