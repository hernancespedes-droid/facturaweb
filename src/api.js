'use strict';
const { tx } = require('./db');
const F = require('./facturacion');
const { ValidationError } = F;

const plain = (row) => (row ? { ...row } : row);
const rows = (list) => list.map(plain);

class NotFound extends Error { constructor(m = 'No encontrado') { super(m); this.status = 404; } }

function str(v, max = 200) { return String(v ?? '').trim().slice(0, max); }

function validarCliente(b) {
  const c = {
    nombre: str(b.nombre),
    cuit: str(b.cuit, 13).replace(/\D/g, ''),
    condicion_iva: str(b.condicion_iva) || 'Consumidor Final',
    domicilio: str(b.domicilio),
    email: str(b.email),
    telefono: str(b.telefono, 50),
  };
  if (!c.nombre) throw new ValidationError('El nombre es obligatorio');
  if (!F.CONDICIONES_IVA.includes(c.condicion_iva)) throw new ValidationError('Condición de IVA inválida');
  if (c.cuit && !F.cuitValido(c.cuit)) throw new ValidationError('CUIT inválido');
  if (c.condicion_iva === 'Responsable Inscripto' && !c.cuit) {
    throw new ValidationError('Un Responsable Inscripto requiere CUIT');
  }
  return c;
}

function validarProducto(b) {
  const p = {
    codigo: str(b.codigo, 50),
    descripcion: str(b.descripcion),
    precio: Number(b.precio),
    alicuota_iva: Number(b.alicuota_iva ?? 21),
  };
  if (!p.descripcion) throw new ValidationError('La descripción es obligatoria');
  if (!(p.precio >= 0)) throw new ValidationError('Precio inválido');
  if (!F.ALICUOTAS.includes(p.alicuota_iva)) throw new ValidationError('Alícuota de IVA inválida');
  p.precio = F.round2(p.precio);
  return p;
}

