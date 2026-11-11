'use strict';
/* FacturaWeb — interfaz (SPA sin dependencias) */

const $app = document.getElementById('app');
const state = { config: null, empresa: null };

// ---------- utilidades ----------
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const money = (n) => Number(n || 0).toLocaleString('es-AR', { style: 'currency', currency: 'ARS' });
const fdate = (s) => (s ? s.split('-').reverse().join('/') : '');
const today = () => { const d = new Date(); return new Date(d - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10); };
const nroComp = (f) => `${String(f.punto_venta).padStart(5, '0')}-${String(f.numero).padStart(8, '0')}`;
const fmtCuit = (c) => (c && c.length === 11 ? `${c.slice(0, 2)}-${c.slice(2, 10)}-${c.slice(10)}` : c || '');
const round2 = (n) => Math.round((n + Number.EPSILON) * 100) / 100;
const vencida = (f) => f.estado === 'pendiente' && f.vencimiento && f.vencimiento < today();

async function api(path, opts = {}) {
  const res = await fetch('/api' + path, {
    method: opts.method || 'GET',
    headers: opts.body ? { 'Content-Type': 'application/json' } : {},
    body: opts.body ? JSON.stringify(opts.body) : undefined,
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || `Error ${res.status}`);
  return data;
}

let toastTimer;
function toast(msg, error = false) {
  const t = document.getElementById('toast');
  t.textContent = msg; t.className = 'toast no-print' + (error ? ' error' : ''); t.hidden = false;
  clearTimeout(toastTimer); toastTimer = setTimeout(() => (t.hidden = true), 3500);
}

function formData(form) {
  return Object.fromEntries(new FormData(form).entries());
}

function estadoBadge(f) {
  if (vencida(f)) return '<span class="badge vencida">vencida</span>';
  return `<span class="badge ${f.estado}">${f.estado}</span>`;
}

function condOptions(selected, list = state.config.condiciones_iva) {
  return list.map((c) => `<option ${c === selected ? 'selected' : ''}>${esc(c)}</option>`).join('');
}
function alicOptions(selected) {
  return state.config.alicuotas.map((a) => `<option value="${a}" ${Number(selected) === a ? 'selected' : ''}>${String(a).replace('.', ',')}%</option>`).join('');
}

// ---------- vistas ----------
const views = {};

views.inicio = async () => {
  const r = await api('/resumen');
  const max = Math.max(1, ...r.por_mes.map((m) => m.total));
  $app.innerHTML = `
    <h1>Inicio</h1>
    <div class="stats">
      <div class="card stat"><div class="label">Facturado este mes</div><div class="value">${money(r.facturado_mes.total)}</div><div class="sub">${r.facturado_mes.cantidad} comprobantes</div></div>
      <div class="card stat"><div class="label">Pendiente de cobro</div><div class="value">${money(r.pendiente_cobro.total)}</div><div class="sub">${r.pendiente_cobro.cantidad} facturas</div></div>
      <div class="card stat"><div class="label">Vencido</div><div class="value" style="color:var(--danger)">${money(r.vencidas.total)}</div><div class="sub">${r.vencidas.cantidad} facturas</div></div>
      <div class="card stat"><div class="label">Clientes activos</div><div class="value">${r.clientes}</div><div class="sub"><a href="#/clientes">Ver clientes</a></div></div>
    </div>
    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr))">
      <div class="card"><b>Facturación por mes</b>
        ${r.por_mes.length ? `<div class="bars">${r.por_mes.map((m) => `
          <div class="bar" title="${m.mes}: ${money(m.total)}"><span style="height:${(m.total / max) * 100}%"></span>${m.mes.slice(5)}/${m.mes.slice(2, 4)}</div>`).join('')}</div>`
          : '<p class="empty">Todavía no hay facturas.</p>'}
      </div>
      <div class="card"><b>Mejores clientes</b>
        ${r.top_clientes.length ? `<table>${r.top_clientes.map((c) => `<tr><td>${esc(c.nombre)}</td><td class="num">${money(c.total)}</td></tr>`).join('')}</table>`
          : '<p class="empty">Sin datos.</p>'}
      </div>
    </div>
    ${state.empresa.cuit ? '' : `<div class="card">Primero cargá los datos de <a href="#/empresa">tu empresa</a> (razón social, CUIT, condición frente al IVA y punto de venta).</div>`}`;
};

views.facturas = async () => {
  $app.innerHTML = `
    <div class="row spread"><h1>Facturas</h1><a class="btn" href="#/facturas/nueva">+ Nueva factura</a></div>
    <div class="card">
      <form id="filtros" class="row" style="margin-bottom:12px">
        <input name="q" placeholder="Buscar cliente…" style="max-width:220px">
        <label>Desde <input type="date" name="desde"></label>
        <label>Hasta <input type="date" name="hasta"></label>
        <select name="estado" style="max-width:160px">
          <option value="">Todos los estados</option><option>pendiente</option><option>pagada</option><option>anulada</option>
        </select>
      </form>
      <div class="table-wrap"><table>
        <thead><tr><th>Comprobante</th><th>Fecha</th><th>Cliente</th><th>Vence</th><th>Estado</th><th class="num">Total</th></tr></thead>
        <tbody id="lista"></tbody>
      </table></div>
    </div>`;
  const form = document.getElementById('filtros');
  const load = async () => {
    const qs = new URLSearchParams(Object.entries(formData(form)).filter(([, v]) => v)).toString();
    const list = await api('/facturas' + (qs ? '?' + qs : ''));
    document.getElementById('lista').innerHTML = list.length ? list.map((f) => `
      <tr class="clickable" data-id="${f.id}">
        <td><b>${f.tipo}</b> ${nroComp(f)}</td><td>${fdate(f.fecha)}</td><td>${esc(f.cliente_nombre)}</td>
        <td>${fdate(f.vencimiento)}</td><td>${estadoBadge(f)}</td><td class="num">${money(f.total)}</td>
      </tr>`).join('') : '<tr><td colspan="6" class="empty">No hay facturas.</td></tr>';
  };
  form.addEventListener('input', load);
  document.getElementById('lista').addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-id]');
    if (tr) location.hash = '#/facturas/' + tr.dataset.id;
  });
  await load();
};

