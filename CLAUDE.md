# FacturaWeb — guía de desarrollo (para Claude)

Contexto, decisiones y estado del proyecto: skill `facturaweb-proyecto` (copia en `.claude/skills/facturaweb-proyecto/SKILL.md`)
y `docs/analisis-migracion.md`. Esto es solo lo técnico del repositorio.

> **No modificar ningún `.sh` de `bin/`** (`package.sh`, `test.sh`, `setup-dev.sh`, `dev-server.sh`, `env.sh`, `pruebas.sh`, `empaquetar.sh`) **ni agregar uno nuevo.**
> El hosting (Ferozo, sin SSH) les cambia los permisos y GIT se niega a sincronizar si una versión nueva los toca («local changes would be overwritten»).
> Pasó con 0.11.1 y otra vez el 21/11/2026 (sumar tres renglones a `pruebas.sh` dejó el servidor trabado en 0.32; arreglado en 0.44.1).
> `tests/ScriptsCongeladosTest.php` lo controla con el hash de cada uno. Un recorrido de navegador nuevo se suma en la lista de `e2e/extras.js`
> (lo llama `e2e/cuenta-corriente.js` al final); cualquier otra herramienta va en PHP o node, en un archivo nuevo.

## Al empezar una sesión

1. `bash bin/setup-dev.sh`: instala MySQL 8 si falta, crea `facturaweb_dev` y `facturaweb_test` (usuario `facturaweb` / `facturaweb_dev`) y migra. Idempotente.
   Si MySQL no está corriendo (el contenedor se reinició), el mismo script lo levanta.
2. `bash bin/pruebas.sh` tiene que pasar **antes** de tocar nada (`--unit` solo PHP). El recorrido de navegador usa el Playwright preinstalado.
3. `bin/dev-server.sh` → http://127.0.0.1:8080. Usuario: `php bin/crear-master.php`.
4. Datos reales de OYS para probar la migración: en Drive (`datos_facturador_web….rar`), **nunca al repo** (`.gitignore` bloquea `*.dbf`).
   Se descomprimen con `unrar` en el scratchpad y se cargan con `php bin/migrar-dbf.php <carpeta> --reemplazar`.

## Base técnica

Núcleo copiado de `hernancespedes-droid/oesteparts` (App, Core, Http, Auth, Usuarios, Roles, Sistema, layout, corredor de pruebas)
y adaptado a **una instalación por cliente**: sin tabla `empresas`, cada usuario tiene un rol (`usuarios.rol_id`).
Sin Composer (Packagist bloqueado): autoloader propio, pruebas propias (`tests/run.php`), nada de librerías de terceros.

- **PHP 8.2 como piso**: nada exclusivo de 8.3/8.4. **MySQL 8**, InnoDB, `utf8mb4_unicode_ci` en cada tabla
  (códigos O&S con `utf8mb4_bin`: en el VFP conviven 'SE' y 'SÑ').
- **Esquema O&S**: tablas y columnas con los nombres largos de `datos.dbc` del VFP (`clientes.razon_social`, `sucursales.ultima_factura_a`…),
  para que la migración y `lib/afip_fe` (adaptador `OysSubdiario`) trabajen casi 1 a 1. Lo que agrega la web se documenta en la migración.
- `parametros` = datos de la empresa (tabla O&S, fila id=1). La configuración del sistema web es la tabla `configuracion`.

## Facturación (etapas 2 y 3)

- `Modules/Comprobantes`: `Calculadora` (lógica pura, las reglas de importes), `Tipos` (letra, códigos ARCA, numeradores), `ComprobantesService`
  (`armar()` valida y calcula; `emitir()` numera con `FOR UPDATE` en `sucursales` y graba todo en una transacción; clave de operación contra el doble envío),
  `ComprobanteImpreso` (datos para imprimir) + `ComprobantePdf` y `Core\Pdf` (PDF sin librerías) + `Core\Mailer` (SMTP sin librerías).
