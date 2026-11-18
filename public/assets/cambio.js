/*
 * Cambio de mercadería: arma «devuelve / se lleva / diferencia a cobrar» y muestra «Cómo se paga» solo si hay diferencia. El servidor vuelve a
 * calcular y validar todo (precio de lo devuelto, cantidades disponibles, vuelto).
 */
(function () {
  'use strict';
  var form = document.querySelector('[data-cambio]');
  if (!form) return;
  function num(txt) {
    txt = String(txt || '').replace(/[\s$]/g, '');
    if (txt === '') return 0;
    if (txt.indexOf(',') >= 0) txt = txt.replace(/\./g, '').replace(',', '.');
    else if (/^\d{1,3}(\.\d{3})+$/.test(txt)) txt = txt.replace(/\./g, '');
    var v = parseFloat(txt);
    return isNaN(v) ? 0 : v;
  }
  function r2(v) { return Math.round((v + (v >= 0 ? 1e-9 : -1e-9)) * 100) / 100; }
  function fmt(v) { return '$ ' + v.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  var q = function (s) { return form.querySelector(s); };
  var qa = function (s) { return Array.prototype.slice.call(form.querySelectorAll(s)); };
  var bloquePago = q('[data-bloque-pago]');

  function recalcular() {
    var dev = 0, lleva = 0;
    qa('[data-devolver]').forEach(function (i) {
      var c = Math.min(num(i.value), parseFloat(i.getAttribute('data-max')) || 0);
      dev += c * (parseFloat(i.getAttribute('data-unitario')) || 0);
    });
    qa('[data-nuevo]').forEach(function (tr) {
      var imp = num(tr.querySelector('[data-cant]').value || (tr.querySelector('[data-art]').value ? '1' : '0')) * num(tr.querySelector('[data-precio]').value);
      tr.querySelector('[data-importe]').textContent = imp > 0 ? fmt(r2(imp)) : '—';
      lleva += imp;
    });
    dev = r2(dev); lleva = r2(lleva);
    var dif = r2(lleva - dev);
    q('[data-t-devuelve]').textContent = fmt(dev);
    q('[data-t-lleva]').textContent = fmt(lleva);
    var d = q('[data-t-dif]');
    d.textContent = dif >= 0 ? fmt(dif) : 'Faltan ' + fmt(-dif);
    d.className = dif < 0 ? 'texto-error' : '';
    var cobra = dif > 0.004;
    bloquePago.hidden = !cobra;
    var sec = q('[data-pago]');
    if (cobra && sec) { sec.setAttribute('data-total', String(dif)); sec.querySelector('.total-cobrar b').textContent = fmt(dif); sec.dispatchEvent(new CustomEvent('pago:total')); }
  }

  form.addEventListener('input', recalcular);
  form.addEventListener('click', function (ev) {
    var t = ev.target;
    if (t.matches('[data-todo]')) {
      var i = t.closest('td').querySelector('[data-devolver]');
      i.value = String(parseFloat(i.getAttribute('data-max')) || 0).replace('.', ',');
      recalcular();
    }
    if (t.matches('[data-mas]')) {
      var oculta = form.querySelector('[data-nuevo][hidden]');
      if (oculta) { oculta.hidden = false; oculta.querySelector('[data-art]').focus(); }
      if (!form.querySelector('[data-nuevo][hidden]')) t.hidden = true;
    }
  });
  // al elegir un artículo de la lista: queda el precio de lista (con IVA) y 1 unidad si no había cantidad
  form.addEventListener('buscador:elegido', function (ev) {
    var tr = ev.target.closest('[data-nuevo]');
    if (!tr) return;
    var a = ev.detail || {};
    if (a.precio) tr.querySelector('[data-precio]').value = a.precio;
    var c = tr.querySelector('[data-cant]');
    if (c.value.trim() === '') c.value = a.cantidad ? String(a.cantidad) : '1';
    recalcular();
  });
  form.addEventListener('submit', function (ev) {
    var dif = q('[data-t-dif]').textContent;
    if (!window.confirm('¿Confirmás el cambio? Diferencia a cobrar: ' + (dif.indexOf('Faltan') === 0 ? '$ 0,00' : dif))) ev.preventDefault();
  });
  recalcular();
})();
