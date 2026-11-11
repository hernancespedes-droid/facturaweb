# FacturaWeb

Facturador web de Organización & Sistemas, migración del sistema de facturación VFP9 de O&S (menú `menu1`).
PHP 8.2+ y MySQL 8, sin framework, sobre el núcleo de `oesteparts` (login con bloqueo, usuarios, roles,
migraciones, página Sistema, pruebas). **Una instalación por cliente**: su carpeta, su base y su certificado de ARCA.

Análisis y etapas: [`docs/analisis-migracion.md`](docs/analisis-migracion.md). Factura electrónica (etapa 3): `lib/afip_fe/`.

## Qué tiene (etapas 1 y 2, versión 0.3.0)

- **Ingreso** con bloqueo por intentos, "recordarme", contraseña temporal y cierre por inactividad.
- **Usuarios y roles** (Sistema): cada usuario tiene un rol; cada rol, las opciones del menú que puede usar
  (una por opción del VFP) y si edita o solo consulta. Roles de fábrica: Master (O&S), Administrador, Operador.
- **Tablas** (las 12 del VFP): sucursales, rubros, subrubros, provincias, parámetros (datos de la empresa),
  bancos, cotizaciones del dólar, vendedores, alícuotas de IVA, condiciones de pago, transportes y formas de pago.
- **Clientes y artículos** con el esquema O&S (mismos nombres de tabla y campo que `datos.dbc`).
- **Facturación** (etapa 2): facturas, notas de crédito y débito A, B y C con letra automática, IVA por alícuota, numeración, cuenta corriente,
  impresión A4 y PDF propio y envío por mail. Todavía **sin CAE** de ARCA (etapa 3): los comprobantes son internos.
- **Migrador DBF → MySQL** (`php bin/migrar-dbf.php` o subiendo un .zip en Sistema): ver [`docs/migracion-dbf.md`](docs/migracion-dbf.md).

## Desarrollo

```bash
bash bin/setup-dev.sh
```

```bash
bash bin/test.sh
```

```bash
bin/dev-server.sh
```

`bin/test.sh --unit` corre solo las pruebas de PHP; sin `--unit` también el recorrido de navegador (`e2e/recorrido.js`, Playwright).
El sistema queda en http://127.0.0.1:8080; el primer usuario se crea con `php bin/crear-master.php`.

## Carpetas

| Carpeta | Contenido |
|---|---|
| `public/` | Lo único que sirve la web (docroot): `index.php`, estilos y scripts |
| `src/` | Código. Cada módulo en `src/Modules/<Nombre>/` (Auth, Usuarios, Roles, Tablas, Clientes, Articulos, Comprobantes, Configuracion, Sistema, Migracion) |
| `views/` | Pantallas |
| `config/` | Configuración; `config/local.php` (solo en el servidor) tiene las claves de la base |
| `database/migrations/` | Cambios de base numerados (`php bin/migrate.php` o Sistema → Aplicar cambios) |
| `lib/afip_fe/` | Módulo de factura electrónica (no público) |
| `vfp/` | Fuentes del sistema VFP (referencia; no van en los paquetes) |
| `bin/`, `tests/`, `e2e/` | Herramientas y pruebas |
| `docs/` | Análisis, despliegue, migración y notas de entrega |

Despliegue: [`docs/DESPLIEGUE.md`](docs/DESPLIEGUE.md). Chequeo rápido: `/salud` tiene que mostrar `"ok":true`.