- **El número interno no es único en los datos del VFP**: la clave es tipo + letra + sucursal + número + **fecha** y las tablas hijas (`iva`, `estadistica`,
  `caja`, `ctact`) apuntan con `subdiario_id`. Mostrar siempre `numero_visible()` (el de ARCA si tiene CAE). Ver `docs/migracion-dbf.md`.
- Convención de renglones: `precio` y `total_item` son lo que se ve (A neto; B y C con IVA); `precio_siniva` y `total_siniva` netos y con el descuento. El
  IVA oficial está en `iva`, no se recalcula desde los renglones.
- **ARCA (etapa 3)**: `Modules/Arca/Arca` es el único que toca `lib/afip_fe`; modo en `config/local.php` (`afip.modo` interno|simulado|homologacion|produccion).
  `ComprobantesService::emitirConArca` registra en `afip_operaciones` antes de pedir el CAE y `recuperar()` graba lo aprobado y no guardado. `ArcaSimulador`
  contesta WSAA/WSFEv1 sin internet (CAE `99…`, rechaza totales ,13). Lo no fiscal queda `es_prueba = 1`. Las decisiones de Claude llevan `DECISIÓN D-xx` y
  están en `docs/decisiones-pendientes.md`: al decidir algo de negocio sin consultar, marcarla así y sumarla ahí.
- Los campos de importe usan `data-decimales` (la máscara de `app.js` pone puntos de miles: `Valida::decimal` y `comprobante.js` los leen igual).

## Cuenta corriente y recibos (etapa 4)

- `Modules/CuentaCorriente/CuentaCorrienteService`: recibos (`armarRecibo` valida y calcula; `emitirRecibo` numera con `sucursales.ultimo_recibo` bloqueada, bloquea las filas de `ctact` y revalida el saldo adentro), `imputarManual`, `ajuste` (AD/AC), `anularRecibo`, cheques y deuda.
- `ctact.tico`: débitos < 7 (FC 1, ND 2, AD 3), créditos ≥ 7 (RC 7, NC 8, AC 9). `ctact.saldo` es lo que falta cancelar; el recibo deja su anticipo como saldo propio. Cada cancelación es una fila de `imputaciones` (`ctact_db` / `ctact_cr`).
- Pantallas con cliente: `views/ctacte/_picker.php` + `public/assets/cliente-picker.js` (buscador + lupa, el usuario siempre elige).

## Presupuesto (0.16.0)

`PR` (Presupuesto) y `NX` (su contrapartida) son comprobantes **internos** (letra X, `Tipos::INTERNOS`): actúan como factura / nota de crédito (stock, caja, `ctact` tico 4 y 10)
pero **no van al subdiario de IVA, al Libro IVA Digital, al portal ni al VFP**: todo lo fiscal que lee `subdiario` tiene que filtrar con `Tipos::sqlSoloFiscales()` (o `tipo NOT IN ('PR','NX')`).
Un presupuesto genera **una sola** factura (`presupuesto_id` único) que va **solo al subdiario** (`solo_subdiario = 1`: sin caja, `ctact` ni stock). Ventas diarias: `Listados/VentasDiarias`. Detalle en el skill.

## Cambio de mercadería (0.20.0)

`CM` (Cambio, letra X) es otro comprobante **interno** (`Tipos::INTERNOS`, `Tipos::SQL_INTERNOS`): `Cambios/CambiosService` lo arma desde un presupuesto o una factura (ticket de cambio sin importes, `TicketCambioPdf`),
devuelve y saca stock y cobra solo la diferencia (`ctact.tico` 5). Se enciende en Parámetros (`parametros.ticket_cambio`) y se habilita por rol con `cambios.hacer`. Todo lo fiscal que lee `subdiario` sigue filtrando con `Tipos::sqlSoloFiscales()`.

## Modo Kiosco (0.22.0)

`Modules/Kiosco`: `/kiosco` (tarjetas + carrito en `public/assets/kiosco.js`) → `POST /kiosco/cobrar` arma un presupuesto (`datos.origen = 'kiosco'`) y reutiliza `ComprobantesController::pantallaRevisar` («Cómo se paga») → `POST /kiosco/emitir`, que solo acepta presupuestos del kiosco.
Se enciende en Parámetros (`parametros.kiosco`) y se da por rol con `kiosco.usar`.