views.nuevaFactura = async () => {
  const [clientes, productos] = await Promise.all([api('/clientes'), api('/productos')]);
  if (!clientes.length) {
    $app.innerHTML = `<h1>Nueva factura</h1><div class="card">Primero tenés que <a href="#/clientes">dar de alta un cliente</a>.</div>`;
    return;
  }
  const emp = state.empresa;
  const tipoPara = (c) => (emp.condicion_iva !== 'Responsable Inscripto' ? 'C' : c?.condicion_iva === 'Responsable Inscripto' ? 'A' : 'B');
  const items = [{ producto_id: '', descripcion: '', cantidad: 1, precio_unitario: 0, alicuota_iva: 21 }];

  $app.innerHTML = `
    <h1>Nueva factura <span id="tipo" class="badge pendiente"></span></h1>
    <form id="f" class="card">
      <div class="grid">
        <label>Cliente <select name="cliente_id" required>
          <option value="">Seleccioná…</option>
          ${clientes.map((c) => `<option value="${c.id}">${esc(c.nombre)}${c.cuit ? ' — ' + fmtCuit(c.cuit) : ''}</option>`).join('')}
        </select></label>
        <label>Fecha <input type="date" name="fecha" value="${today()}" required></label>
        <label>Vencimiento <input type="date" name="vencimiento"></label>
      </div>
      <p class="muted" id="cliente-info" style="margin:8px 0 0"></p>
      <h3 style="margin-top:20px">Ítems</h3>
      <div class="table-wrap"><table class="items-table">
        <thead><tr><th style="width:22%">Producto</th><th>Descripción</th><th class="num" style="width:90px">Cant.</th>
          <th class="num" style="width:130px">Precio unit. (neto)</th><th style="width:95px" class="col-iva">IVA</th><th class="num" style="width:120px">Subtotal</th><th></th></tr></thead>
        <tbody id="items"></tbody>
      </table></div>
      <button type="button" class="btn btn-ghost btn-sm" id="add-item" style="margin-top:8px">+ Agregar ítem</button>
      <div class="totals" id="totales"></div>
      <label style="margin-top:12px">Observaciones <textarea name="observaciones" rows="2"></textarea></label>
      <div class="row" style="margin-top:16px; justify-content:flex-end">
        <a class="btn btn-ghost" href="#/facturas">Cancelar</a>
        <button class="btn" type="submit">Emitir factura</button>
      </div>
    </form>`;

  const form = document.getElementById('f');
  const tbody = document.getElementById('items');
  const selCliente = form.cliente_id;
  const tipo = () => tipoPara(clientes.find((c) => c.id === Number(selCliente.value)));

  const prodOptions = (sel) => `<option value="">— libre —</option>` +
    productos.map((p) => `<option value="${p.id}" ${Number(sel) === p.id ? 'selected' : ''}>${esc(p.codigo ? p.codigo + ' · ' : '')}${esc(p.descripcion)}</option>`).join('');

  function renderItems() {
    const isC = tipo() === 'C';
    tbody.innerHTML = items.map((it, i) => `
      <tr data-i="${i}">
        <td><select data-k="producto_id">${prodOptions(it.producto_id)}</select></td>
        <td><input data-k="descripcion" value="${esc(it.descripcion)}" required></td>
        <td><input data-k="cantidad" type="number" step="any" min="0.01" value="${it.cantidad}" class="num" required></td>
        <td><input data-k="precio_unitario" type="number" step="0.01" min="0" value="${it.precio_unitario}" required></td>
        <td class="col-iva">${isC ? '<span class="muted">—</span>' : `<select data-k="alicuota_iva">${alicOptions(it.alicuota_iva)}</select>`}</td>
        <td class="num" data-sub></td>
        <td>${items.length > 1 ? `<button type="button" class="btn btn-ghost btn-sm" data-del title="Quitar">✕</button>` : ''}</td>
      </tr>`).join('');
    renderTotals();
  }

  function renderTotals() {
    const t = tipo(); const isC = t === 'C';
    let neto = 0; let iva = 0;
    const porAlic = {};
    items.forEach((it, i) => {
      const n = round2((Number(it.cantidad) || 0) * (Number(it.precio_unitario) || 0));
      const v = isC ? 0 : round2(n * Number(it.alicuota_iva) / 100);
      neto += n; iva += v;
      porAlic[it.alicuota_iva] = (porAlic[it.alicuota_iva] || 0) + v;
      const cell = tbody.querySelector(`tr[data-i="${i}"] [data-sub]`);
      if (cell) cell.textContent = money(n);
    });
    document.getElementById('tipo').textContent = selCliente.value ? `Factura ${t}` : '';
    document.getElementById('totales').innerHTML = `
      <div><span>Subtotal neto</span><span>${money(neto)}</span></div>
      ${isC ? '' : Object.entries(porAlic).filter(([, v]) => v).map(([a, v]) => `<div><span>IVA ${a.replace('.', ',')}%</span><span>${money(v)}</span></div>`).join('')}
      <div class="grand"><span>Total</span><span>${money(round2(neto + iva))}</span></div>`;
  }

  selCliente.addEventListener('change', () => {
    const c = clientes.find((x) => x.id === Number(selCliente.value));
    document.getElementById('cliente-info').textContent = c ? `${c.condicion_iva}${c.domicilio ? ' · ' + c.domicilio : ''}` : '';
    renderItems();
  });
  tbody.addEventListener('input', (e) => {
    const tr = e.target.closest('tr[data-i]'); const k = e.target.dataset.k;
    if (!tr || !k) return;
    const it = items[Number(tr.dataset.i)];
    it[k] = e.target.value;
    if (k === 'producto_id') {
      const p = productos.find((x) => x.id === Number(e.target.value));
      if (p) Object.assign(it, { descripcion: p.descripcion, precio_unitario: p.precio, alicuota_iva: p.alicuota_iva });
      renderItems();
    } else renderTotals();
  });
  tbody.addEventListener('click', (e) => {
    if (!e.target.matches('[data-del]')) return;
    items.splice(Number(e.target.closest('tr').dataset.i), 1); renderItems();
  });
  document.getElementById('add-item').addEventListener('click', () => {
    items.push({ producto_id: '', descripcion: '', cantidad: 1, precio_unitario: 0, alicuota_iva: 21 }); renderItems();
  });
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const d = formData(form);
    try {
      const f = await api('/facturas', { method: 'POST', body: {
        cliente_id: Number(d.cliente_id), fecha: d.fecha, vencimiento: d.vencimiento, observaciones: d.observaciones,
        items: items.map((it) => ({ ...it, cantidad: Number(it.cantidad), precio_unitario: Number(it.precio_unitario), alicuota_iva: Number(it.alicuota_iva) })),
      } });
      toast(`Factura ${f.tipo} ${nroComp(f)} emitida`);
      location.hash = '#/facturas/' + f.id;
    } catch (err) { toast(err.message, true); }
  });
  renderItems();
};

