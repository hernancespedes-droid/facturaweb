# Despliegue (una instalación por cliente)

Cada cliente tiene **su carpeta, su base y su configuración**. El mismo código se actualiza en todas.
Primero se instala en un ambiente de prueba y después en producción, con el mismo procedimiento.

## Requisitos

- PHP 8.2 o superior con `pdo_mysql`, `mbstring`, `curl`, `openssl`, `dom` (y `zip` para subir los DBF desde Sistema).
- MySQL 8.
- El docroot del dominio tiene que ser `public/`. Si el hosting no deja elegirlo, el `.htaccess` de la raíz bloquea el código y manda todo a `public/`.

## DonWeb (Ferozo) con GIT, sin SSH

Ferozo clona el repositorio dentro de `public_html/`.

1. Ferozo → GIT → **Clave SSH**: cargarla en GitHub como *Deploy key* de solo lectura del repo `facturaweb`.
2. GIT → **Crear nuevo**: repo `git@github.com:hernancespedes-droid/facturaweb.git`, rama de la versión, directorio `facturador_web`.
3. Subir `docs/donweb/public_html.htaccess` como `public_html/.htaccess` (si el dominio del facturador apunta a `public_html`)
   o apuntar el subdominio (`facturador.<dominio>`) a `public_html/facturador_web/public`.
4. Crear la base y su usuario en el panel. Crear `facturador_web/config/local.php` (copia de `config/local.example.php`) con:
   los datos de la base y `'instalar_token'` con una clave larga al azar.
5. Permisos 775 a `facturador_web/storage/` y subcarpetas.
6. Abrir `https://<dominio>/instalar`: pide la clave de instalación, crea las tablas y el usuario **Master** (O&S).
   Después **borrar `instalar_token`** de `local.php` (la ruta igual queda apagada porque ya existe un Master).
7. `/salud` → `"ok":true` y la versión. Ingresar con el Master.
8. **Mail de salida** (para enviar comprobantes): en `config/local.php` completar la sección `mail` con la casilla del dominio del cliente
   (host, puerto, `tls` o `ssl`, usuario, contraseña, `from`); sin eso el botón Enviar queda deshabilitado.
9. Datos del cliente: **Tablas → Parámetros** (razón social, CUIT, condición de IVA, inicio de actividades) y, si viene del
   VFP, **Sistema → Migración desde el sistema VFP** (ver `docs/migracion-dbf.md`).

## Con SSH

1. Clonar o descomprimir el paquete `dist/facturaweb-X.Y.Z.zip` en la carpeta del cliente.
2. `cp config/local.example.php config/local.php` y completar la base.
3. `chmod -R 775 storage`
4. `php bin/migrate.php` y `php bin/crear-master.php` (la contraseña se pide por teclado y no queda escrita).
5. Migración del VFP: `php bin/migrar-dbf.php <carpeta> --simular`, revisar los avisos y repetir sin `--simular`.

## Actualizar

1. **Backup** de la base (phpMyAdmin → Exportar, o `mysqldump`).
2. Ferozo → GIT → actualizar (pull) el repositorio de la instalación (con SSH: `git pull` o descomprimir el zip nuevo).
3. Ingresar como Master → **Sistema → Aplicar cambios** (o `php bin/migrate.php`).
4. `/salud` muestra la versión nueva y `cambios_de_base_pendientes: 0`.

Nunca se pisan `config/local.php` ni `storage/`.

## Factura electrónica (etapa 3)

`config_afip.php`, certificado, clave y `cacert.pem` van **fuera de `public_html`** (por ejemplo `<raíz>/afip_<cliente>/`),
uno por instalación. Paso a paso (incluido el modo simulado para probar sin certificado): `docs/ARCA-PUESTA-EN-MARCHA.md`.
En `config/local.php`: `'afip' => ['modo' => 'interno|simulado|homologacion|produccion', 'config' => '/ruta/afip_<cliente>/config_afip.php']`.

## Diagnóstico

`php bin/diagnostico.php` o, sin SSH, `'diag_token'` en `local.php` y abrir `/diagnostico?token=LA_CLAVE` (después vaciarlo).
