# FacturaWeb — guía de desarrollo (para Claude)

Contexto, decisiones y estado del proyecto: skill `facturaweb-proyecto` (copia en `.claude/skills/facturaweb-proyecto/SKILL.md`)
y `docs/analisis-migracion.md`. Esto es solo lo técnico del repositorio.

## Al empezar una sesión

1. `bash bin/setup-dev.sh`: instala MySQL 8 si falta, crea `facturaweb_dev` y `facturaweb_test` (usuario `facturaweb` / `facturaweb_dev`) y migra. Idempotente.
   Si MySQL no está corriendo (el contenedor se reinició), el mismo script lo levanta.
2. `bash bin/test.sh` tiene que pasar **antes** de tocar nada (`--unit` solo PHP). El recorrido de navegador usa el Playwright preinstalado.
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

## Seguridad

Sentencias preparadas siempre (los nombres de tabla/columna solo salen de definiciones del código), `password_hash`, CSRF en todo POST,
permisos verificados en el servidor en cada ruta (y probados: qué NO puede cada rol), errores a `storage/logs/`.
Nada de datos reales de clientes en el repo ni en pruebas (las pruebas del migrador generan sus DBF con `tests/DbfFalso.php`).

## Un módulo está terminado cuando

1. Migraciones, código y vistas responsive.
2. Pruebas de PHP (lógica, permisos por rol, concurrencia si numera) y el flujo en `e2e/recorrido.js` (y `e2e/arca.js`, con ARCA simulado). `bash bin/test.sh` pasa completo.
3. Se recorrió de verdad con el navegador (escritorio y celular).
4. `VERSION` subida (0.2.0 = etapa 1, 0.3.0 = etapa 2, 0.4.0 = etapa 3…; 1.0.0 = pase a producción de OYS), commit y `bin/package.sh` arma `dist/facturaweb-<version>.zip`.
5. Nota de entrega `docs/entregas/<version>-<etapa>.md` (qué incluye, qué probar con qué usuario, cómo desplegar y revertir, qué falta del cliente).
6. Skill `facturaweb-proyecto` actualizado si cambió el estado o una decisión.

## Al cerrar la sesión

Commit y push a la rama de trabajo (el contenedor no persiste).