## Reservas de stock y fotos (0.23.0)

`Stock/ReservasService`: reservas blandas por venta en curso (token `reserva`, 15 min, `reservas.js`), `disponible` en `BuscadorArticulos`; faltantes piden autorización (`stock.autorizar`, o supervisor con clave) en `armar` y se re-controlan con filas bloqueadas en `grabar` (`StockNoDisponible`); se registran en `stock_autorizaciones`. Fotos de artículos en `articulos_fotos` (`Articulos/Foto`, `/articulos/foto`), visor con zoom en `app.js`.

## Impresión A4 / ticket (0.24.0)

`parametros.impresion` (`a4`|`t80`|`t50`): `ImpresionController::imprimir` elige `views/comprobantes/imprimir.php` (A4) o `imprimir_ticket.php`; `?papel=` lo cambia. El PDF (`pdf()`, mail) sigue en A4; `ComprobanteTicketPdf` (con `?papel=t80|t50`) hace el PDF angosto. El vuelto se lee de `caja` (`ComprobanteImpreso::datos()['pagos']`).

## Vuelto interactivo (0.50.0)

`public/assets/vuelto.js` (`Vuelto.crear(campoRecibido, lugar)`) dibuja la calculadora de vuelto junto a «Recibido» de toda forma que acepta vuelto; la usan `pago.js` (`_pago.php`: factura, kiosco, cambio) y `recibo.js` (los recibos graban `p_recibido`/`p_vuelto`). Con plata de menos no se envía el formulario.
Después de cobrar, `Session::flash('vuelto', importe)` muestra el cartel grande del layout (`[data-vuelto-final]`, lo cierra `app.js`). D-134.
**Cobrar venta (0.55.0, D-137):** al contado «Emitir» abre la ventana `dialog[data-cobro]` de `_pago.php` (`pago.js`: medios `pagos[n][fpago|importe]` mezclados, resumen total/pagado/vuelto,
billetes con `vuelto.js`). El vuelto es informativo: `resolverPagos` acepta pagar de más (solo frena lo de menos) y deja en caja el total, descontando lo de más de cuenta corriente → efectivo → el resto.
En e2e, emitir al contado = clic en «Emitir» + `[data-confirmar-cobro]` (helper `emitir(page)` de `recorrido.js`).
**Importes (0.57.0, D-140):** la máscara de miles de `app.js` escucha `input` en captura (corre antes que cualquier manejador de pantalla) y `Vuelto.num` descarta todo punto (miles) y toma la coma como decimal. Un manejador nuevo de importes tiene que leer con `Vuelto.num` o `Valida::decimal`.

## Caja (0.25.0)

`Modules/Caja/CajaService`: turnos con arqueo (`caja_turnos`), ingresos/egresos manuales (`caja_movimientos`, tope `parametros.caja_tope`, se anulan con contra-asiento `anula_id`, nunca se editan) y `caja_auditoria` con hash encadenado (`verificar()`). Todo cobro nuevo en `caja` lleva `usuario_id`, `creado_en` y `turno_id` con `CajaService::contexto()` (hay que sumarlo en cualquier INSERT nuevo en `caja`). Permisos `caja.turno`, `caja.mover`, `caja.auditar`.

## Kiosco: orden, precio y venta sin stock (0.26.0)

`articulos.orden_kiosco` ordena las tarjetas (`BuscadorArticulos::listar(..., $kiosco = true)`). `KioscoService::fijarPrecios` impone el precio de lista a quien no tiene `kiosco.precio` (se aplica en `cobrar` y en `emitir`). `ReservasService::autorizaPorPermiso` salta la autorización de stock para roles con `kiosco.sin_stock` (solo PR del kiosco) o `stock.vender_sin_stock`; `ComprobantesService::armar` lo guarda en `stock_automatico` y `grabar` lo registra con `automatica = 1` (informe `Stock/VentasSinStock`).

