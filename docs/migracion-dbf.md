# Migración de datos DBF (VFP) → MySQL

El migrador lee la carpeta de datos del sistema VFP (los `.dbf`, sus memos `.fpt` y `datos.dbc`) y carga las tablas del
facturador. Está escrito en PHP (`src/Modules/Migracion/`), sin librerías: el mismo código corre por consola o desde la web.

## Qué migra (etapa 1)

| MySQL | DBF | Notas |
|---|---|---|
| `sucursales` | `sucursales.dbf` | Punto de venta y numeradores (última FC A/B/E, NC, ND, recibo, remito). No se traen los datos de SMTP (contraseña incluida) |
| `rubros`, `subrubros`, `provincias`, `bancos`, `cotizacion_dolar`, `vendedores`, `tasas_iva`, `condicionpago`, `transportes`, `forma_de_pagos`, `unidad_venta` | mismo nombre | 1 a 1 |
| `clientes`, `articulos`, `articulos_precios` | mismo nombre | Todos los campos del VFP (los de producción textil quedan guardados pero no se muestran) |
| `parametros` (fila 1) | `parametros.dbf` | Razón social, CUIT, IVA, textos impresos, último cliente / recibo. Si `empresa` no tiene un nombre, se toma de `fe_configuracion` |
| `usuarios` | `cla_usuarios.dbf` | Se crean **desactivados**, con contraseña al azar (la del VFP no se migra). Supervisor → Administrador, el resto → Operador. Nombres de menos de 3 letras no se migran; los repetidos en mayúsculas/minúsculas quedan como uno |

Los comprobantes (`subdiario`, `iva`, `DET`), la cuenta corriente y la configuración de factura electrónica se migran en sus etapas.

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
