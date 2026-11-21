/*
 * Nueva orden de pago: tildar un comprobante pone su saldo (se puede pagar una parte), suma lo que se paga menos lo que se descuenta,
 * muestra en cada medio solo los campos que usa (cheque de la cartera, retención, cheque propio) y avisa si falta o sobra (anticipo).
 * El servidor vuelve a validar todo.
 */
(function () {
  'use strict';
  var form = document.getElementById('form-op');
  if (!form) return;
  var $ = function (s, r) { return (r || form).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || form).querySelectorAll(s)); };
  function num(v) {
    v = String(v || '').replace(/[\s$]/g, '');
    if (v === '') return 0;
    if (v.indexOf(',') >= 0) v = v.replace(/\./g, '').replace(',', '.');
    else if (/^-?[1-9]\d{0,2}(\.\d{3})+$/.test(v)) v = v.replace(/\./g, '');
    var n = parseFloat(v);
    return isNaN(n) ? 0 : n;
  }
  function r2(n) { return Math.round((n + (n >= 0 ? 1e-9 : -1e-9)) * 100) / 100; }
  function campo(n) { return n.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function plata(n) { return '$ ' + campo(n); }

  function totales() {
    var deb = 0, cre = 0, pag = 0;
    $$('[data-imp]').forEach(function (i) { var v = num(i.value); if (i.getAttribute('data-imp') === 'deb') deb += v; else cre += v; });
    $$('[data-medio-imp]').forEach(function (i) { pag += num(i.value); });
    var aPagar = r2(Math.max(0, deb - cre)), dif = r2(pag - aPagar);
    $('[data-op-apagar]').textContent = plata(aPagar);
    $('[data-op-pagado]').textContent = plata(pag);
    var t = $('[data-op-dif-titulo]'), d = $('[data-op-dif]');
    if (Math.abs(dif) < 0.005) { t.textContent = 'Diferencia'; d.innerHTML = '<span class="pill pill-ok">Cierra justo</span>'; }
    else if (dif < 0) { t.textContent = 'Falta pagar'; d.innerHTML = '<span class="pill pill-mal">' + plata(-dif) + '</span>'; }
    else { t.textContent = 'Queda a favor (anticipo)'; d.innerHTML = '<span class="pill pill-warn">' + plata(dif) + '</span>'; }
    return { aPagar: aPagar, pagado: pag };
  }

  // con un solo medio, propone lo que hay que pagar (mientras el usuario no lo cambie a mano)
  function proponer() {
    var filas = $$('[data-medio-fila]');
    if (filas.length !== 1) return;
    var imp = filas[0].querySelector('[data-medio-imp]');
    if (imp.readOnly) return;
    if (imp.value === '' || imp.getAttribute('data-auto') === '1') {
      var t = totales();
      imp.value = t.aPagar > 0 ? campo(t.aPagar) : '';
      imp.setAttribute('data-auto', '1');
    }
  }

  function vistaMedio(tr) {
    var m = tr.querySelector('[data-medio]').value;
    $$('[data-solo]', tr).forEach(function (el) {
      var si = el.getAttribute('data-solo').split(' ').indexOf(m) >= 0;
      el.hidden = !si;
      el.disabled = !si;
    });
    tr.querySelector('[data-medio-imp]').readOnly = m === 'Q';
  }

  form.addEventListener('change', function (ev) {
    var t = ev.target;
    if (t.matches('[data-tilde]')) {
      var inp = t.closest('tr').querySelector('[data-imp]');
      inp.value = t.checked ? campo(num(inp.getAttribute('data-saldo'))) : '';
      proponer();
    } else if (t.matches('[data-todos]')) {
      $$('[data-tilde]', t.closest('table')).forEach(function (c) {
        c.checked = t.checked;
        var inp = c.closest('tr').querySelector('[data-imp]');
        inp.value = t.checked ? campo(num(inp.getAttribute('data-saldo'))) : '';
      });
      proponer();
    } else if (t.matches('[data-medio]')) {
      vistaMedio(t.closest('tr'));
    } else if (t.matches('[data-cheque]')) {
      var op = t.options[t.selectedIndex];
      var imp = t.closest('tr').querySelector('[data-medio-imp]');
      imp.value = op && op.getAttribute('data-importe') ? campo(num(op.getAttribute('data-importe'))) : '';
      imp.removeAttribute('data-auto');
    }
    totales();
  });
  form.addEventListener('input', function (ev) {
    var t = ev.target;
    if (t.matches('[data-imp]')) {
      var c = t.closest('tr').querySelector('[data-tilde]');
      if (c) c.checked = num(t.value) > 0;
      proponer();
    }
    if (t.matches('[data-medio-imp]')) t.removeAttribute('data-auto');
    if (t.matches('[data-imp], [data-medio-imp]')) totales();
  });
  $$('[data-medio-fila]').forEach(vistaMedio);

  // agregar y quitar medios; el nuevo propone lo que falta
  var cuerpo = $('[data-filas-medios]');
  var molde = cuerpo.querySelector('tr').cloneNode(true);
  var n = cuerpo.children.length;
  $('[data-agregar-medio]').addEventListener('click', function () {
    var tr = molde.cloneNode(true);
    $$('[name]', tr).forEach(function (el) {
      el.name = el.name.replace(/medios\[\d+\]/, 'medios[' + n + ']');
      if (el.tagName === 'SELECT') el.selectedIndex = 0; else el.value = '';
    });
    n++;
    cuerpo.appendChild(tr);
    vistaMedio(tr);
    var t = totales(), falta = r2(t.aPagar - t.pagado);
    if (falta > 0) tr.querySelector('[data-medio-imp]').value = campo(falta);
    totales();
    tr.querySelector('[data-medio]').focus();
  });
  cuerpo.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-quitar]');
    if (!b) return;
    var tr = b.closest('tr');
    if (cuerpo.children.length > 1) cuerpo.removeChild(tr);
    else { $$('input', tr).forEach(function (i) { i.value = ''; }); tr.querySelector('[data-cheque]').value = ''; }
    totales();
  });
  form.addEventListener('submit', function () { var b = form.querySelector('button[type=submit]'); if (b) setTimeout(function () { b.disabled = true; }, 0); });
  proponer();
  totales();
})();