## Mercado Pago (0.27.0)

`Modules/MercadoPago/MercadoPago` es el único que habla con la API (modos `mp.modo` apagado|simulado|produccion en `config/local.php`; `MercadoPago::$transporte` permite probar sin red). `MercadoPagoService`: «Cobrar con Mercado Pago» deja la venta en un cobro `pendiente` (tabla `mp_cobros`, sin claves) y **solo se emite al acreditarse el pago** (`confirmar` → `emitir`, idempotente con el token como clave de operación; `CajaService::$actor` pone al cajero cuando no hay sesión). La notificación va por `/api/v1/mercadopago/webhook` (sin sesión ni CSRF) con firma HMAC verificada y el pago se vuelve a consultar en Mercado Pago. `ComprobantesService::armar` acepta `$autorizoPrevio` (solo desde el código) para la autorización de stock ya resuelta.

## Ingresos de Mercado Pago (0.41.0)

`MercadoPago/IngresosService`: todo pago aprobado que entra a la cuenta (sea o no una venta de la web) va a `mp_ingresos` una sola vez (`mp_pago_id` único). Llega por la notificación (`notificacion` llama a `registrar`
antes de `confirmar`) y por `consultarSiToca()` (una por minuto, turno tomado con UPDATE condicionado sobre `interno.mp_ingresos_consulta`; la disparan `/mercadopago/ingresos/novedades` y `bin/mp-ingresos.php`).
Campanita y carteles: `views/layout.php` + `public/assets/mp-ingresos.js` (con `mercadopago.ingresos`); vistos por usuario en `mp_ingresos_vistos`. Mail opcional a `configuracion.mercadopago.ingresos_mails`.

## Alta de clientes desde el CUIT (0.37.0)

`Arca/Padron` consulta la constancia de inscripción (`ws_sr_constancia_inscripcion`, `getPersona_v2`, mismo certificado y `Arca::afip()->wsaa`) y la normaliza (condición de IVA → `clientes.iva`, D-70); guarda cada respuesta en `padron_consultas` (caché `afip.padron_dias`, 30). `ArcaSimulador::padron` contesta sin internet (ver los CUIT de ejemplo en su docblock; `Padron::$transporte` para pruebas).
`Clientes/AltaCliente`: `propuesta()` (CUIT → existentes + datos del formulario con la provincia de la tabla + letra) y `crear()` (alta rápida, solo `CAMPOS_RAPIDOS`). Rutas `GET /clientes/padron` y `POST /clientes/rapido` con `clientes.alta` o `clientes.editar`.
Pantalla con buscador de clientes que tenga que permitir el alta: `icono_nuevo_cliente()` junto a la lupa, `require views/clientes/_alta_rapida.php` y `AltaCliente.itemLista(q, elegir)` al final de la lista (ver `comprobante.js` y `kiosco.js`).

## Estadística, stock y costos (0.32.0)

`Listados/Estadistica` (agrupar por artículo, rubro, cliente, vendedor, mes…, ranking y detalle; `/listados/estadistica` y `/listados/rentabilidad`), `Listados/ListaCostos` (`/listados/costos`), `Stock/MovimientosStock` (trazabilidad con saldo corrido y resumen; `/stock/movimientos`) y `Stock/Existencias` (por depósito a una fecha; `/stock/existencias`).
**Todo INSERT nuevo en `estadistica` lleva `costo`** (unitario en pesos al emitir, `Listados\Costos::unitarios`, D-62). Costos, márgenes y valorizados solo con el permiso `listados.costos`. La estadística no cuenta la factura de un presupuesto (`solo_subdiario = 1`) y resta NC/NX (D-63).

## Precios con margen sobre el costo (0.49.0)