views.verFactura = async (id) => {
  const f = await api('/facturas/' + id);
  const e = f.empresa;
  const discrimina = f.tipo === 'A';
  // En factura B el precio se muestra con IVA incluido; en A neto + IVA discriminado; en C no hay IVA.
  const pu = (it) => (f.tipo === 'B' ? it.precio_unitario * (1 + it.alicuota_iva / 100) : it.precio_unitario);
  const sub = (it) => (f.tipo === 'B' ? it.total : it.neto);
  $app.innerHTML = `
    <div class="row spread no-print" style="margin-bottom:12px">
      <a href="#/facturas">← Volver</a>
      <div class="row">
        ${estadoBadge(f)}
        ${f.estado === 'pendiente' ? '<button class="btn btn-sm" data-estado="pagada">Marcar pagada</button>' : ''}
        ${f.estado === 'pagada' ? '<button class="btn btn-ghost btn-sm" data-estado="pendiente">Marcar pendiente</button>' : ''}
        ${f.estado !== 'anulada' ? '<button class="btn btn-danger btn-sm" data-estado="anulada">Anular</button>' : ''}
        <button class="btn btn-ghost btn-sm" onclick="print()">Imprimir / PDF</button>
      </div>
    </div>
    <div class="invoice">
      <div class="inv-head">
        <div><h2>${esc(e.razon_social)}</h2>${esc(e.domicilio)}<br>${esc(e.condicion_iva)}</div>
        <div class="inv-letter"><b>${f.tipo}</b><small>${f.tipo === 'A' ? 'COD. 01' : f.tipo === 'B' ? 'COD. 06' : 'COD. 11'}</small></div>
        <div><h2>FACTURA</h2>
          N° <b>${nroComp(f)}</b><br>Fecha: ${fdate(f.fecha)}<br>
          CUIT: ${fmtCuit(e.cuit)}${e.inicio_actividades ? `<br>Inicio de actividades: ${fdate(e.inicio_actividades)}` : ''}
        </div>
      </div>
      <div class="inv-client">
        <div><span class="muted">Cliente:</span> <b>${esc(f.cliente_nombre)}</b></div>
        <div><span class="muted">CUIT/DNI:</span> ${fmtCuit(f.cliente_cuit) || '—'}</div>
        <div><span class="muted">Condición IVA:</span> ${esc(f.cliente_condicion_iva)}</div>
        <div><span class="muted">Domicilio:</span> ${esc(f.cliente_domicilio) || '—'}</div>
        ${f.vencimiento ? `<div><span class="muted">Vencimiento:</span> ${fdate(f.vencimiento)}</div>` : ''}
      </div>
      <div class="inv-body table-wrap">
        <table>
          <thead><tr><th>Descripción</th><th class="num">Cant.</th><th class="num">Precio unit.</th>${discrimina ? '<th class="num">IVA</th>' : ''}<th class="num">Subtotal</th></tr></thead>
          <tbody>${f.items.map((it) => `<tr><td>${esc(it.descripcion)}</td><td class="num">${it.cantidad.toLocaleString('es-AR')}</td>
            <td class="num">${money(pu(it))}</td>${discrimina ? `<td class="num">${String(it.alicuota_iva).replace('.', ',')}%</td>` : ''}
            <td class="num">${money(sub(it))}</td></tr>`).join('')}</tbody>
        </table>
      </div>
      <div class="inv-foot">
        <div class="totals">
          ${discrimina ? `<div><span>Importe neto gravado</span><span>${money(f.neto)}</span></div>
            ${f.iva_por_alicuota.filter((a) => a.iva).map((a) => `<div><span>IVA ${String(a.alicuota).replace('.', ',')}%</span><span>${money(a.iva)}</span></div>`).join('')}` : ''}
          <div class="grand"><span>Total</span><span>${money(f.total)}</span></div>
        </div>
        ${f.tipo === 'B' && f.iva ? `<p class="muted" style="font-size:12px">Régimen de Transparencia Fiscal al Consumidor (Ley 27.743): IVA contenido ${money(f.iva)}</p>` : ''}
        ${f.observaciones ? `<p><span class="muted">Observaciones:</span> ${esc(f.observaciones)}</p>` : ''}
        ${f.estado === 'anulada' ? '<p style="color:var(--danger);font-weight:700">COMPROBANTE ANULADO</p>' : ''}
      </div>
    </div>`;
  $app.querySelectorAll('[data-estado]').forEach((b) => b.addEventListener('click', async () => {
    const estado = b.dataset.estado;
    if (estado === 'anulada' && !confirm('¿Anular esta factura? No se puede deshacer.')) return;
    try { await api(`/facturas/${id}/estado`, { method: 'PATCH', body: { estado } }); toast('Estado actualizado'); views.verFactura(id); }
    catch (err) { toast(err.message, true); }
  }));
};

