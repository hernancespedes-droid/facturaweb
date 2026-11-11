# FacturaWeb

Sistema web de facturación para Argentina: clientes, productos/servicios y facturas **A, B y C** con cálculo de IVA.

Sin dependencias externas: Node.js (≥ 22.13) con su SQLite integrado (`node:sqlite`) y una interfaz web en HTML/CSS/JS.

## Uso

```bash
npm start
```

Abrí http://localhost:3000. La base se guarda en `data/facturaweb.db` (configurable con `DB_FILE`; puerto con `PORT`).

```bash
npm test
```

## Funcionalidades

- **Mi empresa**: razón social, CUIT, domicilio, condición frente al IVA, inicio de actividades y punto de venta.
- **Clientes**: alta, edición, búsqueda y baja lógica. Valida el dígito verificador del CUIT.
- **Productos**: precio neto y alícuota de IVA (0, 2,5, 5, 10,5, 21 y 27 %).
- **Facturas**:
  - Tipo automático: emisor Responsable Inscripto → **A** a otro RI, **B** al resto; Monotributo/Exento → **C**.
  - Numeración correlativa por tipo y punto de venta (`00001-00000001`).
  - Varios ítems con distintas alícuotas, IVA discriminado por alícuota en la A, precio con IVA incluido en la B
    (con la leyenda de IVA contenido de la Ley 27.743), sin IVA en la C.
  - Se guarda una copia de los datos del cliente al emitir, así editar el cliente no altera comprobantes viejos.
  - Estados: pendiente, pagada, anulada (y "vencida" si pasó el vencimiento).
  - Vista de comprobante lista para imprimir o guardar como PDF.
- **Inicio**: facturado del mes, pendiente de cobro, vencido, facturación por mes y mejores clientes.

## Estructura

```
src/server.js       servidor HTTP + archivos estáticos
src/api.js          rutas REST (/api/...)
src/facturacion.js  reglas de negocio (tipo de comprobante, IVA, CUIT)
src/db.js           esquema SQLite
public/             interfaz web (SPA)
test/               tests con node:test
```

## API

| Método | Ruta | Descripción |
|---|---|---|
| GET/PUT | `/api/empresa` | Datos del emisor |
| GET/POST | `/api/clientes` | Listar (`?q=`) / crear |
| PUT/DELETE | `/api/clientes/:id` | Editar / dar de baja |
| GET/POST | `/api/productos` | Listar (`?q=`) / crear |
| PUT/DELETE | `/api/productos/:id` | Editar / dar de baja |
| GET | `/api/facturas` | Listar (`?desde=&hasta=&estado=&cliente_id=&q=`) |
| POST | `/api/facturas` | Emitir `{cliente_id, fecha, vencimiento, observaciones, items:[{descripcion, cantidad, precio_unitario, alicuota_iva, producto_id?}]}` |
| GET | `/api/facturas/:id` | Comprobante con ítems |
| PATCH | `/api/facturas/:id/estado` | `{estado: "pendiente" \| "pagada" \| "anulada"}` |
| GET | `/api/resumen` | Datos del tablero |

## Pendiente / próximos pasos

- **Factura electrónica ARCA (ex AFIP)**: obtener CAE vía WSAA + WSFEv1 (requiere certificado digital) e imprimir el QR.
  Hoy los comprobantes son internos, sin validez fiscal.
- Notas de crédito/débito (en lugar de anular).
- Usuarios y login.
- Cobros parciales y cuenta corriente por cliente.
