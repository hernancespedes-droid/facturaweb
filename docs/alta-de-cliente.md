# Alta de un cliente nuevo (suscripción mensual)

Cada cliente es **una instalación propia**: su subdominio, su carpeta, su base, su certificado y punto de venta de ARCA y su licencia.
El código es el mismo para todos. Este manual es el camino que se repite; lo que cambia de un cliente a otro va en su ficha.

## Sitio master y dos carpetas (0.29.0)

**oys.orgysis.site es el sitio master de O&S**: desde ahí se crean, facturan, suspenden y dan de baja los clientes, y es donde se prueba primero cada cambio.

| Carpeta en el hosting | Rama de GIT | Quién la usa |
|---|---|---|
| `facturador_web` (la actual) | `main` | el master (oys.orgysis.site) |
| `facturador_clientes` (nueva) | `estable` | todos los clientes (`cliente.orgysis.site`) |

**Armar la carpeta de clientes (una sola vez):**
1. Ferozo → GIT → Crear nuevo: mismo repo, rama **`estable`**, directorio `facturador_clientes`. Permisos 775 a `facturador_clientes/storage/`.
2. En `facturador_web/config/local.php` (el del master) agregar:
   `'panel' => ['carpeta_clientes' => '/ruta/completa/public_html/facturador_clientes', 'github_token' => '…'],`
   La ruta completa se ve en Sistema → Instalaciones (la de la carpeta actual, cambiando el último nombre). El token es opcional: GitHub → Settings →
   Developer settings → Fine-grained tokens → solo el repo `facturaweb`, permiso *Contents: Read and write*. Con el token aparece el botón «Pasar a clientes».
3. Si ya había clientes creados en la carpeta del master, el panel muestra «Pasar a la carpeta de clientes»: tocarlo y apuntar sus subdominios a `facturador_clientes/public`.

**Cada cambio pedido a Code:**
1. Code hace el PR a `main`; se fusiona. Ferozo → GIT → `facturador_web` → actualizar. Sistema → Aplicar cambios. **Se prueba en el master.**
2. Si está bien: Instalaciones de clientes → **Pasar a clientes** (mueve `estable` al commit exacto que corre el master; sin token, pedírselo a Code).
3. Ferozo → GIT → `facturador_clientes` → actualizar. Instalaciones de clientes → **Aplicar cambios en todas**.
4. Si la función nueva no la tienen que tener todos: viene como **módulo** apagado (`Core\Modulos`) y se prende por cliente en «Administrar → Módulos».

**Suscripción** (Administrar → Suscripción): Activa → Aviso de pago pendiente (cartel) → Sin emitir (consultan e imprimen) → Bloqueada (solo entra el Master de O&S),
con fechas opcionales para que avance sola. **Baja**: descargar la copia (`.sql.gz`) y después «Dar de baja» borra tablas y carpeta; falta borrar subdominio y base en el hosting.
Cada cliente nuevo queda además como **cliente del master** (para facturarle; si el CUIT ya existe, se usa ese).

## Lo rápido: desde la web (0.28.0)
1. Panel del hosting: crear una **base vacía** con su usuario.
2. En la instalación principal (oys.orgysis.site), como Master: **Sistema → Instalaciones de clientes → + Nuevo cliente** (empresa, subdominio, base, usuarios). Crea las tablas,
   el Master, un Administrador con clave provisoria y la configuración del cliente.
3. Panel del hosting: crear el **subdominio** apuntando a la **misma carpeta `…/public`** que la principal y activar su SSL.
4. Entrar a `https://<subdominio>` y seguir con Parámetros/Sucursales, migración del VFP (Sistema), licencia y ARCA (modo y mail se cambian desde el panel, «Mail, ARCA y licencia»).
5. Actualizar: GIT una sola vez + **Aplicar cambios en todas** en el panel.

El camino de abajo (una carpeta y un clon de GIT por cliente) sigue sirviendo para un cliente en otro hosting.

## Cómo se trabaja un alta (manual, otro hosting)
1. `php bin/alta-cliente.php --nombre="Razón social" --cuit=<11 dígitos> --dominio=<dominio.com.ar> --salida=<carpeta fuera del repo>`
   deja `config/local.php` listo, `PASOS.md` (la lista con los datos del cliente) y `ficha.md`. Las plantillas están en `docs/clientes/`.
2. Se sigue `PASOS.md` marcando cada paso. **Todo lo que salga distinto de lo esperado se anota en `ficha.md`, sección Lecciones, en el momento.**
3. Al cerrar: lo generalizable pasa a `docs/lecciones-alta.md`, la instalación se suma a `docs/instalaciones.md` (sin claves) y la ficha va a Drive.

