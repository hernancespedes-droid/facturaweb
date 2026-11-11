# Módulo PHP de factura electrónica ARCA (O&S)

Pide el CAE a ARCA (WSAA + WSFEv1) desde cualquier sistema PHP de O&S. Funciona con PHP 7.0 a 8.x y solo necesita las extensiones `openssl`, `curl` y `dom`. No usa la extensión `soap`.

Salió del webservice C# de Reciclados (`RecicladorWs/cAFIP_Metodos.cs` y `WS_AFIP/WSAFIP.cs`). Hace lo mismo que ese webservice y le agrega algunas mejoras.

## Archivos

| Archivo | Para qué |
|---|---|
| `afip.php` | Se incluye esto y nada más. |
| `src/Wsaa.php` | Firma el pedido con el certificado y obtiene el ticket de acceso (TA). Guarda el TA en un archivo (`AlmacenTaArchivo`) o en una tabla (`AlmacenTaPdo`). |
| `src/Wsfe.php` | Último número, pedido de CAE, consulta de comprobantes y tablas de parámetros. Si se corta la conexión, `autorizar()` consulta a ARCA antes de dar error. |
| `src/Comprobante.php` | El comprobante: tipos, IVA, tributos, asociados y opcionales. Valida las sumas antes de enviar. No depende de ninguna base de datos. |
| `src/Calculo.php` | `CalculoComprobante::calcular()`: totales de un comprobante desde sus renglones (IVA por alícuota, percepciones, total), igual que los facturadores de O&S. No depende de tablas. |
| `src/Facturador.php` | `Facturador::emitir()`: de los renglones a la factura aprobada para cualquier sistema (sin el esquema O&S). Calcula, elige la letra, arma IVA, exentos, percepciones, asociados y FCE, y pide el CAE. |
| `src/Impresion.php` | `FacturaHtml::render()`: comprobante imprimible (HTML A4, se guarda como PDF desde el navegador) con el diseño de la factura O&S, CAE y QR de ARCA. Lleva `assets/qrcode.js` adentro de la página. |
| `src/Utiles.php` | `Afip` (punto de entrada), `QrAfip` (URL del QR) y `Bloqueo` (para que no se repitan números). |
| `adaptadores/OysSubdiario.php` | Para los sistemas O&S con `subdiario` y `fe_parametros` (Reciclados, Prenpol…). Toma los nombres de tablas y campos de `fe_parametros`, igual que el C#. |
| `abm/EquivalenciasIva.php` | ABM de las equivalencias de condición de IVA (`equivalencias_afip`, tabla `Equivaliencia`). Arma operaciones `{t, a, v, k}`: las ejecuta directo con PDO o las pasa a un callback (la cola de Reciclados). Crea la tabla en MySQL si no existe. |
| `abm/EquivalenciasIvaVista.php` | La pantalla del ABM: listado, alta, modificación, baja, aviso de códigos en uso sin equivalencia y botón para cargar las sugeridas. El sistema pone el menú, el permiso y el CSRF. |
| `herramientas/probar_conexion.php` | Revisa el certificado, el TA, los puntos de venta, el último número y las condiciones de IVA. No emite nada. |
| `ejemplos/emitir_simple.php` | Ejemplo completo para un sistema nuevo: emitir, grabar e imprimir. |
| `recursos/cacert.pem` | Certificados raíz de Mozilla, para hostings donde cURL no valida a ARCA ("unable to get local issuer"). |
| `GUIA_NUEVO_PROYECTO.md` | Lineamientos para usar el módulo en otro sistema (para pasar como memoria/instrucciones del proyecto). |
| `tests/` | Pruebas sin conexión, con ARCA simulado y una base de prueba cargada con los CSV de Reciclado. |

## Uso rápido (sistema nuevo)

```php
require 'afip_fe/afip.php';
$afip = new OysAfip\Afip(require '/ruta/privada/config_afip.php');
$r = (new OysAfip\Facturador($afip, $pdo))->emitir(array(
    'tipo' => 'FC', 'pto_vta' => 5,
    'receptor' => array('documento' => '30-71000000-1', 'condicion_iva' => 1),
    'items' => array(array('descripcion' => 'Cartón', 'cantidad' => 2.5, 'precio' => 1000, 'alicuota' => 21)),
));
if ($r['aprobado']) { /* grabar con $r['numero'], $r['cae'], $r['vence'] */ echo OysAfip\FacturaHtml::render($datosImpresion); }
```

## Uso desde cualquier sistema (bajo nivel)

```php
require 'afip_fe/afip.php';
use OysAfip\Afip; use OysAfip\Comprobante;

$afip = new Afip(require '/ruta/privada/config_afip.php');
$c = new Comprobante();
$c->tipo = Comprobante::FC_A;   // o Comprobante::tipoAfip('FC', 'A', $esMiPyme)
$c->ptoVta = 3;
$c->fecha = date('Y-m-d');
$c->receptor('30-71000000-1');  // CUIT, DNI o vacío (consumidor final)
$c->condicionIvaReceptor = Comprobante::IVA_RESPONSABLE_INSCRIPTO;
$c->agregarIva(21, 1000.00, 210.00);
$c->impNeto = 1000.00;
$c->totalizar();
$c->impTotal = 1210.00;
$r = $afip->autorizar($c);       // ['aprobado','numero','cae','vence','observaciones','errores']
$qr = $afip->urlQr($c, $r['cae']);
```

## Uso con el esquema O&S (subdiario)

```php
$ad = new OysAfip\OysSubdiario($pdo, $afip, 1 /* empresa = sucursal */, array(
    'registro' => '/ruta/privada/autorizaciones.log',
));
$pendientes = $ad->pendientes();
$r = $ad->aprobar(array('tipo' => 'FC', 'letra' => 'A', 'prefijo' => 3, 'comprobante' => 101490,
                        'cliente' => 73, 'idx_registro' => '2026052617053267'));
```

