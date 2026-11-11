'use strict';
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { openDb } = require('../src/db');
const { createServer } = require('../src/server');
const F = require('../src/facturacion');

let server; let base;
before(async () => {
  server = createServer(openDb(':memory:'));
  await new Promise((r) => server.listen(0, r));
  base = `http://localhost:${server.address().port}/api`;
});
after(() => server.close());

async function call(method, path, body) {
  const res = await fetch(base + path, {
    method, headers: { 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined,
  });
  return { status: res.status, data: await res.json() };
}

test('validación de CUIT', () => {
  assert.equal(F.cuitValido('20-12345678-6'), true);
  assert.equal(F.cuitValido('20123456787'), false);
  assert.equal(F.cuitValido('123'), false);
});

test('tipo de comprobante', () => {
  assert.equal(F.tipoComprobante('Responsable Inscripto', 'Responsable Inscripto'), 'A');
  assert.equal(F.tipoComprobante('Responsable Inscripto', 'Consumidor Final'), 'B');
  assert.equal(F.tipoComprobante('Monotributo', 'Responsable Inscripto'), 'C');
});

test('flujo completo: empresa, cliente, producto, factura A y B', async () => {
  let r = await call('PUT', '/empresa', { razon_social: 'ACME SA', cuit: '20123456786', condicion_iva: 'Responsable Inscripto', punto_venta: 3 });
  assert.equal(r.status, 200);

  r = await call('POST', '/clientes', { nombre: 'Cliente RI', condicion_iva: 'Responsable Inscripto' });
  assert.equal(r.status, 400, 'RI sin CUIT debe fallar');

  const ri = (await call('POST', '/clientes', { nombre: 'Cliente RI', cuit: '20-12345678-6', condicion_iva: 'Responsable Inscripto' })).data;
  const cf = (await call('POST', '/clientes', { nombre: 'Consumidor', condicion_iva: 'Consumidor Final' })).data;
  const prod = (await call('POST', '/productos', { descripcion: 'Servicio', precio: 1000, alicuota_iva: 21 })).data;

  r = await call('POST', '/facturas', {
    cliente_id: ri.id, fecha: '2026-11-01',
    items: [
      { producto_id: prod.id, descripcion: 'Servicio', cantidad: 2, precio_unitario: 1000, alicuota_iva: 21 },
      { descripcion: 'Libro', cantidad: 1, precio_unitario: 500, alicuota_iva: 10.5 },
    ],
  });
  assert.equal(r.status, 201);
  assert.equal(r.data.tipo, 'A');
  assert.equal(r.data.punto_venta, 3);
  assert.equal(r.data.numero, 1);
  assert.equal(r.data.neto, 2500);
  assert.equal(r.data.iva, 472.5);
  assert.equal(r.data.total, 2972.5);
  assert.equal(r.data.iva_por_alicuota.length, 2);

  const b1 = (await call('POST', '/facturas', { cliente_id: cf.id, items: [{ descripcion: 'X', cantidad: 1, precio_unitario: 100 }] })).data;
  const b2 = (await call('POST', '/facturas', { cliente_id: cf.id, items: [{ descripcion: 'Y', cantidad: 3, precio_unitario: 10 }] })).data;
  assert.equal(b1.tipo, 'B'); assert.equal(b1.numero, 1); assert.equal(b2.numero, 2);

  r = await call('POST', '/facturas', { cliente_id: cf.id, items: [] });
  assert.equal(r.status, 400);

  r = await call('PATCH', `/facturas/${b1.id}/estado`, { estado: 'anulada' });
  assert.equal(r.data.estado, 'anulada');
  r = await call('PATCH', `/facturas/${b1.id}/estado`, { estado: 'pagada' });
  assert.equal(r.status, 400);

  r = await call('GET', '/facturas?estado=pendiente');
  assert.equal(r.data.length, 2);

  // la baja de un cliente no rompe sus facturas
  await call('DELETE', `/clientes/${ri.id}`);
  assert.equal((await call('GET', '/clientes')).data.length, 1);
  assert.equal((await call('GET', '/facturas')).data.length, 3);

  r = await call('GET', '/resumen');
  assert.equal(r.status, 200);
  assert.equal(r.data.pendiente_cobro.cantidad, 2);
});

test('emisor monotributista emite factura C sin IVA', async () => {
  await call('PUT', '/empresa', { razon_social: 'Mono', condicion_iva: 'Monotributo', punto_venta: 1 });
  const c = (await call('POST', '/clientes', { nombre: 'Otro' })).data;
  const f = (await call('POST', '/facturas', { cliente_id: c.id, items: [{ descripcion: 'Z', cantidad: 1, precio_unitario: 100, alicuota_iva: 21 }] })).data;
  assert.equal(f.tipo, 'C'); assert.equal(f.iva, 0); assert.equal(f.total, 100);
});