function createApi(db) {
  const routes = [];
  const add = (method, pattern, handler) => {
    const keys = [];
    const re = new RegExp('^' + pattern.replace(/:(\w+)/g, (_, k) => { keys.push(k); return '(\\d+)'; }) + '$');
    routes.push({ method, re, keys, handler });
  };

  const getEmpresa = () => plain(db.prepare('SELECT * FROM empresa WHERE id = 1').get());

  // ---------- Empresa ----------
  add('GET', '/api/empresa', () => getEmpresa());
  add('PUT', '/api/empresa', ({ body }) => {
    const e = {
      razon_social: str(body.razon_social) || 'Mi Empresa',
      cuit: str(body.cuit, 13).replace(/\D/g, ''),
      domicilio: str(body.domicilio),
      condicion_iva: str(body.condicion_iva) || 'Responsable Inscripto',
      inicio_actividades: str(body.inicio_actividades, 10),
      punto_venta: Number(body.punto_venta) || 1,
    };
    if (!['Responsable Inscripto', 'Monotributo', 'Exento'].includes(e.condicion_iva)) {
      throw new ValidationError('Condición de IVA del emisor inválida');
    }
    if (e.cuit && !F.cuitValido(e.cuit)) throw new ValidationError('CUIT inválido');
    if (!(e.punto_venta >= 1 && e.punto_venta <= 99999)) throw new ValidationError('Punto de venta inválido');
    db.prepare(`UPDATE empresa SET razon_social=?, cuit=?, domicilio=?, condicion_iva=?,
      inicio_actividades=?, punto_venta=? WHERE id=1`)
      .run(e.razon_social, e.cuit, e.domicilio, e.condicion_iva, e.inicio_actividades, e.punto_venta);
    return getEmpresa();
  });

  add('GET', '/api/config', () => ({ alicuotas: F.ALICUOTAS, condiciones_iva: F.CONDICIONES_IVA }));

  // ---------- Clientes ----------
  add('GET', '/api/clientes', ({ query }) => {
    const q = `%${query.get('q') || ''}%`;
    return rows(db.prepare(`SELECT * FROM clientes WHERE activo = 1
      AND (nombre LIKE ? OR cuit LIKE ?) ORDER BY nombre`).all(q, q));
  });
  add('GET', '/api/clientes/:id', ({ params }) => {
    const c = db.prepare('SELECT * FROM clientes WHERE id = ?').get(params.id);
    if (!c) throw new NotFound('Cliente no encontrado');
    return plain(c);
  });
  add('POST', '/api/clientes', ({ body }) => {
    const c = validarCliente(body);
    const r = db.prepare(`INSERT INTO clientes (nombre, cuit, condicion_iva, domicilio, email, telefono)
      VALUES (?,?,?,?,?,?)`).run(c.nombre, c.cuit, c.condicion_iva, c.domicilio, c.email, c.telefono);
    return plain(db.prepare('SELECT * FROM clientes WHERE id = ?').get(r.lastInsertRowid));
  });
  add('PUT', '/api/clientes/:id', ({ params, body }) => {
    const c = validarCliente(body);
    const r = db.prepare(`UPDATE clientes SET nombre=?, cuit=?, condicion_iva=?, domicilio=?, email=?, telefono=?
      WHERE id=?`).run(c.nombre, c.cuit, c.condicion_iva, c.domicilio, c.email, c.telefono, params.id);
    if (!r.changes) throw new NotFound('Cliente no encontrado');
    return plain(db.prepare('SELECT * FROM clientes WHERE id = ?').get(params.id));
  });
  add('DELETE', '/api/clientes/:id', ({ params }) => {
    // baja lógica: las facturas emitidas siguen referenciando al cliente
    const r = db.prepare('UPDATE clientes SET activo = 0 WHERE id = ?').run(params.id);
    if (!r.changes) throw new NotFound('Cliente no encontrado');
    return { ok: true };
  });

  // ---------- Productos ----------
  add('GET', '/api/productos', ({ query }) => {
    const q = `%${query.get('q') || ''}%`;
    return rows(db.prepare(`SELECT * FROM productos WHERE activo = 1
      AND (descripcion LIKE ? OR codigo LIKE ?) ORDER BY descripcion`).all(q, q));
  });
  add('POST', '/api/productos', ({ body }) => {
    const p = validarProducto(body);
    const r = db.prepare('INSERT INTO productos (codigo, descripcion, precio, alicuota_iva) VALUES (?,?,?,?)')
      .run(p.codigo, p.descripcion, p.precio, p.alicuota_iva);
    return plain(db.prepare('SELECT * FROM productos WHERE id = ?').get(r.lastInsertRowid));
  });
  add('PUT', '/api/productos/:id', ({ params, body }) => {
    const p = validarProducto(body);
    const r = db.prepare('UPDATE productos SET codigo=?, descripcion=?, precio=?, alicuota_iva=? WHERE id=?')
      .run(p.codigo, p.descripcion, p.precio, p.alicuota_iva, params.id);
    if (!r.changes) throw new NotFound('Producto no encontrado');
    return plain(db.prepare('SELECT * FROM productos WHERE id = ?').get(params.id));
  });
  add('DELETE', '/api/productos/:id', ({ params }) => {
    const r = db.prepare('UPDATE productos SET activo = 0 WHERE id = ?').run(params.id);
    if (!r.changes) throw new NotFound('Producto no encontrado');
    return { ok: true };
  });

  // ---------- Facturas ----------
  const getFactura = (id) => {
    const f = db.prepare('SELECT * FROM facturas WHERE id = ?').get(id);
    if (!f) throw new NotFound('Factura no encontrada');
    const items = rows(db.prepare('SELECT * FROM factura_items WHERE factura_id = ? ORDER BY id').all(id));
    return { ...f, items, iva_por_alicuota: F.ivaPorAlicuota(items), empresa: getEmpresa() };
  };

  add('GET', '/api/facturas', ({ query }) => {
    const where = [];
    const args = [];
    if (query.get('desde')) { where.push('fecha >= ?'); args.push(query.get('desde')); }
    if (query.get('hasta')) { where.push('fecha <= ?'); args.push(query.get('hasta')); }
    if (query.get('estado')) { where.push('estado = ?'); args.push(query.get('estado')); }
    if (query.get('cliente_id')) { where.push('cliente_id = ?'); args.push(Number(query.get('cliente_id'))); }
    if (query.get('q')) { where.push('cliente_nombre LIKE ?'); args.push(`%${query.get('q')}%`); }
    const sql = `SELECT id, tipo, punto_venta, numero, fecha, vencimiento, cliente_id, cliente_nombre,
      neto, iva, total, estado FROM facturas ${where.length ? 'WHERE ' + where.join(' AND ') : ''}
      ORDER BY fecha DESC, id DESC LIMIT 500`;
    return rows(db.prepare(sql).all(...args));
  });

  add('GET', '/api/facturas/:id', ({ params }) => getFactura(params.id));

  add('POST', '/api/facturas', ({ body }) => {
    const id = tx(db, () => {
      const empresa = getEmpresa();
      const cliente = db.prepare('SELECT * FROM clientes WHERE id = ? AND activo = 1').get(Number(body.cliente_id));
      if (!cliente) throw new ValidationError('Seleccioná un cliente válido');
      const tipo = F.tipoComprobante(empresa.condicion_iva, cliente.condicion_iva);
      const fecha = /^\d{4}-\d{2}-\d{2}$/.test(body.fecha || '') ? body.fecha : new Date().toISOString().slice(0, 10);
      const venc = /^\d{4}-\d{2}-\d{2}$/.test(body.vencimiento || '') ? body.vencimiento : null;
      if (venc && venc < fecha) throw new ValidationError('El vencimiento no puede ser anterior a la fecha');
      const calc = F.calcularItems(body.items, tipo);
      const pv = empresa.punto_venta;
      const numero = db.prepare('SELECT COALESCE(MAX(numero), 0) + 1 AS n FROM facturas WHERE tipo = ? AND punto_venta = ?')
        .get(tipo, pv).n;
      const r = db.prepare(`INSERT INTO facturas (tipo, punto_venta, numero, fecha, vencimiento, cliente_id,
        cliente_nombre, cliente_cuit, cliente_condicion_iva, cliente_domicilio, neto, iva, total, observaciones)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)`).run(
        tipo, pv, numero, fecha, venc, cliente.id, cliente.nombre, cliente.cuit, cliente.condicion_iva,
        cliente.domicilio, calc.neto, calc.iva, calc.total, str(body.observaciones, 1000));
      const fid = r.lastInsertRowid;
      const ins = db.prepare(`INSERT INTO factura_items (factura_id, producto_id, descripcion, cantidad,
        precio_unitario, alicuota_iva, neto, iva, total) VALUES (?,?,?,?,?,?,?,?,?)`);
      for (const it of calc.items) {
        ins.run(fid, it.producto_id, it.descripcion, it.cantidad, it.precio_unitario, it.alicuota_iva, it.neto, it.iva, it.total);
      }
      return fid;
    });
    return getFactura(id);
  });

  add('PATCH', '/api/facturas/:id/estado', ({ params, body }) => {
    const estado = body.estado;
    if (!['pendiente', 'pagada', 'anulada'].includes(estado)) throw new ValidationError('Estado inválido');
    const f = db.prepare('SELECT estado FROM facturas WHERE id = ?').get(params.id);
    if (!f) throw new NotFound('Factura no encontrada');
    if (f.estado === 'anulada') throw new ValidationError('Una factura anulada no puede cambiar de estado');
    db.prepare('UPDATE facturas SET estado = ? WHERE id = ?').run(estado, params.id);
    return getFactura(params.id);
  });

  // ---------- Resumen / tablero ----------
  add('GET', '/api/resumen', () => {
    const mes = new Date().toISOString().slice(0, 7);
    const one = (sql, ...a) => plain(db.prepare(sql).get(...a));
    return {
      mes,
      facturado_mes: one(`SELECT COUNT(*) AS cantidad, COALESCE(SUM(total),0) AS total FROM facturas
        WHERE estado != 'anulada' AND substr(fecha,1,7) = ?`, mes),
      pendiente_cobro: one(`SELECT COUNT(*) AS cantidad, COALESCE(SUM(total),0) AS total FROM facturas
        WHERE estado = 'pendiente'`),
      vencidas: one(`SELECT COUNT(*) AS cantidad, COALESCE(SUM(total),0) AS total FROM facturas
        WHERE estado = 'pendiente' AND vencimiento IS NOT NULL AND vencimiento < date('now')`),
      clientes: one('SELECT COUNT(*) AS cantidad FROM clientes WHERE activo = 1').cantidad,
      por_mes: rows(db.prepare(`SELECT substr(fecha,1,7) AS mes, COUNT(*) AS cantidad, SUM(total) AS total
        FROM facturas WHERE estado != 'anulada' GROUP BY mes ORDER BY mes DESC LIMIT 12`).all()).reverse(),
      top_clientes: rows(db.prepare(`SELECT cliente_nombre AS nombre, SUM(total) AS total, COUNT(*) AS cantidad
        FROM facturas WHERE estado != 'anulada' GROUP BY cliente_id ORDER BY total DESC LIMIT 5`).all()),
    };
  });

  return async function handle(method, pathname, query, body) {
    for (const r of routes) {
      if (r.method !== method) continue;
      const m = pathname.match(r.re);
      if (!m) continue;
      const params = Object.fromEntries(r.keys.map((k, i) => [k, Number(m[i + 1])]));
      return r.handler({ params, query, body: body || {} });
    }
    throw new NotFound('Ruta inexistente');
  };
}

module.exports = { createApi };