/** Vista genérica de ABM (clientes / productos). */
function abm({ titulo, recurso, columnas, campos, vacio }) {
  return async () => {
    $app.innerHTML = `
      <div class="row spread"><h1>${titulo}</h1><button class="btn" id="nuevo">+ Nuevo</button></div>
      <div class="card">
        <input id="buscar" placeholder="Buscar…" style="max-width:300px; margin-bottom:12px">
        <div class="table-wrap"><table>
          <thead><tr>${columnas.map((c) => `<th class="${c.num ? 'num' : ''}">${c.t}</th>`).join('')}<th></th></tr></thead>
          <tbody id="lista"></tbody>
        </table></div>
      </div>
      <dialog id="dlg"><form id="frm" method="dialog">
        <h3 id="dlg-t" style="margin-top:0"></h3>
        <div class="grid">${campos.map((c) => `<label>${c.t}${c.input()}</label>`).join('')}</div>
        <div class="row" style="justify-content:flex-end; margin-top:16px">
          <button type="button" class="btn btn-ghost" id="cancelar">Cancelar</button><button class="btn" type="submit">Guardar</button>
        </div>
      </form></dialog>`;
    let data = [];
    let editId = null;
    const dlg = document.getElementById('dlg');
    const frm = document.getElementById('frm');
    const load = async () => {
      data = await api(`/${recurso}?q=${encodeURIComponent(document.getElementById('buscar').value)}`);
      document.getElementById('lista').innerHTML = data.length ? data.map((r) => `
        <tr>${columnas.map((c) => `<td class="${c.num ? 'num' : ''}">${c.v(r)}</td>`).join('')}
          <td class="num"><button class="btn btn-ghost btn-sm" data-edit="${r.id}">Editar</button>
          <button class="btn btn-ghost btn-sm" data-del="${r.id}">Borrar</button></td></tr>`).join('')
        : `<tr><td colspan="${columnas.length + 1}" class="empty">${vacio}</td></tr>`;
    };
    const abrir = (r) => {
      editId = r?.id ?? null;
      document.getElementById('dlg-t').textContent = (r ? 'Editar' : 'Nuevo') + ' — ' + titulo.toLowerCase();
      frm.reset();
      campos.forEach((c) => { if (r && frm[c.k]) frm[c.k].value = r[c.k] ?? ''; });
      dlg.showModal();
    };
    document.getElementById('nuevo').onclick = () => abrir(null);
    document.getElementById('cancelar').onclick = () => dlg.close();
    document.getElementById('buscar').oninput = load;
    document.getElementById('lista').onclick = async (e) => {
      const ed = e.target.dataset.edit; const del = e.target.dataset.del;
      if (ed) abrir(data.find((r) => r.id === Number(ed)));
      if (del && confirm('¿Dar de baja este registro?')) {
        try { await api(`/${recurso}/${del}`, { method: 'DELETE' }); toast('Registro dado de baja'); load(); }
        catch (err) { toast(err.message, true); }
      }
    };
    frm.onsubmit = async (e) => {
      e.preventDefault();
      try {
        await api(editId ? `/${recurso}/${editId}` : `/${recurso}`, { method: editId ? 'PUT' : 'POST', body: formData(frm) });
        dlg.close(); toast('Guardado'); load();
      } catch (err) { toast(err.message, true); }
    };
    await load();
  };
}

