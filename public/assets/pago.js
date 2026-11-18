/*
 * Pantalla de revisión: «Cómo se paga». Contado muestra con qué se paga (y el cheque, si es cheque); si la forma de pago acepta vuelto
 * (efectivo) se pide lo recibido y se avisa cuánto vuelto hay que dar. El servidor vuelve a validar todo.
 */
(function () {
  'use strict';
  var sec = document.querySelector('[data-pago]');
  if (!sec) return;
  var total = parseFloat(sec.getAttribute('data-total')) || 0;
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
  var bloque = sec.querySelector('[data-contado]');
  var fp = sec.querySelector('[data-fpago]');
  var cheque = sec.querySelector('[data-cheque]');
  var cajaRec = sec.querySelector('[data-recibido-caja]');
  var rec = sec.querySelector('[data-recibido]');
  var aviso = sec.querySelector('[data-vuelto-aviso]');

  function actualizar() {
    var r = document.querySelector('[name="forma_pago"]:checked');
    var contado = !!r && r.value === '1';
    bloque.hidden = !contado;
    var o = fp.options[fp.selectedIndex];
    cheque.hidden = !(contado && o && o.getAttribute('data-tipo') === '5');
    var acepta = contado && o && o.getAttribute('data-vuelto') === '1';
    cajaRec.hidden = !acepta;
    aviso.hidden = true;
    if (!acepta) { return; }
    var v = num(rec.value);
    if (rec.value.trim() === '' || v === 0) return;
    var dif = r2(v - total);
    if (dif > 0) {
      aviso.textContent = 'DAR VUELTO AL CLIENTE: ' + fmt(dif) + ' (recibís ' + fmt(v) + ' por ' + fmt(total) + ')';
      aviso.hidden = false;
    } else if (dif < 0) {
      aviso.textContent = 'Falta cobrar ' + fmt(-dif) + ' para llegar al total.';
      aviso.hidden = false;
    }
  }
  document.querySelectorAll('[name="forma_pago"]').forEach(function (x) { x.addEventListener('change', actualizar); });
  fp.addEventListener('change', actualizar);
  rec.addEventListener('input', actualizar);
  actualizar();
})();