`Articulos/Precios`: margen % sobre el costo por lista (`margen_l1|l2|l3|oferta` en `articulos` → `rubros` → `parametros`, NULL = hereda; D-130), IVA si la empresa es RI y
`parametros.precios_con_iva` (D-131), redondeo hacia arriba `parametros.precio_redondeo` (D-132). `articulos.precio_automatico`: `ArticulosService::guardar` recalcula (D-133).
`Precios::aplicar` cambia costo/precios con la fila bloqueada y anota en `articulos_precios_historial` (usa la transacción de quien llama). **Todo cambio nuevo de `articulos.costo`
pasa por `Precios::aplicar` o `registrar`.** Para preguntar por los precios después de cambiar costos: `ArticulosController::preguntarPrecios($codigos, $motivo, $volver)` → `/articulos/precios?pendientes=1`.
Permiso `articulos.precios` (costo, márgenes, automático, `/articulos/precios`; el ingreso de stock con costo).

## Tablero de inicio (0.40.0)

`Modules/Tablero`: `Tablero::datos()` arma todo lo del inicio según permisos y módulos (D-90; «venta» = lo vendido de la caja diaria, D-91; avisos, D-92) y `Graficos` dibuja los SVG en el servidor
(colores por variables CSS: `--g-marca` = color del cliente legible, `--g-comp` gris de comparación, `--g-1..5` paleta fija validada para «Cómo te pagaron»). `public/assets/tablero.js` pone la guía y el cartel
(`data-tip` con renglones separados por «|»). Otro módulo suma su tarjeta con `Tablero::EXTRAS` (modulo, permiso, vista), sin tocar `home.php`.

## Compras y centros de costos (0.43.0)

`Modules/Compras` (módulo `compras` de `Core\Modulos`, apagado por omisión; permisos `compras.ver`, `compras.cargar`, `compras.pagar`, `compras.centros`): `CentrosService` (centros de dos niveles con «General»
que no se desactiva, D-110; presupuesto mensual `centros_presupuesto`; conceptos de gasto; plantillas de reparto), `ProveedoresService` (tabla `proveedores`, buscador con `cliente-picker.js` + lupa
`VentanaLista.proveedores`), `ComprasService` (`armar` valida y calcula: IVA por alícuota, percepciones, costo D-111, controles D-112, reparto entre centros que suma 100 %; `grabar` en una transacción
con adjuntos en la base; pagos: efectivo = `caja_movimientos` del turno, D-113, que `CajaService::anular` no deja anular) y `ComprasListados` (análisis, presupuesto contra gastado, a pagar por antigüedad,
subdiario de IVA compras, `tablero()` para `/compras` con gráficos SVG en `compras-graficos.js` y `resumenInicio()` para la tarjeta del inicio). **Los gastos suman `compras_reparto.importe`
(costo sin IVA computable ni percepciones) y las NC restan.** 
**Entrega B (0.46.0):** `OrdenesPagoService` (órdenes de pago: `armar` valida, `emitir` numera con `compras_numeradores` bloqueado, bloquea compras, anticipos y cheques y revalida;
medios E efectivo del turno, T, Q cheque de la cartera → `caja.cheque_estado = 'E'`, P cheque propio, R retención a mano, O; lo de más queda en `ordenes_pago.anticipo_saldo`, D-115..D-117;
`anular` devuelve saldos, cheques y efectivo; `cuentaCorriente` con saldo corrido), `OrdenPagoPdf` (PDF y mail), pantallas `/compras/pagos…` y `/compras/proveedores/cuenta`
(`ordenes-pago.js`). El saldo del proveedor resta `anticipo_saldo`. Compras con artículos (`compras_items`, D-118): mueven stock con origen `COMPRA`/`DEVCOMPRA`/`ANUCOMPRA` y actualizan
`articulos.costo`. Una compra en una orden vigente no se anula. 
**Entrega C (0.47.0):** `ComprobanteArca` (códigos de comprobante de ARCA ↔ tipo/letra, `desdeQr` lee el QR de la factura, `proveedorOAlta` con el padrón, `datosCompra` arma el
formulario «para revisar»; `ComprasController::form` es público para eso), `/compras/qr` (`compras-qr.js`, BarcodeDetector o pegar el enlace, D-123/D-124), `MisComprobantes`
(importa el Excel/CSV de «Mis Comprobantes Recibidos» a `compras_arca` y lo controla contra compras, D-125/D-126; `/compras/mis-comprobantes`, `/compras/desde-arca`),
`LibroIvaCompras` (RG 4597 compras: CBTE 325 y ALICUOTAS 84, reutiliza los formatos de `Listados\LibroIvaDigital`, D-127; se baja desde el subdiario de IVA compras) y
`ComprasListados::resultado` (`/compras/resultado`, D-128).

