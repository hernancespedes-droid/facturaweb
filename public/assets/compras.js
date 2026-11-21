/*
 * Nueva compra o gasto: muestra los importes según el comprobante (A y M discriminan IVA; B, C, tique y gasto, un solo importe),
 * propone el IVA de cada alícuota, suma el total y lo compara con el impreso, calcula el costo que va a los centros, reparte por
 * porcentaje (con plantillas) y completa lo que propone el proveedor (letra, centro, concepto, vencimiento). El servidor vuelve a validar todo.
 */
(function () {
  'use strict';
  var form = document.getElementById('form-compra');
  if (!form) return;
  var inscripta = form.getAttribute('data-inscripta') === '1';
  var plantillas = JSON.parse(form.getAttribute('data-plantillas') || '[]');
  var $ = function (s, r) { return (r || form).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || form).querySelectorAll(s)); };
  var plazo = 0;

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

  var tipo = $('[data-tipo]'), letra = $('[data-letra]');
  function discrimina() { return tipo.value !== 'GS' && (letra.value === 'A' || letra.value === 'M'); }

  function mostrar(sel, si) {
    $$(sel).forEach(function (b) {
      b.hidden = !si;
      $$('input, select, textarea', b).forEach(function (i) { if (!i.hasAttribute('data-siempre')) i.disabled = !si; });
    });
  }

  function vista() {
    var gs = tipo.value === 'GS';
    $$('[data-con-numero]').forEach(function (b) { b.hidden = gs; $$('input, select', b).forEach(function (i) { i.disabled = gs; }); });
    var d = discrimina();
    mostrar('[data-discrimina]', d);
    mostrar('[data-sin-discriminar]', !d);
    $('[data-aviso-iva]').textContent = d
      ? (inscripta ? 'Comprobante con IVA discriminado: el IVA y las percepciones son crédito fiscal y no van al costo.' : 'La empresa no es responsable inscripta: el IVA no se computa y va al costo.')
      : (gs ? 'Gasto sin comprobante: no va al IVA compras; el importe completo es el costo.' : 'Sin IVA discriminado (B, C o tique): el importe completo es el costo.');
    calcular();
  }

  // IVA propuesto por alícuota (si el usuario no lo tocó)
  $$('[data-alicuota]').forEach(function (tr) {
    var a = parseFloat(tr.getAttribute('data-alicuota')), neto = $('[data-neto]', tr), iva = $('[data-iva]', tr);
    if (iva.value !== '') iva.dataset.manual = '1';
    neto.addEventListener('input', function () {
      if (iva.dataset.manual !== '1') iva.value = num(neto.value) ? campo(r2(num(neto.value) * a / 100)) : '';
      calcular();
    });
    iva.addEventListener('input', function () { iva.dataset.manual = iva.value === '' ? '' : '1'; calcular(); });
  });

  var costoActual = 0;
  function calcular() {
    var total = 0, iva = 0, perc = 0;
    if (discrimina()) {
      $$('[data-alicuota]').forEach(function (tr) { total += num($('[data-neto]', tr).value); iva += num($('[data-iva]', tr).value); });
      total += num(form.elements.no_gravado.value) + num(form.elements.exento.value) + iva;
    } else {
      total += num(form.elements.importe.value);
    }
    $$('[data-tributos] tbody tr').forEach(function (tr) { perc += num($('input[name$="[importe]"]', tr).value); });
    total = r2(total + perc);
    costoActual = r2(total - perc - (inscripta && discrimina() ? iva : 0));
    $('[data-total]').textContent = plata(total);
    $('[data-costo]').textContent = plata(costoActual);
    var imp = $('[data-impreso]').value, ctl = $('[data-control]');
    if (imp === '' || total === 0) ctl.innerHTML = '';
    else if (Math.abs(num(imp) - total) <= 0.011) ctl.innerHTML = '<span class="pill pill-ok">coincide</span>';
    else ctl.innerHTML = '<span class="pill pill-mal">diferencia ' + plata(r2(num(imp) - total)) + '</span>';
    repartoCalc();
  }

  // ---------------- percepciones
  function reindexar(tbody, prefijo) {
    $$('tr', tbody).forEach(function (tr, i) {
      $$('[name^="' + prefijo + '["]', tr).forEach(function (el) { el.name = el.name.replace(new RegExp('^' + prefijo + '\\[\\d+\\]'), prefijo + '[' + i + ']'); });
    });
  }
  var tribBody = $('[data-tributos] tbody');
  $('[data-agregar-trib]').addEventListener('click', function () {
    var tr = tribBody.rows[0].cloneNode(true);
    $$('input', tr).forEach(function (i) { i.value = ''; });
    tribBody.appendChild(tr);
    reindexar(tribBody, 'trib');
    $('input[name$="[importe]"]', tr).focus();
  });

  // ---------------- reparto
  var simple = $('[data-simple]'), reparto = $('[data-reparto]'), filas = $('[data-filas-reparto]');
  function repartoCalc() {
    if (reparto.hidden) return;
    var trs = $$('tr', filas), suma = 0, resto = costoActual;
    trs.forEach(function (tr, i) {
      var p = num($('[data-pct]', tr).value);
      suma += p;
      var imp = i === trs.length - 1 ? r2(resto) : r2(costoActual * p / 100);
      resto -= imp;
      $('[data-imp]', tr).textContent = campo(imp);
    });
    suma = r2(suma);
    $('[data-suma-pct]').textContent = campo(suma) + ' %';
    $('[data-suma-imp]').textContent = campo(costoActual);
    $('[data-estado-reparto]').innerHTML = Math.abs(suma - 100) < 0.005 ? '<span class="pill pill-ok">completo</span>'
      : '<span class="pill pill-mal">' + (suma < 100 ? 'falta ' + campo(r2(100 - suma)) : 'sobra ' + campo(r2(suma - 100))) + ' %</span>';
  }
  function nuevaFila(centro, concepto, pct) {
    var tr = filas.rows[0].cloneNode(true);
    $$('input, select', tr).forEach(function (i) { i.disabled = false; });
    $('select[name$="[centro]"]', tr).value = String(centro || form.getAttribute('data-general'));
    $('select[name$="[concepto]"]', tr).value = String(concepto || $('[data-concepto]').value);
    $('[data-pct]', tr).value = pct === undefined ? '' : campo(pct).replace(/,00$/, '');
    filas.appendChild(tr);
    reindexar(filas, 'rep');
    return tr;
  }
  function modoReparto(si) {
    simple.hidden = si;
    $$('select', simple).forEach(function (s) { s.disabled = si; });
    reparto.hidden = !si;
    $$('input, select', reparto).forEach(function (i) { i.disabled = !si; });
    if (si) repartoCalc();
  }
  $('[data-repartir]').addEventListener('click', function () {
    var c = $('[data-centro]').value, k = $('[data-concepto]').value;
    while (filas.rows.length > 1) filas.deleteRow(1);
    $('select[name$="[centro]"]', filas.rows[0]).value = c;
    $('select[name$="[concepto]"]', filas.rows[0]).value = k;
    $('[data-pct]', filas.rows[0]).value = '100';
    modoReparto(true);
    var tr = nuevaFila(null, k, undefined);
    $('[data-pct]', filas.rows[0]).value = '50';
    $('[data-pct]', tr).value = '50';
    repartoCalc();
    $('select', tr).focus();
  });
  $('[data-un-centro]').addEventListener('click', function () {
    $('[data-centro]').value = $('select[name$="[centro]"]', filas.rows[0]).value;
    $('[data-concepto]').value = $('select[name$="[concepto]"]', filas.rows[0]).value;
    modoReparto(false);
  });
  $('[data-agregar-centro]').addEventListener('click', function () {
    var suma = $$('[data-pct]', filas).reduce(function (a, i) { return a + num(i.value); }, 0);
    var tr = nuevaFila(null, null, suma < 100 ? r2(100 - suma) : undefined);
    $('select', tr).focus();
    repartoCalc();
  });
  form.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-quitar]');
    if (!b) return;
    var tbody = b.closest('tbody');
    if (tbody.rows.length <= 1) { $$('input', b.closest('tr')).forEach(function (i) { i.value = ''; }); calcular(); return; }
    b.closest('tr').remove();
    reindexar(tbody, tbody === filas ? 'rep' : 'trib');
    calcular();
  });
  form.addEventListener('input', function (ev) {
    if (ev.target.matches('[data-pct]')) repartoCalc();
    else if (ev.target.matches('[data-suma], [data-impreso], input[name^="trib"]')) calcular();
  });
  var selPl = $('[data-plantilla]');
  if (selPl) selPl.addEventListener('change', function () {
    var pl = plantillas.filter(function (x) { return String(x.id) === selPl.value; })[0];
    if (!pl) return;
    var concepto = $('select[name$="[concepto]"]', filas.rows[0]).value;
    while (filas.rows.length > 1) filas.deleteRow(1);
    pl.detalle.forEach(function (d, i) {
      var tr = i === 0 ? filas.rows[0] : nuevaFila(d.centro, concepto, d.porcentaje);
      if (i === 0) { $('select[name$="[centro]"]', tr).value = String(d.centro); $('[data-pct]', tr).value = campo(d.porcentaje).replace(/,00$/, ''); }
    });
    repartoCalc();
  });
  var chkPl = $('[data-guardar-pl]');
  if (chkPl) chkPl.addEventListener('change', function () { var n = $('[data-nombre-pl]'); n.hidden = !chkPl.checked; if (chkPl.checked) n.focus(); });

  // ---------------- forma de pago y vencimiento
  function formaVista() {
    var f = ($('[data-forma]:checked') || {}).value;
    $$('[data-si-forma]').forEach(function (b) {
      var si = b.getAttribute('data-si-forma') === f;
      b.hidden = !si;
      $$('input', b).forEach(function (i) { i.disabled = !si; });
    });
  }
  $$('[data-forma]').forEach(function (r) { r.addEventListener('change', formaVista); });
  var vence = $('[data-vence]');
  if (vence.value !== '') vence.dataset.manual = '1';
  vence.addEventListener('input', function () { vence.dataset.manual = vence.value === '' ? '' : '1'; });
  function proponerVence() {
    if (vence.dataset.manual === '1') return;
    var m = String(form.elements.fecha.value).match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (!m) return;
    var d = new Date(Number(m[3]), Number(m[2]) - 1, Number(m[1]) + plazo);
    vence.value = ('0' + d.getDate()).slice(-2) + '/' + ('0' + (d.getMonth() + 1)).slice(-2) + '/' + d.getFullYear();
  }
  form.elements.fecha.addEventListener('change', proponerVence);

  // ---------------- lo que propone el proveedor
  function letraPara(iva) {
    if (iva === 1) return inscripta ? 'A' : 'B';
    return 'C';
  }
  function marcarAuto(el) { el.classList.add('auto'); el.addEventListener('change', function () { el.classList.remove('auto'); }, { once: true }); }
  form.addEventListener('picker:elegido', function (ev) {
    var p = ev.detail;
    plazo = p.plazo_dias || 0;
    if (tipo.value !== 'GS') { letra.value = letraPara(p.iva); marcarAuto(letra); }
    if (p.centro_id) { $('[data-centro]').value = String(p.centro_id); marcarAuto($('[data-centro]')); }
    if (p.concepto_id) { $('[data-concepto]').value = String(p.concepto_id); marcarAuto($('[data-concepto]')); }
    vence.dataset.manual = '';
    proponerVence();
    vista();
    var pv = form.elements.punto_venta;
    if (pv && !pv.disabled) pv.focus();
  });
  var ini = document.getElementById('prov-inicial');
  if (ini) {
    var p0 = JSON.parse(ini.textContent);
    plazo = p0.plazo_dias || 0;
    proponerVence();
  }

  tipo.addEventListener('change', vista);
  letra.addEventListener('change', vista);
  if (!reparto.hidden) modoReparto(true); else modoReparto(false);
  formaVista();
  vista();
})();
