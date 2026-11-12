# Alta de {{nombre}} — pasos (generado el {{fecha}} con la versión {{version}})

CUIT {{cuit}} · punto de venta {{pv}} · instalación `{{slug}}` · `https://facturador.{{dominio}}`
Manual completo y el porqué de cada paso: `docs/alta-de-cliente.md`. Marcá cada paso al terminarlo; lo que salga distinto va a `ficha.md` (sección «Lecciones»).

## 1. Hosting (mismo hosting que las otras instalaciones)
- [ ] Subdominio `facturador.{{dominio}}` apuntando a `public_html/facturador_{{slug}}/public` (o el que corresponda).
- [ ] Base de datos **nueva y propia** `…_{{slug}}` con su usuario (nunca compartir base entre clientes).
- [ ] Ferozo → GIT → crear: repo `facturaweb`, rama `main`, directorio `facturador_{{slug}}`.
- [ ] Permisos 775 a `storage/` y subcarpetas.

## 2. Configuración
- [ ] Subir `config/local.php` (de esta carpeta) a `facturador_{{slug}}/config/` y completar la base (`db`) y el mail (`mail`, casilla del cliente).
- [ ] Cambiar la ruta `afip.config` por la real del hosting (carpeta `afip_{{slug}}/` **fuera** de `public_html`).

## 3. Instalar
- [ ] Abrir `https://facturador.{{dominio}}/instalar`, clave de instalación = `instalar_token` de `local.php`, crear el usuario Master (O&S).
- [ ] **Vaciar `instalar_token`** en `local.php`. Verificar `/salud` → `"ok":true`.

## 4. Licencia (suscripción mensual)
- [ ] En tu PC: `php bin/licencia.php emitir --privada=<licencia_privada.key> --cliente="{{nombre}}" --cuit={{cuit}} --meses=1`
- [ ] Master → Sistema → Licencia → pegar el contenido de `licencia.json`. Tiene que decir «Suscripción vigente hasta …».

## 5. Datos de la empresa
- [ ] Tablas → Parámetros: razón social, CUIT {{cuit}}, condición de IVA, inicio de actividades.
- [ ] Tablas → Sucursales: la sucursal con el punto de venta {{pv}} y su numeración (continuar la última del sistema actual).

## 6. Migrar desde su VFP
- [ ] Pedirle la carpeta de datos del VFP (`.rar`/`.zip`) por Drive, **nunca al repo**.
- [ ] `php bin/migrar-dbf.php <carpeta> --simular`, revisar los avisos; después sin `--simular` (o Sistema → Migración).
- [ ] Controlar saldos y cantidades contra el VFP (clientes, artículos, comprobantes).

## 7. ARCA (factura electrónica)
- [ ] `php bin/afip-csr.php --cuit={{cuit}} --org="<razón social como figura en ARCA>" --cn="<alias>" --salida=<carpeta fuera de public_html>`
- [ ] El cliente sube el `.csr` en ARCA (Administración de certificados digitales), descarga el `.crt` y habilita el punto de venta {{pv}} (RECE para WS). **Claude no entra a ARCA ni escribe claves.**
- [ ] `config_afip.php` + `.key` + `.crt` + `cacert.pem` en `afip_{{slug}}/`; modo `homologacion`, luego `produccion`. Guía: `docs/ARCA-PUESTA-EN-MARCHA.md`.

## 8. Puesta en marcha
- [ ] Prueba en modo interno → homologación → producción con el cliente presente (una factura y una nota de crédito reales).
- [ ] Usuarios y roles (Administrador / Operador) con contraseñas propias.
- [ ] Si sigue usando el VFP en paralelo: instalar el agente (`agente-windows/`) y el importador (`vfp-importador/`), con prueba en copia de datos.
- [ ] Capacitación y nota de entrega (`docs/entregas/`).

## 9. Cierre
- [ ] Completar `ficha.md` (tiempos reales y lecciones) y pasar lo generalizable a `docs/lecciones-alta.md` del repo.
- [ ] Agregar la instalación a `docs/instalaciones.md` (sin claves) y anotar el vencimiento de la licencia para renovarla cada mes.