## Libro IVA Digital (ventas)

- `Modules/Listados/LibroIvaDigital::generar('AAAA-MM')` arma los dos archivos de ARCA (diseño de registros RG 4597, rev. 07/2025: CBTE = 266 caracteres, ALICUOTAS = 62; ANSI, CRLF, importes de 15 posiciones sin coma). Solo comprobantes con CAE y `es_prueba = 0`. Los comprobantes internos de la web nacen con `es_prueba = 1`: en las pruebas un comprobante se hace «fiscal» seteando `fe_ccae`, `fe_nrocbte` y `es_prueba = 0`.

## Varias instalaciones con el mismo código (0.28.0)

`Modules/Instancias`: Sistema → Instalaciones de clientes (solo Master y solo en la principal). Cada cliente tiene `storage/instancias/<dirección>/config.php` (+ logs, sesiones, licencia)
y su base; su subdominio apunta a la **misma** carpeta `public/`. `config/config.php` elige la configuración por `HTTP_HOST` (por consola: `FACTURAWEB_HOST=`) y, con clientes creados,
la principal solo responde en las direcciones de `storage/instancias/_principal.php` (otra dirección → «no habilitada»). **Todo archivo de datos va con `App::storage('…')`, nunca
`App::root() . '/storage/…'`** (si no, se mezclan los clientes). Pruebas: base extra `facturaweb_test_inst` (crearla con root; si falta, `InstanciasTest` saltea el alta).

**Sitio master y dos carpetas (0.29.0)**: oys.orgysis.site es el master (rama `main`); los clientes corren en otra carpeta con la rama `estable` (`panel.carpeta_clientes`).
**Todo cambio llega primero al master**; a los clientes solo con «Pasar a clientes» (`Instancias/Promocion`). Suscripción por cliente (`Instancias/Suscripcion`: aviso → sin emitir →
bloqueada, en `Licencia::exigirParaEmitir`, `Guard::auth` y `App::handle`). **Función nueva que no tienen que tener todos: declararla en `Core\Modulos::LISTA` con `por_omision => false`**
(menú + prefijos de rutas); el master siempre tiene todo. Nunca romper el formato del config de los clientes: la carpeta de clientes puede estar una versión atrás.

## Usuarios de los clientes desde el master (0.59.0)

`Instancias/UsuariosCliente`: Administrar → «Usuarios y contraseñas» (`/instancias/usuarios?host=`, Master en la principal; también desde el ticket en la bandeja) lista los usuarios de la base del cliente y
`resetear()` pone una temporal (`UsuariosService::claveTemporal`, `debe_cambiar_clave = 1`, desbloquea, borra `recordarme`, «Reactivarlo» opcional). Se muestra una vez y no se guarda;
registro en `instancias_resets` (base del master) y nota interna en el ticket elegido, sin la contraseña (D-142, D-143). Solo usa columnas de la 0001 en la base del cliente. e2e: `e2e/usuarios-clientes.js`
(fixture `tests/fixtures/e2e_instancia.php`, necesita `facturaweb_test_inst`).

## Apariencia por cliente (0.31.0)

