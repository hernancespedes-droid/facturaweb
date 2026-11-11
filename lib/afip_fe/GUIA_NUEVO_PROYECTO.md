# Factura electrónica en un sistema nuevo — memoria y lineamientos (O&S)

Este texto va como memoria e instrucciones de un proyecto nuevo que necesite factura electrónica de ARCA.
Resume lo hecho y aprendido en Reciclados del Oeste (noviembre 2026). Se usa junto con el módulo `afip_fe`.

## 1. Qué se reutiliza

Módulo PHP `afip_fe` (carpeta `G:\reciclados_cloud\modulos\afip_fe\`, paquete `afip_fe_v2_20261110.zip`):
- **PHP 7.0 a 8.x**, solo `openssl`, `curl` y `dom`. No usa la extensión `soap`.
- **Se incluye `afip.php` y nada más.** Espacio de nombres `OysAfip`.
- **Piezas:**
  - `Afip`: WSAA + WSFEv1, ticket guardado y bloqueo de números.
  - `Facturador`: de los renglones a la factura aprobada.
  - `CalculoComprobante`: totales.
  - `Comprobante`: control de sumas antes de enviar.
  - `FacturaHtml`: impresión A4 con QR.
  - `EquivalenciasIva` y `EquivalenciasIvaVista`: ABM de condición de IVA.
  - `OysSubdiario`: adaptador para sistemas O&S con `subdiario` / `fe_parametros`.
  - `probar_conexion.php`: diagnóstico sin emitir.
- **Pruebas sin conexión** con ARCA simulado: `tests/prueba.php`, `tests/prueba_abm.php` y `tests/prueba_facturador.php`. Correrlas después de cualquier cambio.
- **Al copiarlo a un sistema:** va en una carpeta **no pública** (por ejemplo `lib/afip_fe/`), negada en `.htaccess` y en `web.config`.

## 2. Reglas de trabajo

- **Bases:** cualquier cambio en SQL Server o MySQL lo autoriza Hernán. Agregar está bien; **borrar** lo autoriza él.
- **Facturación real:** activarla la decide Hernán.
  - Claude deja todo listo en **modo prueba** (`factura_grabar` en `false`: se carga y se revisa, pero no se graba ni se pide CAE).
  - Hernán activa el último paso.
- **Claves:** Claude no entra a ARCA con clave fiscal ni escribe claves en archivos de configuración. Prepara el pedido de certificado y las instrucciones; el trámite en ARCA lo hace Hernán o quien administre la empresa.
- **Sistemas con escritorio:** antes de programar, relevar cómo factura el sistema de escritorio (formularios, tablas, numeración, formato impreso) y **copiar su comportamiento**. Lo que difiera, decirlo.

## 3. Puesta en marcha en ARCA (checklist)

1. **Certificado propio para la web.** ARCA da **un solo ticket de acceso (TA) por certificado**: si la web usa el mismo que otro sistema, se traban entre sí hasta 12 horas ("ya posee un TA válido").
   - Claude genera la clave privada y el `.csr`.
   - **Si la empresa ya tiene un alias que funciona** (con la relación wsfe hecha), conviene **"Agregar certificado" en ese mismo alias**:
     - hereda la autorización y no hace falta el Administrador de Relaciones;
     - el TA es por certificado, así que no se traba con el otro sistema (probado en Reciclados);
     - el `.csr` tiene que llevar el **mismo DN** que el alias.
   - **Si se crea un alias nuevo,** la relación con "ARCA › WebServices › Facturación Electrónica" la tiene que hacer el **administrador de relaciones de la empresa**, entrando por la empresa.
     - Representante: Computador Fiscal (CUIT dueño del alias + alias).
     - Si la hace un usuario que no es administrador de la empresa, queda a su propio nombre y ARCA responde "No apareció CUIT en lista de relaciones".
2. **Punto de venta propio para la web**, tipo "RECE para aplicativo y web services".
   - Así no compite por los números con el escritorio.
   - Va en `config_afip.php` → `'puntos_venta' => array(N)`.
3. **Dónde va todo:** `config_afip.php`, el `.crt`, el `.key` y `cacert.pem`, en una carpeta **fuera de `public_html`** (por ejemplo `<raíz del hosting>/afip_<sistema>/`).
4. **Diagnóstico antes de emitir.** Correr `probar_conexion.php` o una página temporal solo para administradores.
   - Tiene que dar OK en: FEDummy, ticket de acceso, puntos de venta, último número y condiciones de IVA.
   - Borrar la página temporal después de usarla.
5. **Mientras la prueba da error de relación, no repetirla.** El TA sale igual y queda guardado 12 horas con las relaciones de ese momento: aunque se corrija la relación, la prueba vuelve a dar bien recién cuando vence ese TA.

## 4. Hosting (lo que falló y cómo se resolvió)

- **PHP 7.0 + OpenSSL 1.0.2** (Towebs, Windows/IIS):
  - `@SECLEVEL=1` da "failed setting cipher list". El módulo ya lo omite solo si el OpenSSL no lo soporta.
  - cURL no trae certificados raíz ("unable to get local issuer" / "self signed certificate in chain"): usar `recursos/cacert.pem` con `'ca_bundle'`.
  - **No desactivar nunca la verificación SSL.**
- **TA en la base** (`AlmacenTaPdo`, tabla `afip_ta`): no depende de permisos de escritura en carpetas.
- **Sesión trabada:** las páginas que consultan a ARCA deben liberar la sesión (`session_write_close()`) y usar un timeout corto. Si no, el usuario no puede abrir otras pestañas mientras espera.
- **Archivos en uso:** un PHP que está corriendo no se puede pisar por FTP. Subir con otro nombre o esperar.

## 5. Reglas de negocio (ARCA)

- **Condición de IVA del receptor (RG 5616), obligatoria.**
  - Se traduce del código del sistema al Id de ARCA con la tabla `equivalencias_afip` (`tabla = 'Equivaliencia'`).
  - **Nunca suponer un valor por defecto.** Si falta la equivalencia, no se envía y se avisa claro.
  - Ids de ARCA: 1 RI, 4 Exento, 5 Consumidor Final, 6 Monotributo, 13 Monotributista social, 16 Monotributo trabajador independiente promovido, 7 No categorizado, 8 y 9 exterior, 10 Liberado, 15 No alcanzado.
  - En O&S (`catg_iva`), las sugeridas son 1→1, 3→6, 4→4, 5→5.
- **Letra:**
  - Emisor RI: A a RI y monotributistas (Ids 1, 6, 13, 16) y B al resto.
  - Emisor monotributista o exento: C, sin IVA discriminado.
- **Cálculo:**
  - Renglón = cantidad × precio, redondeado a 2 decimales.
  - IVA agrupado por alícuota; la alícuota 0 % va como IVA Id 3.
  - Lo exento va en `ImpOpEx`.
  - Percepciones de IIBB como tributo Id 2: subtotal × alícuota.
- **Consumidor final sin documento:** DocTipo 99, DocNro 0.
- **NC / ND:**
  - Comprobante asociado obligatorio, y tiene que tener CAE.
  - El tipo del asociado sale del dato aprobado (`fe_ntipoafip`); si no está, de `tipoafip_oys` o de tipo + letra.
- **FCE MiPyMEs (201/206/211):**
  - Vencimiento de pago (30 días por defecto) y opcionales 2101 (CBU) y 27 (SCA).
  - NC/ND FCE: opcional 22 (anulación N; si ARCA rechaza, reintentar con S).
- **Fecha:** ARCA acepta hasta 5 días atrás (productos) y nunca anterior al último comprobante aprobado de ese punto de venta y tipo.
- **Un comprobante con CAE no se anula:** se cancela con una nota de crédito.

## 6. Flujo de pantalla (probado)

1. **Formulario:** cliente, comprobante, punto de venta (solo los de la web), fecha y renglones. Totales en vivo en el navegador, pero el **servidor vuelve a calcular todo**.
2. **"Revisar":** muestra el comprobante completo (IVA por alícuota, percepciones, total). Todavía no se manda nada.
3. **"Emitir y pedir CAE":**
   - se pide el número a ARCA (con bloqueo por punto de venta y tipo) y el CAE;
   - **si ARCA rechaza, no se graba nada** y se muestra el motivo con su código;
   - **si aprueba,** se graba con el número y el CAE de ARCA.
4. **Protección contra doble envío:** la página de revisión lleva una clave de la operación. Si llega dos veces (doble clic, volver atrás), se muestra la que ya existe.
5. **Si la grabación falla después del CAE:** el comprobante existe en ARCA. Se registra en el log con todos los datos y se avisa para cargarlo. **Nunca pedir otro CAE** para la misma operación; `Afip::autorizar` ya recupera con `FECompConsultar` si se corta la conexión.
6. **Impresión inmediata** con `FacturaHtml`, desde lo grabado (o desde la cola, en sistemas donde lo grabado tarda en llegar a la nube).
7. **Modo prueba** (`factura_grabar = false`) y modo "sistema" (graba sin CAE y lo aprueba el escritorio) como respaldo mientras no está el certificado.

## 7. Arquitectura Reciclados (si el sistema nuevo tiene escritorio + nube)

- **SQL Server** en la oficina y **MySQL** en el hosting, sincronizados por un agente Python.
- **La web no escribe en SQL Server directo:** deja operaciones `{t, a, v, k}` en una cola (`web_cola`) y el agente las aplica en una transacción.
  - Lista blanca de tablas y acciones (`PERMITIDO`).
  - Numeradores (`@@ultimo_fe`), `existe` (idempotencia), `conflicto` y `guardas`.
- **Con CAE pedido por la web:** la operación ya lleva el número y el CAE de ARCA, así que el agente solo la graba.
- **Configuración de la facturación:** fuera de `public_html` (`afip_<sistema>/facturacion.php`, con `factura_cae`, `afip_config` y `factura_grabar`). Así no se toca el `config.php` que tiene las claves de la base.

## 8. Pruebas

- **Local:**
  - MariaDB + `php -S` + Playwright.
  - ARCA simulado con `Soap::$simulador` (ver `tests/prueba_facturador.php`).
  - Probar alta, rechazo de ARCA, falta de equivalencia, NC con asociado, FCE, doble envío e impresión.
- **Homologación:** si se quiere emitir una de verdad sin valor fiscal, hace falta un certificado de homologación (WSASS) y `'produccion' => false`.
- **Primera factura en producción:**
  - chica, a un cliente Responsable Inscripto conocido;
  - verificar número, CAE, impresión y cuenta corriente;
  - tener lista la nota de crédito para anularla.
