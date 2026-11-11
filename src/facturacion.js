'use strict';
// Reglas de negocio de facturación (Argentina).

const ALICUOTAS = [0, 2.5, 5, 10.5, 21, 27];
const CONDICIONES_IVA = [
  'Responsable Inscripto',
  'Monotributo',
  'Exento',
  'Consumidor Final',
];

const round2 = (n) => Math.round((Number(n) + Number.EPSILON) * 100) / 100;

class ValidationError extends Error {
  constructor(msg) { super(msg); this.status = 400; }
}

/** Determina el tipo de comprobante según emisor y receptor. */
function tipoComprobante(condEmisor, condReceptor) {
  if (condEmisor !== 'Responsable Inscripto') return 'C';
  return condReceptor === 'Responsable Inscripto' ? 'A' : 'B';
}

/** Calcula importes de cada ítem y los totales. Los precios son netos. */
function calcularItems(items, tipo) {
  if (!Array.isArray(items) || items.length === 0) {
    throw new ValidationError('La factura debe tener al menos un ítem');
  }
  const out = items.map((it, i) => {
    const cantidad = Number(it.cantidad);
    const precio = Number(it.precio_unitario);
    const descripcion = String(it.descripcion ?? '').trim();
    let alicuota = tipo === 'C' ? 0 : Number(it.alicuota_iva ?? 21);
    if (!descripcion) throw new ValidationError(`Ítem ${i + 1}: falta la descripción`);
    if (!(cantidad > 0)) throw new ValidationError(`Ítem ${i + 1}: cantidad inválida`);
    if (!(precio >= 0)) throw new ValidationError(`Ítem ${i + 1}: precio inválido`);
    if (!ALICUOTAS.includes(alicuota)) throw new ValidationError(`Ítem ${i + 1}: alícuota de IVA inválida`);
    const neto = round2(cantidad * precio);
    const iva = round2(neto * alicuota / 100);
    return {
      producto_id: it.producto_id ? Number(it.producto_id) : null,
      descripcion, cantidad, precio_unitario: precio, alicuota_iva: alicuota,
      neto, iva, total: round2(neto + iva),
    };
  });
  const neto = round2(out.reduce((s, x) => s + x.neto, 0));
  const iva = round2(out.reduce((s, x) => s + x.iva, 0));
  return { items: out, neto, iva, total: round2(neto + iva) };
}

/** Resumen de IVA por alícuota (para el pie de la factura A). */
function ivaPorAlicuota(items) {
  const m = new Map();
  for (const it of items) {
    const k = it.alicuota_iva;
    const cur = m.get(k) || { alicuota: k, neto: 0, iva: 0 };
    cur.neto = round2(cur.neto + it.neto);
    cur.iva = round2(cur.iva + it.iva);
    m.set(k, cur);
  }
  return [...m.values()].sort((a, b) => b.alicuota - a.alicuota);
}

/** Valida un CUIT/CUIL argentino (11 dígitos con dígito verificador). */
function cuitValido(cuit) {
  const s = String(cuit || '').replace(/\D/g, '');
  if (s.length !== 11) return false;
  const pesos = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
  const suma = pesos.reduce((acc, p, i) => acc + p * Number(s[i]), 0);
  let dv = 11 - (suma % 11);
  if (dv === 11) dv = 0;
  if (dv === 10) dv = 9;
  return dv === Number(s[10]);
}

module.exports = {
  ALICUOTAS, CONDICIONES_IVA, ValidationError,
  round2, tipoComprobante, calcularItems, ivaPorAlicuota, cuitValido,
};
