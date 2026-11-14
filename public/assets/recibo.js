/*
 * Formulario de recibo: tildar un comprobante carga su saldo (se puede achicar para cobrar en partes), arma los totales y avisa si los
 * medios de pago no suman lo que se cobra. El servidor vuelve a calcular y validar todo; esto es solo ayuda para el que carga.
 */
(function () {
  'use strict';
  var form = document.querySelector('[data-recibo]');
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
  function ponerNum(inp, v) { inp.value = v.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  var q = function (s) { return form.querySelector(s); };
  var qa = function (s) { return form.querySelectorAll(s); };

  function recalcular() {
    var imput = 0;
    qa('[data-imputar]').forEach(function (inp) { imput += num(inp.value); });
    var bruto = r2(imput + num(q('[data-anticipo]').value));
    var dto = num(q('[data-descuento]').value);
    var ret = 0;
    qa('[data-ret]').forEach(function (inp) { ret += num(inp.value); });
    var neto = r2(bruto - dto - ret);
    var pagos = 0;
    qa('[data-pago-importe]').forEach(function (inp) { pagos += num(inp.value); });
    pagos = r2(pagos);
    q('[data-t-bruto]').textContent = fmt(bruto);
    q('[data-t-neto]').textContent = fmt(neto);
    q('[data-t-pagos]').textContent = fmt(pagos);
    var dif = r2(neto - pagos);
    var d = q('[data-t-dif]');
    d.textContent = dif === 0 ? 'Cuadra' : (dif > 0 ? 'Faltan ' + fmt(dif) : 'Sobran ' + fmt(-dif));
    d.className = dif === 0 ? 'texto-ok' : 'texto-error';
    q('[data-neto]').value = neto;
  }
  form.addEventListener('input', recalcular);
  form.addEventListener('change', function (ev) {
    var t = ev.target;
    if (t.matches('[data-marca]')) {
      var inp = t.closest('tr').querySelector('[data-imputar]');
      if (t.checked) { ponerNum(inp, parseFloat(t.getAttribute('data-saldo'))); } else { inp.value = ''; }
      recalcular();
    }
    if (t.matches('[data-fpago]')) { cheque(t.closest('[data-pago]')); }
  });
  qa('[data-imputar]').forEach(function (inp) {
    inp.addEventListener('input', function () { var c = inp.closest('tr').querySelector('[data-marca]'); c.checked = num(inp.value) > 0; });
  });
  function cheque(fila) {
    var tipo = fila.querySelector('[data-fpago] option:checked').getAttribute('data-tipo');
    fila.querySelectorAll('[data-solo-cheque]').forEach(function (e) { e.hidden = tipo !== '5'; });
  }
  qa('[data-pago]').forEach(cheque);
  var todo = q('[data-cobrar-todo]');
  if (todo) todo.addEventListener('click', function () {
    qa('[data-marca]').forEach(function (c) { c.checked = true; ponerNum(c.closest('tr').querySelector('[data-imputar]'), parseFloat(c.getAttribute('data-saldo'))); });
    recalcular();
  });
  var comp = q('[data-completar]');
  if (comp) comp.addEventListener('click', function () {
    var fila = qa('[data-pago]')[0];
    var otros = 0;
    qa('[data-pago-importe]').forEach(function (inp, i) { if (i > 0) otros += num(inp.value); });
    recalcular();
    var neto = parseFloat(q('[data-neto]').value) || 0;
    ponerNum(fila.querySelector('[data-pago-importe]'), Math.max(0, r2(neto - otros)));
    recalcular();
  });
  var plantilla = q('template[data-plantilla-pago]');
  var cuerpo = q('[data-pagos]');
  var agregar = q('[data-agregar-pago]');
  if (agregar) agregar.addEventListener('click', function () {
    var n = qa('[data-pago]').length;
    var div = document.createElement('div');
    div.innerHTML = plantilla.innerHTML.replace(/__N__/g, String(n));
    var fila = div.firstElementChild;
    cuerpo.appendChild(fila);
    cheque(fila);
  });
  var plantillaRet = q('template[data-plantilla-ret]');
  var agregarRet = q('[data-agregar-ret]');
  if (agregarRet) agregarRet.addEventListener('click', function () {
    var div = document.createElement('div');
    div.innerHTML = plantillaRet.innerHTML.replace(/__N__/g, String(Date.now()));
    q('[data-rets]').appendChild(div.firstElementChild);
  });
  form.addEventListener('click', function (ev) {
    if (ev.target.matches('[data-quitar-ret]')) { ev.target.closest('[data-ret-fila]').remove(); recalcular(); }
  });
  form.addEventListener('submit', function (ev) {
    var b = q('[data-confirmar-recibo]');
    if (b && !window.confirm('¿Confirmás el recibo por ' + q('[data-t-neto]').textContent + '?')) ev.preventDefault();
  });
  recalcular();
})();
