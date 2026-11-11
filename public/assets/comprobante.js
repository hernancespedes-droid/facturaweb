// Formulario de facturación: búsqueda de cliente y artículos, renglones y totales en vivo.
// Los totales de la pantalla son una ayuda: el servidor vuelve a calcular todo al revisar (mismas reglas que Calculadora.php).
(function () {
  'use strict';
  var form = document.getElementById('form-comprobante');
  if (!form) return;

  var tipo = form.getAttribute('data-tipo');
  var condEmisor = form.getAttribute('data-condicion-emisor');
  var cuerpo = form.querySelector('[data-renglones-cuerpo]');
  var plantilla = document.getElementById('renglon-plantilla');
  var picker = form.querySelector('[data-picker]');
  var qInput = form.querySelector('[data-cliente-q]');
  var hidCliente = form.querySelector('[data-cliente]');
  var lista = form.querySelector('[data-cliente-lista]');
  var info = form.querySelector('[data-cliente-info]');
  var letra = form.getAttribute('data-letra') || '';
  var siguiente = cuerpo.querySelectorAll('[data-renglon]').length;

  // ---- números (formato argentino: 1.234,56)
  function num(txt) {
    txt = String(txt || '').replace(/[\s$]/g, '');
    if (txt === '') return NaN;
    if (txt.indexOf(',') >= 0) txt = txt.replace(/\./g, '').replace(',', '.');
    else if (/^-?[1-9]\d{0,2}(\.\d{3})+$/.test(txt)) txt = txt.replace(/\./g, '');
    return /^-?\d+(\.\d+)?$/.test(txt) ? parseFloat(txt) : NaN;
  }
  function r2(v) { return Math.round((v + (v >= 0 ? 1e-9 : -1e-9)) * 100) / 100; }
  function fmt(v) { return '$ ' + v.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

  // ---- letra: la de la factura que se corrige, o la que sale de la condición del cliente
  function actualizarLetra(l) {
    letra = l || '';
    form.setAttribute('data-letra', letra);
    var t = form.parentNode.querySelector('[data-letra-titulo]') || document.querySelector('[data-letra-titulo]');
    if (t) t.textContent = letra;
    var ley = form.querySelector('[data-leyenda-precio]');
    var th = form.querySelector('[data-th-precio]');
    if (letra === 'A') { ley.textContent = 'Factura A: los precios van sin IVA (netos); el IVA se suma al total.'; th.textContent = 'Precio neto'; }
    else if (letra === 'B') { ley.textContent = 'Factura B: los precios van con el IVA incluido.'; th.textContent = 'Precio con IVA'; }
    else if (letra === 'C') { ley.textContent = 'Factura C: los precios son finales, sin IVA discriminado.'; th.textContent = 'Precio final'; }
    else { ley.textContent = 'Elegí el cliente para saber si es A, B o C.'; th.textContent = 'Precio'; }
    recalcular();
  }

  // ---- totales (mismas reglas que Calculadora.php)
  function recalcular() {
    var desc = num(form.querySelector('[data-descuento]').value);
    desc = isNaN(desc) ? 0 : Math.max(0, Math.min(100, desc));
    var factor = 1 - desc / 100;
    var grupos = {};
    var subtotal = 0, despues = 0;
    cuerpo.querySelectorAll('[data-renglon]').forEach(function (tr) {
      var cant = num(tr.querySelector('[data-cantidad]').value);
      var precio = num(tr.querySelector('[data-precio]').value);
      var celda = tr.querySelector('[data-importe]');
      if (isNaN(cant) || isNaN(precio)) { celda.textContent = '—'; return; }
      var alic = letra === 'C' ? 0 : parseFloat(tr.querySelector('[data-alicuota] option:checked').getAttribute('data-pct') || '0');
      var importe = r2(cant * precio);
      var con = r2(importe * factor);
      celda.textContent = fmt(importe);
      var g = grupos[alic] || (grupos[alic] = { alic: alic, antes: 0, despues: 0 });
      g.antes = r2(g.antes + importe);
      g.despues = r2(g.despues + con);
      subtotal = r2(subtotal + importe);
      despues = r2(despues + con);
    });
    var ivaTotal = 0, neto = 0, exento = 0, filas = '';
    Object.keys(grupos).map(Number).sort(function (a, b) { return a - b; }).forEach(function (k) {
      var g = grupos[k], n, iva;
      if (letra === 'A') { n = g.despues; iva = g.alic > 0 ? r2(n * g.alic / 100) : 0; }
      else { n = g.alic > 0 ? r2(g.despues / (1 + g.alic / 100)) : g.despues; iva = g.alic > 0 ? r2(g.despues - n) : 0; }
      if (letra === 'C') { neto = r2(neto + n); }
      else if (g.alic > 0) { neto = r2(neto + n); ivaTotal = r2(ivaTotal + iva); if (letra === 'A') filas += '<div><span>IVA ' + g.alic.toLocaleString('es-AR') + ' %</span><b>' + fmt(iva) + '</b></div>'; }
      else { exento = r2(exento + n); }
    });
    var total = r2(neto + exento + ivaTotal);
    form.querySelector('[data-t-subtotal]').textContent = fmt(subtotal);
    var filaDesc = form.querySelector('[data-t-desc-fila]');
    filaDesc.hidden = !(subtotal - despues > 0);
    form.querySelector('[data-t-desc]').textContent = '− ' + fmt(r2(subtotal - despues));
    form.querySelector('[data-t-iva-filas]').innerHTML = filas;
    form.querySelector('[data-t-total]').textContent = fmt(total);
  }

  // ---- cliente
  var timer = null;
  function buscarClientes(q) {
    fetch(form.getAttribute('data-url-cliente') + '?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (arr) {
        lista.innerHTML = '';
        arr.forEach(function (c) {
          var li = document.createElement('li');
          li.setAttribute('role', 'option');
          li.tabIndex = 0;
          li.innerHTML = '<b>' + esc(c.razon_social) + '</b> <small>' + esc(c.codigo + ' · ' + c.iva_texto + (c.cuit ? ' · ' + c.cuit : '') + (c.localidad ? ' · ' + c.localidad : '')) + '</small>';
          li.addEventListener('click', function () { elegirCliente(c); });
          li.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') elegirCliente(c); });
          lista.appendChild(li);
        });
        lista.hidden = arr.length === 0;
      });
  }
  function elegirCliente(c) {
    hidCliente.value = c.codigo;
    qInput.value = c.razon_social;
    lista.hidden = true;
    var txt = 'Código ' + c.codigo + ' · ' + c.iva_texto + (c.cuit ? ' · CUIT ' + c.cuit : '');
    if (c.credito > 0) txt += ' · crédito ' + fmt(c.credito) + ', deuda ' + fmt(c.deuda);
    if (!c.equivalencia) txt += ' · ATENCIÓN: no hay equivalencia de ARCA para su condición de IVA';
    info.textContent = txt;
    // los datos del cliente pasan al comprobante (como el VFP); la forma de pago arranca en cuenta corriente
    var set = function (sel, v) { var el = form.querySelector(sel); if (el && v !== undefined && v !== null) el.value = v; };
    set('[data-descuento]', c.descuento && c.descuento !== '0' ? c.descuento : '');
    set('[data-vendedor]', c.vendedor);
    set('[data-cond-pago]', c.cond_pago);
    set('[data-email]', c.email);
    if (tipo === 'FC') actualizarLetra(c.letra);
    else cargarAsociables(c.codigo);
    recalcular();
  }
  function cargarAsociables(cliente) {
    var sel = form.querySelector('[data-asociado]');
    fetch(form.getAttribute('data-url-asociables') + '?cliente=' + encodeURIComponent(cliente), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (arr) {
        sel.innerHTML = '<option value="">Elegí la factura o nota de débito</option>';
        arr.forEach(function (a) {
          var o = document.createElement('option');
          o.value = a.id; o.textContent = a.texto; o.setAttribute('data-letra', a.letra);
          sel.appendChild(o);
        });
        actualizarLetra('');
      });
  }
  qInput.addEventListener('input', function () {
    hidCliente.value = '';
    info.textContent = '';
    if (tipo === 'FC') actualizarLetra('');
    clearTimeout(timer);
    var q = qInput.value.trim();
    if (q.length < 2) { lista.hidden = true; return; }
    timer = setTimeout(function () { buscarClientes(q); }, 250);
  });
  document.addEventListener('click', function (ev) { if (!picker.contains(ev.target)) lista.hidden = true; });
  var asoc = form.querySelector('[data-asociado]');
  if (asoc) asoc.addEventListener('change', function () {
    var o = asoc.options[asoc.selectedIndex];
    actualizarLetra(o ? o.getAttribute('data-letra') || '' : '');
  });

  // ---- forma de pago
  function formaPago() {
    var cont = form.querySelector('[name="forma_pago"]:checked');
    var contado = cont && cont.value === '1' && tipo !== 'NC';
    var bloque = form.querySelector('[data-contado]');
    if (bloque) bloque.hidden = !contado;
    var fp = form.querySelector('[data-fpago]');
    var cheque = form.querySelector('[data-cheque]');
    if (cheque) cheque.hidden = !(contado && fp && fp.options[fp.selectedIndex] && fp.options[fp.selectedIndex].getAttribute('data-tipo') === '5');
  }
  form.querySelectorAll('[name="forma_pago"]').forEach(function (r) { r.addEventListener('change', formaPago); });
  var fpago = form.querySelector('[data-fpago]');
  if (fpago) fpago.addEventListener('change', formaPago);

  // ---- renglones
  function agregarRenglon() {
    var html = plantilla.innerHTML.replace(/__I__/g, String(siguiente++));
    var t = document.createElement('tbody');
    t.innerHTML = html;
    cuerpo.appendChild(t.firstElementChild);
    recalcular();
  }
  form.querySelector('[data-agregar]').addEventListener('click', agregarRenglon);
  cuerpo.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-quitar]');
    if (!b) return;
    var tr = b.closest('[data-renglon]');
    if (cuerpo.querySelectorAll('[data-renglon]').length > 1) tr.remove();
    else tr.querySelectorAll('input').forEach(function (i) { i.value = ''; });
    recalcular();
  });
  cuerpo.addEventListener('input', recalcular);
  cuerpo.addEventListener('change', recalcular);
  form.querySelector('[data-descuento]').addEventListener('input', recalcular);

  // artículo: sugerencias mientras se escribe y datos al elegirlo
  var dl = document.getElementById('articulos-lista');
  var timerArt = null;
  cuerpo.addEventListener('input', function (ev) {
    var inp = ev.target.closest('[data-articulo]');
    if (!inp || inp.value.trim().length < 2) return;
    clearTimeout(timerArt);
    timerArt = setTimeout(function () {
      fetch(form.getAttribute('data-url-articulo') + '?q=' + encodeURIComponent(inp.value.trim()), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (arr) {
          dl.innerHTML = '';
          arr.forEach(function (a) { var o = document.createElement('option'); o.value = a.codigo; o.label = a.descripcion; dl.appendChild(o); });
        });
    }, 250);
  });
  cuerpo.addEventListener('change', function (ev) {
    var inp = ev.target.closest('[data-articulo]');
    if (!inp || inp.value.trim() === '') return;
    fetch(form.getAttribute('data-url-articulo') + '?exacto=1&q=' + encodeURIComponent(inp.value.trim()), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (arr) {
        if (!arr.length) return;
        var a = arr[0], tr = inp.closest('[data-renglon]');
        inp.value = a.codigo;
        tr.querySelector('[data-descripcion]').value = a.descripcion;
        if (a.precio) tr.querySelector('[data-precio]').value = a.precio;
        var cant = tr.querySelector('[data-cantidad]');
        if (cant.value.trim() === '') cant.value = '1';
        var sel = tr.querySelector('[data-alicuota]');
        for (var i = 0; i < sel.options.length; i++) if (sel.options[i].value === a.alicuota) sel.selectedIndex = i;
        recalcular();
      });
  });

  // un solo envío
  form.addEventListener('submit', function () {
    setTimeout(function () { form.querySelectorAll('button[type=submit]').forEach(function (b) { b.disabled = true; }); }, 0);
  });
  document.querySelectorAll('[data-un-solo-envio]').forEach(function (b) {
    b.form.addEventListener('submit', function () { setTimeout(function () { b.disabled = true; }, 0); });
  });

  actualizarLetra(letra);
  formaPago();
})();
