# Factura electrónica (ARCA): puesta en marcha paso a paso

Para Hernán. Claude no entra a ARCA con la clave fiscal: los pasos de ARCA los hacés vos. El sistema queda en **modo interno**
(sin CAE) hasta que cambies el modo en `config/local.php`. Nada de esto toca el sistema VFP de escritorio.

## 0. Probar todo SIN certificado: modo simulado

Sirve para probar el circuito completo (CAE, número de ARCA, QR, nota de crédito, rechazo) antes de hacer el trámite.

1. En `config/local.php` agregar:
   ```php
   'afip' => ['modo' => 'simulado'],
   ```
2. **Tablas → Sucursales**: en la sucursal de prueba tildar **Factura electrónica**.
3. Emitir una factura: el CAE empieza con `99`, la hoja dice *COMPROBANTE DE PRUEBA (ARCA SIMULADO)*, lleva QR.
4. Para probar un rechazo: un total que termine en **,13** (decisión D-14).
5. **Sistema → ARCA → Probar conexión** tiene que mostrar todo en ✔.
6. Cuando termines: **Sistema → ARCA → Borrar comprobantes de prueba** (solo Master; escribir la frase que pide).

## 1. Certificado (alias OYS, CUIT 20-11499319-1)

El CSR y la clave ya se generaron con `php bin/afip-csr.php` (DN igual al del alias). Si hace falta regenerarlos:

```bash
php bin/afip-csr.php --cuit=20114993191 --org="ioszpe enrique daniel" --cn="organizacion y sistemas" --salida=/ruta/fuera/de/public_html/afip_oys
```

1. ARCA → **Administración de certificados digitales** → alias OYS → **Agregar certificado** → subir el `.csr`.
2. Descargar el `.crt` que devuelve ARCA.
3. Dejar en `afip_oys/` (fuera de `public_html`): `oys_web.key`, el `.crt` descargado y `cacert.pem` (está en `lib/afip_fe/recursos/`).
4. **Certificado propio para la web:** no usar el del escritorio (ARCA da un solo ticket de acceso por certificado y se trabarían).
   Si ARCA rechaza el CSR por el país, regenerar con `--pais=ar`.

## 2. Punto de venta propio de la web

ARCA → **Administración de puntos de venta y domicilios** → **A/B/M de puntos de venta** → agregar uno con sistema
**«RECE para aplicativo y web services»** (propio de la web, así no compite por los números con el VFP).

## 3. `config_afip.php` (fuera de `public_html`)

Copiar `lib/afip_fe/config_afip.ejemplo.php` como `afip_oys/config_afip.php` y completar: `cuit`, `produccion` (`false` en homologación),
`certificado`, `clave_privada` y `puntos_venta` (el número del punto de venta nuevo).

## 4. Activar el modo

En `config/local.php`:

```php
'afip' => ['modo' => 'homologacion', 'config' => '/ruta/afip_oys/config_afip.php'],
```

| Modo | Qué hace | `produccion` en config_afip.php |
|------|----------|----------------------------------|
| `interno` | sin CAE (por omisión) | — |
| `simulado` | ARCA de mentira, sin certificado | — |
| `homologacion` | ARCA de pruebas, certificado de homologación | `false` |
| `produccion` | ARCA real | `true` |

El modo y `produccion` tienen que coincidir o el sistema no emite (así no se mezclan pruebas con comprobantes reales).

## 5. Pasos hacia producción

1. **Sistema → ARCA → Probar conexión** en homologación: certificado, FEDummy, ticket de acceso, puntos de venta, última numeración.
2. En **Tablas → Sucursales**, la sucursal de la web: poner el **punto de venta** nuevo y tildar **Factura electrónica**.
3. Emitir una factura chica y una nota de crédito en homologación (el CAE no vale: es de prueba).
4. Borrar las pruebas (Sistema → ARCA, Master).
5. Cambiar a `'modo' => 'produccion'` y `'produccion' => true` en `config_afip.php`, con el certificado de producción.
6. Primera factura real, chica. Si ARCA aprobó y no se pudo grabar, aparece en **Sistema → ARCA → Aprobados por ARCA y no grabados** → *Grabar*.

El log de lo que se manda y se recibe (sin tokens ni firmas) queda en `storage/logs/afip-AAAA-MM.log`.