`Configuracion/Apariencia`: nombre, color (`--brand`, `--brand-2`, `--on-brand` calculados con contraste) y logo de cada instalación en barra, ingreso, pestaña y portal
(claves `apariencia.*` en `configuracion`, fuera de la lista general; imágenes `logo_web`/`logo_pantalla`/`logo_icono` en `imagenes`, servidas por `/marca/logo` y `/marca/icono` sin sesión).
Menú horizontal o lateral (`apariencia.menu`, `Apariencia::menu()` → `<body class="menu-…">`, 0.52.0, D-136; estilos `body.menu-lateral` en `app.css`, plegado en `app.js`). Íconos del menú en `Core\Menu` (`SVG`, `ICONO`, `ICONO_GRUPO`; 0.54.0, D-138): **opción nueva del menú → sumarle ícono en `Menu::ICONO`** (si no, usa el de su grupo). Contraer/expandir el lateral: cookie `menu_contraido` → `body.menu-contraido` (0.56.0, D-139).
Colores nuevos en `app.css`: siempre con `var(--brand)` / `color-mix(...)` y `var(--on-brand)` para el texto sobre el color, nunca el azul fijo. La marca del cliente va arriba y O&S firma al pie.

## Importar desde Excel, CSV y TXT (0.38.0)

`Modules/Importacion` (Sistema → Importar datos, permiso `importar.datos` + el de cada destino en `Destinos::PERMISO`): `Lector` (xlsx con ZipArchive/XMLReader, CSV/TXT con separador y
codificación detectados; números del Excel con coma decimal), `Destinos` (campos por destino: clientes y artículos reutilizan `campos()` de sus servicios; sinónimos de títulos),
`Importador` (todo en una transacción con SAVEPOINT por fila; probar = rollback; todo o nada salvo «parcial»), `Modelos` (`importacion_modelos`: campo → título de columna).
Clientes/artículos: al actualizar solo cambian las columnas elegidas y con dato. Comprobantes históricos: `subdiario` canal `I` + `importacion_id`, `iva`, `ctact` solo con saldo, sin caja ni stock;
la clave para no duplicar es tipo + letra + punto de venta + número + fecha. Registro en `importaciones`. Campo nuevo en clientes/artículos: si debe importarse, sumarle sinónimos en `Destinos`.

## Tickets de soporte (0.44.0)

`Modules/Soporte`: los tickets viven **solo en la base del master** (`soporte_tickets`, `soporte_mensajes` con `interno`, `soporte_adjuntos`). `SoporteService` (lado master: crear, responder, bandeja,
avisos por mail a `configuracion.soporte.mail_aviso` y al `usuario_mail` del ticket; `$enviador` para pruebas), `SoporteApi` (`/api/v1/soporte/…`, cabeceras `X-FW-Instancia` + `X-FW-Clave`, solo en la principal)
y `SoporteCliente` (lo que usa la pantalla: directo en la principal, por HTTP en un cliente con `soporte.url`/`soporte.clave` de su config; `$transporte` para pruebas). `InstanciasService::asegurarSoporte()`
escribe la URL del master y la clave de cada cliente (al entrar al panel o a la bandeja). Cliente: Ayuda → Soporte (`soporte.usar`, módulo `soporte`); master: Sistema → Tickets de soporte (Master en la principal).

## Base que se actualiza sola (0.40.1)

`Core\AutoMigracion::asegurar()` corre al principio de `App::handle` (salvo `/salud` e `/instalar`): si la firma (versión + migraciones) no coincide con
`sistema_estado`, aplica lo pendiente con el candado `fw_migrar:<base>` (el mismo de «Aplicar cambios»); los demás pedidos ven «Actualizando» (503). Si falla, no bloquea: queda en
`App::storage('automigracion_error.json')`, el Master lo ve en Sistema y `/salud` da `migracion_error`. Por eso las migraciones **tienen que ser aditivas e idempotentes**: se aplican sin que nadie mire.

## Cargar sin saltos (0.51.0)

`public/assets/carga.js` (en `layout.php`, todas las pantallas): en un `form[method=post]` Enter pasa al campo siguiente y desde el último al botón principal (D-135); no envía.
Quedan con Enter normal: textarea, formularios de un campo, GET (filtros) y `data-enter="enviar"` (en el form o el campo, p. ej. el ingreso). Un manejador propio de Enter
(buscadores) tiene que llamar `preventDefault()`: `carga.js` escucha en `window` y respeta `defaultPrevented`. Al volver a la misma ruta después de enviar, restaura altura y foco
(o va al primer `.has-error` / `.field-error`) y muestra los `main > .alerta` flotando (`.avisos-flotantes`).