`aprobar()` hace los mismos pasos que `CAprobar_Comprobante_` del webservice:

1. Busca el comprobante en el subdiario.
2. Lo arma con `iva`, `tributo` y `equivalencias_afip`.
3. Controla la correlatividad con ARCA, si se activa la opción; en producción está apagada (`CbteCorrelativo = N`).
4. Pide el CAE.
5. Graba en el subdiario `fe_ccae`, `fe_nrocbte`, `fe_ntipoafip`, `fe_dfecha`, `fe_dvtocae` y `fe_mobservaciones`.
6. Pasa el número provisorio al número de ARCA en el subdiario y en las tablas de `fe_actualizacion`, en una sola transacción.

## Factura nueva desde una pantalla web (sin grabarla antes)

`armar($fila, array('iva' => $filasIva, 'tributo' => $filasTributo))` arma el comprobante con los renglones en memoria.
Así la web pide el CAE primero y graba solo si ARCA aprueba. Lo usan los facturadores de Reciclados (`web/factura_lib.php`).

```php
$calc = OysAfip\CalculoComprobante::calcular($renglones, $percepciones);   // renglones: cantidad, precio, alicuota
$cb = $ad->armar($filaSubdiario, array('iva' => $filasIva, 'tributo' => $filasTributo));
$r = $afip->autorizar($cb, $pdo);   // si $r['aprobado'], se graba con $r['numero'], $r['cae'], $r['vence']
```

Si el comprobante asociado de una nota todavía no tiene `fe_ntipoafip`, se toma `tipoafip_oys` o se calcula por tipo y letra.

## ABM de equivalencias de IVA (reutilizable)

Sistema solo nube (por ejemplo, Oeste Parts), grabando directo:

```php
require 'afip_fe/afip.php';
use OysAfip\EquivalenciasIva; use OysAfip\EquivalenciasIvaVista;
$abm = new EquivalenciasIva($pdo);          // $pdo = base MySQL del sistema
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    list($errores, $mensaje) = EquivalenciasIvaVista::procesar($abm, $_POST);
}
echo EquivalenciasIvaVista::html($abm, array('url' => 'equivalencias.php', 'csrf' => csrf_campo(),
    'editar' => isset($_GET['editar']) ? $_GET['editar'] : '', 'nuevo' => isset($_GET['nuevo']),
    'en_uso' => $codigosDeIvaUsadosPorLosClientes));
```

Sistema con SQL Server y cola (Reciclados): se pasa un callback que encola. Ver `web/paginas/equivalencias.php`.

```php
$abm = new EquivalenciasIva(db(), function ($datos, $accion, $ref) { encolar('equivalencia_iva', $accion, $clave, $ref, $datos); });
```

- Códigos del sistema O&S (`cliente.catg_iva`): 1 Responsable Inscripto, 2 Responsable No Inscripto, 3 Monotributo, 4 Exento, 5 Consumidor Final. Para otro sistema se pasan con la opción `codigos_sistema`.
- Equivalencias sugeridas: 1 → 1, 3 → 6, 4 → 4 y 5 → 5. El 2 (RNI) ya no existe en ARCA y queda sin sugerencia.
- La lista de condiciones de ARCA viene cargada y se puede refrescar con `EquivalenciasIva::condicionesDesdeArca($afip)`.

## Diferencias con el webservice C#

- **Condición de IVA del receptor (RG 5616).** Se busca siempre por `subdiario.iva_cliente` en `equivalencias_afip` (`tabla = 'Equivaliencia'`, `codigooys = iva_cliente`), de donde sale `codigoafip`. Si el código no está cargado, el comprobante no se envía y queda el aviso en `fe_mobservaciones`: no se supone ninguna condición. El webservice de 09/2026 busca con la columna número 21 del registro y no por nombre.
- **Ticket de acceso guardado.** El C# pedía uno nuevo en cada factura y chocaba con "El CEE ya posee un TA valido".
- **Corte de comunicación.** Si se corta la conexión después de que ARCA aprobó, consulta con `FECompConsultar` y graba ese CAE. No pide otro número.
- **Bloqueo por punto de venta y tipo.** Dos usuarios no pueden tomar el mismo número.
- **Consultas y grabación.** Todas las consultas son parametrizadas, sin SQL concatenado. Las grabaciones van en una transacción y actualizan `actualizacion`, así la sincronización con la nube ve el cambio.
- **Comprobante asociado ambiguo.** Si el número existe como FC común y como FCE, elige el de la misma familia que la nota.
- **Consumidor final sin documento.** Va como DocTipo 99 y DocNro 0; el C# mandaba DNI 1.
- **MTXCA.** No está: Reciclados usa WSFE.

## Puesta en marcha

1. **Certificado propio para la web.** ARCA da un solo TA por certificado. Si la web y el webservice del escritorio usan el mismo, se traban entre sí con "ya posee un TA valido" durante hasta 12 horas. Por eso conviene generar en ARCA otro certificado para el mismo CUIT y asociarlo al servicio wsfe.
2. Certificado: convertir el `.pfx` actual a `.crt` + `.key`, o probar el `.pfx` directo. Guardarlo **fuera de `public_html`**.
3. Copiar `config_afip.ejemplo.php` como `config_afip.php`, también fuera de la parte pública.
4. Correr `herramientas/probar_conexion.php` primero en homologación y después en producción.
5. Cargar en `equivalencias_afip` (tabla `Equivaliencia`) todos los códigos de `iva_cliente` que usa el sistema. Hoy solo está el 1 → 1.
