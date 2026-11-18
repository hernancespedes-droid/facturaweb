/*
 * Imputación manual en dos paneles: a la izquierda se tildan los saldos a favor (notas de crédito, recibos) y eso arma el total a imputar;
 * a la derecha se tildan las facturas y débitos y cada una se cancela hasta agotar ese total. El botón se habilita cuando no queda nada por
 * imputar. El servidor vuelve a validar todo; esto es solo ayuda para el que carga.
 */
(function () {
  'use strict';
  var form = document.querySelector('[data-imputador]');
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
  function poner(inp, v) { inp.value = v > 0 ? v.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : ''; }
  var q = function (s) { return form.querySelector(s); };
  var qa = function (s) { return Array.prototype.slice.call(form.querySelectorAll(s)); };
  var orden = 0;

  function inp(chk) { return chk.closest('tr').querySelector('input[data-imp-cr], input[data-imp-db]'); }
  function suma(sel) { return r2(qa(sel).reduce(function (a, i) { return a + num(i.value); }, 0)); }

  // Si se achica lo que hay para imputar, se descuenta de lo último que se tildó a la derecha
  function recortar() {
    var exceso = r2(suma('[data-imp-db]') - suma('[data-imp-cr]'));
    if (exceso <= 0) return;
    var filas = qa('[data-imp-db]').filter(function (i) { return num(i.value) > 0; })
      .sort(function (a, b) { return Number(b.getAttribute('data-orden') || 0) - Number(a.getAttribute('data-orden') || 0); });
    filas.forEach(function (i) {
      if (exceso <= 0) return;
      var v = num(i.value), quita = Math.min(v, exceso);
      poner(i, r2(v - quita));
      exceso = r2(exceso - quita);
      i.closest('tr').querySelector('[data-marca-db]').checked = num(i.value) > 0;
    });
  }

  function recalcular() {
    recortar();
    var aImp = suma('[data-imp-cr]'), imp = suma('[data-imp-db]'), falta = r2(aImp - imp);
    q('[data-t-aimputar]').textContent = fmt(aImp);
    q('[data-t-imputado]').textContent = fmt(imp);
    var f = q('[data-t-falta]');
    f.textContent = fmt(falta);
    f.className = falta === 0 && aImp > 0 ? 'texto-ok' : 'texto-error';
    q('[data-imputar-btn]').disabled = !(aImp > 0 && falta === 0);
    // Sin nada por imputar no se puede tildar más deuda (las ya tildadas se pueden destildar)
    qa('[data-marca-db]').forEach(function (c) { c.disabled = !c.checked && falta <= 0; });
  }

  form.addEventListener('change', function (ev) {
    var t = ev.target, i;
    if (t.matches('[data-marca-cr]')) {
      i = inp(t);
      poner(i, t.checked ? parseFloat(t.getAttribute('data-saldo')) : 0);
    } else if (t.matches('[data-marca-db]')) {
      i = inp(t);
      if (t.checked) {
        var disp = r2(suma('[data-imp-cr]') - suma('[data-imp-db]'));
        var v = Math.min(parseFloat(t.getAttribute('data-saldo')), disp);
        if (v <= 0) { t.checked = false; } else { i.setAttribute('data-orden', String(++orden)); poner(i, v); }
      } else { poner(i, 0); }
    }
    recalcular();
  });

  // Importes escritos a mano: no pasan del saldo ni de lo que queda por imputar
  form.addEventListener('input', function (ev) {
    var t = ev.target;
    if (!t.matches('[data-imp-cr], [data-imp-db]')) return;
    var cr = t.matches('[data-imp-cr]'), tr = t.closest('tr');
    var chk = tr.querySelector(cr ? '[data-marca-cr]' : '[data-marca-db]');
    var tope = parseFloat(chk.getAttribute('data-saldo'));
    var v = num(t.value);
    if (!cr) tope = Math.min(tope, r2(suma('[data-imp-cr]') - suma('[data-imp-db]') + v));
    if (v > tope) { v = Math.max(0, tope); poner(t, v); }
    chk.checked = v > 0;
    if (!cr && v > 0) t.setAttribute('data-orden', String(++orden));
    recalcular();
  });

  form.addEventListener('submit', function (ev) {
    if (q('[data-imputar-btn]').disabled) { ev.preventDefault(); return; }
    if (!window.confirm('¿Confirmás la imputación por ' + q('[data-t-aimputar]').textContent + '?')) ev.preventDefault();
  });
  recalcular();
})();
