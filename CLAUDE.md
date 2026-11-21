# FacturaWeb — guía de desarrollo (para Claude)

Contexto, decisiones y estado del proyecto: skill `facturaweb-proyecto` (copia en `.claude/skills/facturaweb-proyecto/SKILL.md`)
y `docs/analisis-migracion.md`. Esto es solo lo técnico del repositorio.

> **No modificar `bin/package.sh`, `bin/test.sh`, `bin/setup-dev.sh`, `bin/dev-server.sh` ni `bin/env.sh`.** El hosting (Ferozo, sin SSH) les cambia los permisos y GIT se niega a
> sincronizar si una versión nueva los toca («local changes would be overwritten»). Quedaron congelados **idénticos a los del commit d0daf5a** (0.11.0), que es lo que tiene el servidor de OYS; si cambian respecto de lo que tiene el servidor, GIT no actualiza (pasó con 0.11.1); los scripts vivos son
> `bin/pruebas.sh` (pruebas) y `bin/empaquetar.sh` (paquete). Si hace falta cambiar algo de eso, hacerlo en archivos nuevos.

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

## Caja (0.25.0)

`Modules/Caja/CajaService`: turnos con arqueo (`caja_turnos`), ingresos/egresos manuales (`caja_movimientos`, tope `parametros.caja_tope`, se anulan con contra-asiento `anula_id`, nunca se editan) y `caja_auditoria` con hash encadenado (`verificar()`). Todo cobro nuevo en `caja` lleva `usuario_id`, `creado_en` y `turno_id` con `CajaService::contexto()` (hay que sumarlo en cualquier INSERT nuevo en `caja`). Permisos `caja.turno`, `caja.mover`, `caja.auditar`.

## Kiosco: orden, precio y venta sin stock (0.26.0)

`articulos.orden_kiosco` ordena las tarjetas (`BuscadorArticulos::listar(..., $kiosco = true)`). `KioscoService::fijarPrecios` impone el precio de lista a quien no tiene `kiosco.precio` (se aplica en `cobrar` y en `emitir`). `ReservasService::autorizaPorPermiso` salta la autorización de stock para roles con `kiosco.sin_stock` (solo PR del kiosco) o `stock.vender_sin_stock`; `ComprobantesService::armar` lo guarda en `stock_automatico` y `grabar` lo registra con `automatica = 1` (informe `Stock/VentasSinStock`).

## Mercado Pago (0.27.0)

`Modules/MercadoPago/MercadoPago` es el único que habla con la API (modos `mp.modo` apagado|simulado|produccion en `config/local.php`; `MercadoPago::$transporte` permite probar sin red). `MercadoPagoService`: «Cobrar con Mercado Pago» deja la venta en un cobro `pendiente` (tabla `mp_cobros`, sin claves) y **solo se emite al acreditarse el pago** (`confirmar` → `emitir`, idempotente con el token como clave de operación; `CajaService::$actor` pone al cajero cuando no hay sesión). La notificación va por `/api/v1/mercadopago/webhook` (sin sesión ni CSRF) con firma HMAC verificada y el pago se vuelve a consultar en Mercado Pago. `ComprobantesService::armar` acepta `$autorizoPrevio` (solo desde el código) para la autorización de stock ya resuelta.

## Estadística, stock y costos (0.32.0)

`Listados/Estadistica` (agrupar por artículo, rubro, cliente, vendedor, mes…, ranking y detalle; `/listados/estadistica` y `/listados/rentabilidad`), `Listados/ListaCostos` (`/listados/costos`), `Stock/MovimientosStock` (trazabilidad con saldo corrido y resumen; `/stock/movimientos`) y `Stock/Existencias` (por depósito a una fecha; `/stock/existencias`).
**Todo INSERT nuevo en `estadistica` lleva `costo`** (unitario en pesos al emitir, `Listados\Costos::unitarios`, D-62). Costos, márgenes y valorizados solo con el permiso `listados.costos`. La estadística no cuenta la factura de un presupuesto (`solo_subdiario = 1`) y resta NC/NX (D-63).

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

## Apariencia por cliente (0.31.0)

`Configuracion/Apariencia`: nombre, color (`--brand`, `--brand-2`, `--on-brand` calculados con contraste) y logo de cada instalación en barra, ingreso, pestaña y portal
(claves `apariencia.*` en `configuracion`, fuera de la lista general; imágenes `logo_web`/`logo_pantalla`/`logo_icono` en `imagenes`, servidas por `/marca/logo` y `/marca/icono` sin sesión).
Colores nuevos en `app.css`: siempre con `var(--brand)` / `color-mix(...)` y `var(--on-brand)` para el texto sobre el color, nunca el azul fijo. La marca del cliente va arriba y O&S firma al pie.

## Importar desde Excel, CSV y TXT (0.33.0)

`Modules/Importacion` (Sistema → Importar datos, permiso `importar.datos` + el de cada destino en `Destinos::PERMISO`): `Lector` (xlsx con ZipArchive/XMLReader, CSV/TXT con separador y
codificación detectados; números del Excel con coma decimal), `Destinos` (campos por destino: clientes y artículos reutilizan `campos()` de sus servicios; sinónimos de títulos),
`Importador` (todo en una transacción con SAVEPOINT por fila; probar = rollback; todo o nada salvo «parcial»), `Modelos` (`importacion_modelos`: campo → título de columna).
Clientes/artículos: al actualizar solo cambian las columnas elegidas y con dato. Comprobantes históricos: `subdiario` canal `I` + `importacion_id`, `iva`, `ctact` solo con saldo, sin caja ni stock;
la clave para no duplicar es tipo + letra + punto de venta + número + fecha. Registro en `importaciones`. Campo nuevo en clientes/artículos: si debe importarse, sumarle sinónimos en `Destinos`.

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