## Estructura y convenciones

- Módulos en `src/Modules/<Nombre>/` con `routes.php`; vistas en `views/<modulo>/`. Handlers `function (Request $req, array $params): Response`.
- Las 12 tablas del VFP se declaran en `src/Modules/Tablas/Definiciones.php` (campos, clave O&S, grilla, quién las usa) y tienen
  una sola pantalla genérica. Clientes y Artículos declaran sus campos igual (`campos()`); `App\Core\Form` los dibuja y
  `App\Core\Validador` los valida.
- Menú: `App\Core\Menu` (grupos Maestros, Facturación, Tablas, Sistema). Permiso por opción del VFP (`tablas.rubros`, `clientes.ver`/`clientes.editar`…).
- Permiso nuevo: insertarlo en `permisos` y darlo en la misma migración a los roles `WHERE es_admin = 1 OR es_master = 1`; si es pantalla, sumarlo a `RolesService::MENU` y a `Menu`.
- Cambios de base: solo migraciones nuevas `database/migrations/NNNN_*.sql`, idempotentes; nunca editar una ya entregada.
- Textos en español rioplatense. URLs con `url()`, texto con `e()`.

## Buscar artículos y clientes (regla de Hernán)

Siempre que una pantalla pida un artículo o un cliente, el campo es un **buscador** (código, código de barras, SKU, CUIT o nombre) que muestra las coincidencias
debajo y **el usuario elige siempre**; nunca se toma la primera. Reutilizar `Articulos\BuscadorArticulos` + `public/assets/buscador.js` (artículos) y
`Comprobantes → buscarCliente` (clientes). Cada buscador lleva además la lupa (`icono_lista()` + `public/assets/ventana-lista.js`) que abre la lista para elegir. Detalle en el skill `facturaweb-proyecto`.

## Grillas (regla de Hernán)

Toda grilla: **orden por columna** (con páginas, en el servidor con `Core\Orden::sql()` + `th_orden()`; sin páginas lo hace `grilla.js`), **Excel**
(genérico: `?excel=grilla&tabla=N` → `Core\GrillaExcel`, todo lo filtrado) y **alto de pantalla** con scroll propio (vertical y a la derecha). Vale también para las grillas con campos para completar
(cobrar, aplicar…); solo una tabla que no debe tenerlo (los renglones del comprobante, que se agregan y se quitan) lleva `data-grilla="no"`. Las grillas nuevas con páginas tienen que declarar sus columnas ordenables.

## Seguridad

Sentencias preparadas siempre (los nombres de tabla/columna solo salen de definiciones del código), `password_hash`, CSRF en todo POST,
permisos verificados en el servidor en cada ruta (y probados: qué NO puede cada rol), errores a `storage/logs/`.
Nada de datos reales de clientes en el repo ni en pruebas (las pruebas del migrador generan sus DBF con `tests/DbfFalso.php`).

## Un módulo está terminado cuando

1. Migraciones, código y vistas responsive.
2. Pruebas de PHP (lógica, permisos por rol, concurrencia si numera) y el flujo en `e2e/recorrido.js` (y `e2e/arca.js`, con ARCA simulado). `bash bin/pruebas.sh` pasa completo.
3. Se recorrió de verdad con el navegador (escritorio y celular).
4. `VERSION` subida (0.2.0 = etapa 1, 0.3.0 = etapa 2, 0.4.0 = etapa 3…; 1.0.0 = pase a producción de OYS), commit y `bin/empaquetar.sh` arma `dist/facturaweb-<version>.zip`.
5. Nota de entrega `docs/entregas/<version>-<etapa>.md` (qué incluye, qué probar con qué usuario, cómo desplegar y revertir, qué falta del cliente).
6. Skill `facturaweb-proyecto` actualizado si cambió el estado o una decisión.

## Al cerrar la sesión

Commit y push a la rama de trabajo (el contenedor no persiste).