views.clientes = abm({
  titulo: 'Clientes', recurso: 'clientes', vacio: 'Todavía no cargaste clientes.',
  columnas: [
    { t: 'Nombre / Razón social', v: (r) => `<b>${esc(r.nombre)}</b>` },
    { t: 'CUIT', v: (r) => fmtCuit(r.cuit) },
    { t: 'Condición IVA', v: (r) => esc(r.condicion_iva) },
    { t: 'Email', v: (r) => esc(r.email) },
  ],
  campos: [
    { k: 'nombre', t: 'Nombre / Razón social *', input: () => '<input name="nombre" required>' },
    { k: 'cuit', t: 'CUIT (sin guiones)', input: () => '<input name="cuit" inputmode="numeric" maxlength="13">' },
    { k: 'condicion_iva', t: 'Condición frente al IVA', input: () => `<select name="condicion_iva">${condOptions('Consumidor Final')}</select>` },
    { k: 'domicilio', t: 'Domicilio', input: () => '<input name="domicilio">' },
    { k: 'email', t: 'Email', input: () => '<input name="email" type="email">' },
    { k: 'telefono', t: 'Teléfono', input: () => '<input name="telefono">' },
  ],
});

views.productos = abm({
  titulo: 'Productos', recurso: 'productos', vacio: 'Todavía no cargaste productos o servicios.',
  columnas: [
    { t: 'Código', v: (r) => esc(r.codigo) },
    { t: 'Descripción', v: (r) => `<b>${esc(r.descripcion)}</b>` },
    { t: 'Precio neto', num: true, v: (r) => money(r.precio) },
    { t: 'IVA', num: true, v: (r) => `${String(r.alicuota_iva).replace('.', ',')}%` },
    { t: 'Precio final', num: true, v: (r) => money(r.precio * (1 + r.alicuota_iva / 100)) },
  ],
  campos: [
    { k: 'codigo', t: 'Código', input: () => '<input name="codigo">' },
    { k: 'descripcion', t: 'Descripción *', input: () => '<input name="descripcion" required>' },
    { k: 'precio', t: 'Precio neto (sin IVA) *', input: () => '<input name="precio" type="number" step="0.01" min="0" required>' },
    { k: 'alicuota_iva', t: 'Alícuota IVA', input: () => `<select name="alicuota_iva">${alicOptions(21)}</select>` },
  ],
});

