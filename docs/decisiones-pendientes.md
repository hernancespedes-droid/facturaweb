# Decisiones que tomó Claude y que Hernán puede cambiar

Cada una está marcada en el código con `DECISIÓN D-xx (Claude, a confirmar por Hernán)`. Buscarla con
`grep -rn "D-07" src public database` (cambiar el número). Las tres primeras se cambian **sin tocar el código**, en
*Sistema → Configuración*.

| ID | Qué decidió Claude | Dónde está | Cómo se cambia |
|----|--------------------|------------|----------------|
| D-01 | Una nota de crédito no puede superar lo que queda por acreditar de su factura (el VFP lo permitía). | `ComprobantesService::armar` | Configuración → `comprobantes.nc_limite_factura` (destildar = sin control) |
| D-02 | Un cliente con límite de crédito 0 no tiene control de crédito (el VFP pedía autorización de un supervisor). | `ComprobantesService::armar` | Configuración → `comprobantes.credito_cero_sin_limite` |
| D-03 | Solo hay descuento general (%); no hay descuento por renglón (OYS no lo usa). | `Calculadora` | Aplicar el % de cada renglón antes del general, en el bucle de la Calculadora |
| D-04 | Un solo medio de pago por comprobante; los pagos mixtos y cheques de terceros van con cobranzas (etapa 4). | `ComprobantesService` (pagos) | Etapa 4 |
| D-05 | No hay anulación de comprobantes: con CAE se cancela con nota de crédito; los de prueba se borran juntos desde Sistema → ARCA. | `ComprobantesService` | Agregar una acción que marque `subdiario.estado = 'A'` y ponga en cero su `ctact` |
| D-06 | La fecha del comprobante es siempre hoy (ARCA aceptaría hasta 5 días antes en productos y 10 en servicios). | `ComprobantesService::armar` | Agregar el campo fecha y validar contra el último comprobante del PV y tipo |
| D-07 | Alícuota 0 = exento (sin fila de IVA); ARCA distingue "exento" de "gravado al 0 %". | `Calculadora` | Agregar el caso "gravado 0 %" (IVA Id 3) |
| D-08 | En B y C el IVA se calcula por grupo de alícuota (total / (1 + alícuota)), no renglón por renglón. | `Calculadora` | Cambiar el cálculo del bloque B/C |
| D-09 | La forma de pago arranca en cuenta corriente, como el VFP, aunque el cliente tenga contado. | `public/assets/comprobante.js` | `formaPago()` |
| D-10 | Hasta 2.000 letras por descripción de renglón (en OYS hubo hasta 3.900). | `ComprobantesService::armar` | Cambiar el 2000 (y la columna, que es TEXT) |
| D-11 | Concepto por omisión de la factura electrónica: productos (1). El VFP mandaba siempre 1. | `ComprobantesService::armar` | Configuración → `comprobantes.concepto_defecto` (1 productos, 2 servicios, 3 ambos) |
| D-12 | Todo comprobante que no es de producción (interno, simulado, homologación) queda marcado `es_prueba` y el Master puede borrarlos juntos; el numerador vuelve atrás. | `ComprobantesService::emitirConArca`, `ArcaController::borrarPruebas` | Cambiar la regla de `es_prueba` en `grabar()` |
| D-13 | Si ARCA no contesta no se reintenta sola ni se pide otro CAE: queda "pendiente" y se revisa en Sistema → ARCA. | `ComprobantesService::emitirConArca` | Agregar una consulta a `FECompConsultar` para resolver sola las pendientes |
| D-14 | El ARCA simulado rechaza todo total que termina en ,13 (para poder probar un rechazo). | `ArcaSimulador::rechazaPorTotal` | Cambiar o sacar esa función |
| D-17 | Al leer el DUN-14 de un bulto, la cantidad del renglón se completa con las «unidades por bulto» del artículo (el precio sigue siendo por unidad). | `ComprobantesController::buscarArticulo`, `comprobante.js` (`aplicar`) | Sacar el campo `cantidad` de la respuesta |
| D-18 | El subdiario de IVA ventas lista solo comprobantes vigentes y no de prueba; un tilde incluye los de prueba. | `Listados/IvaVentas` | Cambiar el filtro por omisión |
| D-19 | Stock: siempre se puede facturar sin stock (avisa); las facturas descuentan del depósito principal; la nota de crédito repone (solo renglones con artículo); la nota de débito no mueve. | `Stock/StockService`, `ComprobantesService::grabar` | `StockService::porComprobante` y `advertencias` |
| D-20 | La sincronización del agente es solo VFP → web y solo de comprobantes (con IVA, renglones, caja y cuenta corriente); no actualiza comprobantes ya cargados. | `MigradorVfp::sumarComprobantes`, `Agente/ApiController` | Ampliar el modo incremental a clientes y artículos |
| D-21 | **Pedido de Hernán (12/11):** el agente también sube clientes y artículos del VFP. Mientras convivan, el VFP manda: el cliente/artículo nuevo se suma y el existente se **actualiza por código** con las columnas que trae el VFP; lo que agrega la web (cliente desactivado, EAN, SKU, bultos, control de stock) no se pisa y nada se borra. Riesgo: un código creado en la web que el VFP use después para otra cosa se pisaría. | `MigradorVfp::sumarMaestros`, `POST /api/v1/vfp/maestros` | Quitar el envío de maestros del agente |
| D-15 | (reservado) | | |
| D-16 | **Decisión de Hernán:** un receptor monotributista lleva factura A. | `Tipos` | — |

Mientras estas decisiones no se confirmen, el sistema se comporta como dice la tabla.
