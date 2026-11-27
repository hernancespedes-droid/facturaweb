// Facturación masiva (0.66.0): elegir pre-facturas, ver cuántas y por cuánto, y «Aprobar y facturar» de a una con el avance a la vista.
// Cada pedido factura UNA pre-factura (POST /facturacion-masiva/procesar con json=1), en orden de fecha (data-orden): si una no se puede, queda
// pendiente con el motivo y siguen las demás. Sin JavaScript el formulario se envía igual y el servidor las procesa juntas.
document.addEventListener('DOMContentLoaded', function () {
  var form = document.querySelector('form[data-masiva]');
  if (!form) return;
  var checks = function () { return Array.prototype.slice.call(form.querySelectorAll('[data-masiva-check]')); };
  var plata = function (n) { return '$ ' + n.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var elegidas = form.querySelector('[data-masiva-elegidas]');
  var total = form.querySelector('[data-masiva-total]');

  function resumen() {
    var n = 0, t = 0;
    checks().forEach(function (c) {
      if (!c.checked) return;
      n++;
      t += parseFloat(c.closest('tr').getAttribute('data-total') || '0') || 0;
    });
    if (elegidas) elegidas.textContent = String(n);
    if (total) total.textContent = plata(t);
  }
  form.addEventListener('change', function (ev) {
    if (ev.target.matches('[data-masiva-todas]')) { // «todas»: solo las que están listas para facturar
      checks().forEach(function (c) { c.checked = ev.target.checked && c.closest('tr').getAttribute('data-lista') === '1'; });
    }
    resumen();
  });
  resumen();

  function marcar(tr, r) {
    var celda = tr.querySelector('[data-pf-estado]');
    var c = tr.querySelector('[data-masiva-check]');
    celda.textContent = '';
    var b = document.createElement('span');
    b.className = 'badge ' + (r.ok ? 'badge-ok' : 'badge-warn');
    b.textContent = r.ok ? 'Facturada' : 'Para corregir';
    celda.appendChild(b);
    if (r.ok && r.comprobante_id) {
      celda.appendChild(document.createTextNode(' '));
      var a = document.createElement('a');
      a.href = form.getAttribute('data-url-comprobante') + '/' + r.comprobante_id;
      a.textContent = r.comprobante || 'Ver';
      celda.appendChild(a);
      if (c) c.remove();
      tr.classList.remove('fila-error');
      tr.setAttribute('data-lista', '0');
      var mod = tr.querySelector('a[href*="/modificar"]');
      if (mod) mod.remove();
    } else {
      tr.classList.add('fila-error');
      tr.setAttribute('data-lista', '0');
      if (c) c.checked = false;
    }
    var msg = r.ok ? r.aviso : r.mensaje;
    if (msg) {
      celda.appendChild(document.createElement('br'));
      var s = document.createElement('small');
      s.className = r.ok ? 'muted' : 'field-error';
      s.textContent = msg;
      celda.appendChild(s);
    }
  }

  // Después de la confirmación de app.js (data-confirmar-boton): este manejador va en document y se registra después.
  document.addEventListener('submit', function (ev) {
    if (ev.target !== form || ev.defaultPrevented || !ev.submitter || !ev.submitter.hasAttribute('data-masiva-procesar') || !window.fetch) return;
    ev.preventDefault();
    var filas = checks().filter(function (c) { return c.checked; }).map(function (c) { return c.closest('tr'); });
    filas.sort(function (a, b) { return a.getAttribute('data-orden') < b.getAttribute('data-orden') ? -1 : 1; });
    if (!filas.length) return;
    var botones = form.querySelectorAll('button');
    botones.forEach(function (x) { x.disabled = true; });
    var avance = form.querySelector('[data-masiva-avance]');
    var barra = form.querySelector('[data-masiva-progreso]');
    var texto = form.querySelector('[data-masiva-avance-texto]');
    avance.hidden = false;
    barra.max = filas.length;
    var bien = 0, mal = 0, i = 0;
    var base = new FormData(form);
    base.delete('ids[]');
    base.set('json', '1');
    function fin(extra) {
      texto.textContent = 'Listo: ' + bien + ' facturada(s)' + (mal ? ', ' + mal + ' quedaron pendientes (el motivo está en cada una)' : '') + '.' + (extra || '');
      botones.forEach(function (x) { x.disabled = false; });
      var a = document.createElement('a');
      a.href = window.location.pathname + window.location.search;
      a.textContent = ' Actualizar la lista';
      texto.appendChild(a);
      resumen();
    }
    function siguiente() {
      if (i >= filas.length) { fin(); return; }
      var tr = filas[i];
      texto.textContent = 'Facturando ' + (i + 1) + ' de ' + filas.length + '…';
      tr.scrollIntoView({ block: 'nearest' });
      var fd = new FormData();
      base.forEach(function (v, k) { fd.append(k, v); });
      fd.append('ids[]', tr.getAttribute('data-pf'));
      fetch(form.getAttribute('data-url-procesar'), { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (j) {
          var r = j.resultados && j.resultados[tr.getAttribute('data-pf')];
          if (!r) r = { ok: false, mensaje: 'No se procesó: volvé a intentar.' };
          r.ok ? bien++ : mal++;
          marcar(tr, r);
          i++;
          barra.value = i;
          siguiente();
        })
        .catch(function (e) {
          fin(' Se cortó en la ' + (i + 1) + '.ª (' + e.message + '): actualizá la lista y volvé a intentar; lo ya facturado no se repite.');
        });
    }
    siguiente();
  });
});