views.empresa = async () => {
  const e = state.empresa = await api('/empresa');
  $app.innerHTML = `
    <h1>Mi empresa</h1>
    <form id="f" class="card">
      <p class="muted" style="margin-top:0">Estos datos aparecen en el encabezado de cada factura. La condición frente al IVA define el tipo de comprobante (A/B si sos Responsable Inscripto, C si sos Monotributista o Exento).</p>
      <div class="grid">
        <label>Razón social <input name="razon_social" value="${esc(e.razon_social)}" required></label>
        <label>CUIT <input name="cuit" value="${esc(e.cuit)}" inputmode="numeric"></label>
        <label>Domicilio comercial <input name="domicilio" value="${esc(e.domicilio)}"></label>
        <label>Condición frente al IVA <select name="condicion_iva">${condOptions(e.condicion_iva, ['Responsable Inscripto', 'Monotributo', 'Exento'])}</select></label>
        <label>Inicio de actividades <input type="date" name="inicio_actividades" value="${esc(e.inicio_actividades)}"></label>
        <label>Punto de venta <input type="number" name="punto_venta" min="1" max="99999" value="${e.punto_venta}"></label>
      </div>
      <div class="row" style="justify-content:flex-end; margin-top:16px"><button class="btn">Guardar</button></div>
    </form>`;
  document.getElementById('f').onsubmit = async (ev) => {
    ev.preventDefault();
    try { state.empresa = await api('/empresa', { method: 'PUT', body: formData(ev.target) }); toast('Datos guardados'); }
    catch (err) { toast(err.message, true); }
  };
};

// ---------- router ----------
async function router() {
  const h = location.hash.replace(/^#/, '') || '/';
  document.querySelectorAll('.topbar nav a').forEach((a) => a.classList.toggle('active', a.getAttribute('href') === '#' + h));
  let m;
  try {
    if (!state.config) [state.config, state.empresa] = await Promise.all([api('/config'), api('/empresa')]);
    if (h === '/') await views.inicio();
    else if (h === '/facturas') await views.facturas();
    else if (h === '/facturas/nueva') await views.nuevaFactura();
    else if ((m = h.match(/^\/facturas\/(\d+)$/))) await views.verFactura(m[1]);
    else if (h === '/clientes') await views.clientes();
    else if (h === '/productos') await views.productos();
    else if (h === '/empresa') await views.empresa();
    else $app.innerHTML = '<p class="empty">Página no encontrada.</p>';
  } catch (err) {
    $app.innerHTML = `<div class="card">Error: ${esc(err.message)}</div>`;
  }
  window.scrollTo(0, 0);
}
window.addEventListener('hashchange', router);
router();