## Reglas que no se negocian
- **Una base y una carpeta por cliente.** Nunca se comparten.
- **Nada de datos del cliente, claves ni certificados en el repositorio ni en el chat**: la ficha, el `.rar` del VFP, `config/local.php`, la clave privada de ARCA y la clave privada de licencias viven en Drive o en el servidor.
- **Dev Senior no entra a ARCA ni escribe claves fiscales**: prepara la clave privada y el `.csr`, y el cliente (o Hernán con su autorización) hace lo que se hace dentro de ARCA.
- Siempre se prueba **primero en modo interno, después homologación, después producción**, y la migración **primero con `--simular`**.
- Se entrega con la nota `docs/entregas/…` y el cliente firma la prueba.

## Los pasos, y por qué
| # | Paso | Quién | Detalle y por qué |
|---|---|---|---|
| 1 | Hosting | Hernán | Mismo hosting que los demás (DonWeb/Ferozo con GIT, sin SSH): subdominio `facturador.<dominio>` → `public/`; base nueva; repo `facturaweb` rama `main`. Ver `docs/DESPLIEGUE.md`. |
| 2 | Configuración | Hernán | `config/local.php` generado; completar base y mail del cliente. La clave de instalación ya viene puesta. |
| 3 | Instalar | Hernán | `/instalar` crea las tablas y el Master. **Vaciar `instalar_token` después.** `/salud` tiene que dar `ok`. |
| 4 | Licencia | Hernán | Se emite con `bin/licencia.php` (la clave privada nunca sale de tu PC). Cada mes se emite una nueva y se carga en Sistema → Licencia. |
| 5 | Datos de la empresa | Hernán + cliente | Parámetros y sucursal/punto de venta con la numeración que sigue a la última del sistema anterior. |
| 6 | Migrar el VFP | Dev Senior | `.rar` por Drive, `php bin/migrar-dbf.php <carpeta> --simular`, revisar avisos, migrar, controlar saldos y cantidades contra el VFP. Ver `docs/migracion-dbf.md`. |
| 7 | ARCA | Dev Senior prepara, cliente/Hernán ejecutan | `bin/afip-csr.php` genera la clave y el `.csr`; el cliente lo sube, descarga el certificado y habilita el punto de venta RECE. Ver `docs/ARCA-PUESTA-EN-MARCHA.md`. |
| 8 | Puesta en marcha | Todos | Prueba con el cliente presente (una factura y una NC reales), usuarios y roles, agente + importador si sigue usando el VFP en paralelo, capacitación. |
| 9 | Cierre | Dev Senior | Ficha completa, lecciones, instalaciones, nota de entrega. |

## Suscripción mensual: la licencia
- Una licencia es un archivo firmado por O&S (Ed25519) con el cliente, el CUIT y la fecha de vencimiento. La instalación solo tiene la **clave pública**: puede verificarla pero no fabricarla ni alargarla; tampoco sirve para otro CUIT.
- **Una sola vez** (Hernán): `php bin/licencia.php clave --salida=<carpeta segura>`; guardar `licencia_privada.key` con copia; copiar `licencia_publica.txt` a `config/licencia_publica.txt` y subirlo al repositorio. *Hasta que eso se haga, las instalaciones no pueden verificar licencias.*
- **Cada mes** por cliente: `php bin/licencia.php emitir --privada=<…key> --cliente="…" --cuit=… --meses=1` → mandar `licencia.json` → el cliente (o el Master) lo pega en Sistema → Licencia.
- Estados: más de 10 días de margen = normal; de 10 días antes a 0 = aviso a Master/Administrador; hasta 5 días vencida = aviso a todos y se sigue emitiendo; después = **no se emite** (sí se consulta e imprime). Decisiones D-26/D-27.
- Solo se controla si `config/local.php` tiene `'licencia' => ['exigir' => true]` (el alta lo pone). OYS u otra instalación sin suscripción no lo tiene y no cambia nada.
- Cobranza: el cobro mensual lo manejás vos; la licencia se emite cuando el cliente pagó. Si el cliente no paga, simplemente no se renueva.

## Para presupuestar el próximo
Con la ficha de cada alta se llena la tabla de tiempos reales. Una vez que haya 2 o 3 altas se promedian y se reemplazan las estimaciones del skill `facturaweb-proyecto` por números medidos.

## Lo que todavía no está automatizado (siguientes mejoras, por prioridad)
1. ~~Actualizar todas las instalaciones de una vez~~: hecho en 0.28.0 (panel de instalaciones) para los clientes que comparten el código.
2. **Renovación automática de licencias** (hoy manual; luego un pequeño servicio o un archivo que la instalación consulta).
3. Importador de maestros desde Excel para clientes que no vienen del VFP.
4. Alta de la base y el hosting por API (hoy son pasos manuales en el panel).
