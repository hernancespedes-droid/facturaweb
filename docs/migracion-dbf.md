# Migración de datos DBF (VFP) → MySQL

El migrador lee la carpeta de datos del sistema VFP (los `.dbf`, sus memos `.fpt` y `datos.dbc`) y carga las tablas del
facturador. Está escrito en PHP (`src/Modules/Migracion/`), sin librerías: el mismo código corre por consola o desde la web.

## Qué migra (etapas 1 y 2)

| MySQL | DBF | Notas |
|---|---|---|
| `sucursales` | `sucursales.dbf` | Punto de venta y numeradores (última FC A/B/E, NC, ND, recibo, remito). No se traen los datos de SMTP (contraseña incluida) |
| `rubros`, `subrubros`, `provincias`, `bancos`, `cotizacion_dolar`, `vendedores`, `tasas_iva`, `condicionpago`, `transportes`, `forma_de_pagos`, `unidad_venta` | mismo nombre | 1 a 1 |
| `clientes`, `articulos`, `articulos_precios` | mismo nombre | Todos los campos del VFP (los de producción textil quedan guardados pero no se muestran) |
| `parametros` (fila 1) | `parametros.dbf` | Razón social, CUIT, IVA, textos impresos, último cliente / recibo. Si `empresa` no tiene un nombre, se toma de `fe_configuracion` |
| `usuarios` | `cla_usuarios.dbf` | Se crean **desactivados**, con contraseña al azar (la del VFP no se migra). Supervisor → Administrador, el resto → Operador. Nombres de menos de 3 letras no se migran; los repetidos en mayúsculas/minúsculas quedan como uno |

| `equivalencias_afip` | `equivalencias_afip.dbf` | **Se suma** a lo que hay (las de la condición de IVA aprobadas ya vienen cargadas), no se reemplaza |
| `subdiario`, `iva`, `estadistica` (renglones), `caja`, `ctact` | mismo nombre | El histórico completo de comprobantes. Ver abajo |

La configuración de factura electrónica (`fe_parametros`...) se migra en la etapa 3 y los recibos, imputaciones y cheques en la etapa 4.

### Comprobantes: lo que mostraron los datos reales

- **El número interno no es único.** El servicio de ARCA renumera al aprobar: en OYS hay 28 pares de comprobantes con el mismo
  tipo + letra + sucursal + número y distinta fecha, y en 3.281 de 3.371 el número interno es distinto del que dio ARCA (`fe_nrocbte`).
  El VFP los distingue con la **fecha**. Acá: la clave única es tipo + letra + sucursal + número + fecha, y cada fila de `iva`, `estadistica`,
  `caja` y `ctact` apunta a su comprobante con `subdiario_id` (el `fe_clave` del subdiario). Para mostrar un comprobante se usa el número de
  ARCA si tiene CAE (`numero_visible()`), si no el interno.
- **Los `fe_clave` de las tablas hijas no son únicos** en el VFP (hay repetidos): no se migran, los asigna MySQL.
- **Vínculo**: renglones, caja y cta. cte. se unen por tipo + letra + sucursal + número + **fecha**. El IVA no tiene fecha: si el número está
  repetido se elige el comprobante con el mismo neto (56 filas en OYS: conviene controlar el Libro IVA de esos).
- **Huérfanos del VFP** (ya estaban así): 42 renglones, 28 filas de caja, 28 de cta. cte. y 17 de IVA no tienen comprobante en el subdiario; se
  cargan sin vincular y se avisa. 3 comprobantes con IVA no tienen filas de IVA.
- **Descripciones largas**: el texto de un renglón puede tener varios renglones (hasta ~4.000 letras en OYS); se guarda completo.
- Resultado con la copia de OYS: 3.371 comprobantes, 3.377 filas de IVA, 3.821 renglones, 731 de caja y 810 de cta. cte.; cantidades, neto, IVA y
  cta. cte. por tipo **coinciden al centavo** con el DBF. Los 3.371 se imprimen en PDF sin errores (5 s en total).

## Cómo trabaja

- **Nombres de campo**: los DBF guardan nombres de 10 letras; los largos (`ultima_factura_a`, `razon_social`) salen de `datos.dbc`.
  Sin `datos.dbc` se usan los cortos (y un corto que es el comienzo de una sola columna, como `DESCRIPCIO`, se toma como esa).
- **Conversión** según la columna MySQL: textos sin espacios, vacío → sin dato; `0` en CUIT, transporte y proveedor → sin dato;
  fechas y fecha-hora; lógicos → 1/0; textos más largos que la columna se recortan con aviso. Los registros borrados del DBF no se traen.
- **Una sola transacción**: si algo falla no queda nada a medias.
- **No pisa datos**: si una tabla ya tiene registros cargados en la web, se frena salvo que se pida *reemplazar*
  (alícuotas, provincias y unidades, que vienen con valores iniciales, se reemplazan siempre). Los usuarios existentes no se tocan.
- **Avisos**: CUIT con dígito verificador inválido, clientes sin CUIT que no son consumidor final, textos recortados, y códigos
  usados que no están en su tabla. Para provincias, vendedores, condiciones de pago, transportes y rubros el código faltante
  se **agrega** a la tabla como "VENDEDOR 01 (DEL VFP)" para no perderlo (hay que ponerle el nombre real); alícuotas y unidades
  faltantes solo se avisan.
- Cada corrida queda registrada (tabla `migracion_vfp`) y se ve en **Sistema**, con sus avisos.

## Cómo se corre

**Desde la web (sin SSH)**: Sistema → *Migración desde el sistema VFP* (solo el Master). Subir un `.zip` con la carpeta de datos
(los `.dbf` y `.fpt`, `datos.dbc` y `DATOS.DCT`; los `.cdx` no hacen falta). Primero con *Simular* tildado: muestra lo que pasaría sin grabar.
El límite de subida lo pone el hosting (`public/.user.ini` pide 32 MB). Los archivos se borran al terminar.

**Por consola**:

```bash
php bin/migrar-dbf.php /ruta/a/los/dbf --simular
```

```bash
php bin/migrar-dbf.php /ruta/a/los/dbf --reemplazar
```

Opciones: `--tablas=clientes,articulos` (solo esas) y `--sql=migracion.sql` (además deja todas las sentencias en un archivo,
para importarlo con phpMyAdmin en otra base con el mismo esquema).

## Resultado con la copia de OYS (11/11/2026)

519 clientes, 65 artículos, 34 bancos, 28 formas de pago, 24 provincias, 3 alícuotas, 2 rubros, 2 unidades, 1 sucursal (PV 0004)
y 4 usuarios (de 8: `1` y `9` no se migran, `natalia`/`NATALIA` y `guillermo`/`GUILLERMO` quedan como uno). Avisos:

- 3 clientes con CUIT inválido (107, 245 y 287): corregirlos antes de facturarles.
- Vendedor `01` (lo usan los 519 clientes) y provincia `30` (2 clientes) no estaban en sus tablas: se agregaron para ponerles nombre.
- La razón social de Parámetros salió de `fe_configuracion` ("ORGANIZACION & SISTEMAS"): revisarla.
- `DET.DBF` no son ítems de comprobantes: tiene la estructura de `articulos_precios` (artículo, lista, valor) con 906 precios de
  otra instalación (ningún código coincide con los artículos de OYS). No se migra.
