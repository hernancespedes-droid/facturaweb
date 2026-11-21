/*
 * «Cómo se paga» y la ventana «Cobrar venta» (D-137; factura, presupuesto, kiosco y cambio de mercadería).
 * Contado: el cobro se hace en una ventana con los medios de pago (se pueden mezclar: efectivo, tarjeta, cheque, billetera, una parte a cuenta
 * corriente) y un resumen con el total, lo pagado y el vuelto. El vuelto es solo informativo: si se paga de más (con cualquier medio, por
 * ejemplo por un recargo de la tarjeta) se muestra la diferencia y el operador sabe si la da. Lo único que frena es pagar de menos.
 * Con un solo medio el importe vacío es el total. Botones para cargar rápido lo que entrega el cliente (montos redondos y billetes).
 * Enter en un importe: si ya alcanza, confirma el cobro; si no, va al medio siguiente. El servidor vuelve a calcular todo.
 */
(function () {
  'use strict';
  var sec = document.querySelector('[data-pago]');
  if (!sec || !window.Vuelto) return;
  var V = window.Vuelto, num = V.num;
  function r2(v) { return Math.round((v + (v >= 0 ? 1e-9 : -1e-9)) * 100) / 100; }
  function miles(v) { return v.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function fmt(v) { return '$ ' + miles(v); }
  function totalActual() { return parseFloat(sec.getAttribute('data-total')) || 0; }
  var q = function (s) { return sec.querySelector(s); };
  var lineas = function () { return Array.prototype.slice.call(sec.querySelectorAll('[data-linea]')); };
  var dlg = q('[data-cobro]'), bloque = q('[data-contado]'), cuerpo = q('[data-lineas]'), plantilla = q('template[data-plantilla-pago]');
  var btnConfirmar = q('[data-confirmar-cobro]');
  var siguiente = lineas().length, activa = null, confirmado = false;
  var importeDe = function (l) { return l.querySelector('[data-importe-pago]'); };
  var opcion = function (l) { var s = l.querySelector('[data-fpago]'); return s.options[s.selectedIndex]; };
  var ICONOS = { '1': '$', '2': '▭', '3': '▭', '5': '✎', '6': '⧗', '10': '◎' };

  function contado() { var r = document.querySelector('[name="forma_pago"]:checked'); return !!r && r.value === '1'; }
  // el formulario que emite (los campos llevan form="…")
  var form = document.getElementById(q('[data-fpago]').getAttribute('form'));
  var btnEmitir = form ? form.querySelector('button[type="submit"]:not([formaction])') : null;

  /** Lo que paga cada medio (con uno solo y vacío, el total) y el resumen. */
  function estado() {
    var total = totalActual(), ls = lineas(), varias = ls.length > 1, pagado = 0, acepta = false;
    ls.forEach(function (l) {
      var v = num(importeDe(l).value);
      if (!varias && v === 0) v = total;
      l._paga = v;
      pagado += v;
      var o = opcion(l);
      if (o && (o.getAttribute('data-vuelto') === '1' || o.getAttribute('data-tipo') === '6')) acepta = true;
    });
    pagado = r2(pagado);
    return { total: total, pagado: pagado, dif: r2(pagado - total), acepta: acepta, varias: varias };
  }

  function actualizar() {
    var es = contado();
    bloque.hidden = !es;
    var st = estado(), ls = lineas();
    ls.forEach(function (l) {
      var o = opcion(l), tipo = o ? o.getAttribute('data-tipo') : '';
      l.querySelector('[data-cheque]').hidden = !(es && tipo === '5');
      l.querySelector('[data-icono]').textContent = ICONOS[tipo] || '•';
      l.querySelector('[data-quitar-pago]').hidden = !st.varias;
      importeDe(l).placeholder = st.varias ? '0,00' : 'El total';
    });
    // resumen de la ventana
    q('[data-r-pagado]').textContent = fmt(st.pagado);
    var caja = q('[data-r-caja]'), etq = q('[data-r-etiqueta]'), res = q('[data-r-vuelto]'), des = q('[data-v-desglose]');
    caja.className = 'cobro-vuelto';
    des.hidden = true;
    if (st.dif < 0) { etq.textContent = 'Falta'; res.textContent = fmt(-st.dif); caja.className += ' es-falta'; }
    else if (st.dif > 0) {
      etq.textContent = 'Vuelto'; res.textContent = fmt(st.dif); caja.className += ' es-vuelto';
      des.textContent = 'Para darlo: ' + V.desglose(st.dif).join(' · ') + '.'; des.hidden = false;
    } else { etq.textContent = 'Vuelto'; res.textContent = 'Sin vuelto'; caja.className += ' es-justo'; }
    q('[data-r-nota]').hidden = !(st.dif > 0);
    q('[data-r-nota]').textContent = st.acepta ? 'El vuelto es informativo: vos decidís si lo das.' : 'Ningún medio da vuelto: la diferencia puede ser un recargo (informativo).';
    btnConfirmar.disabled = st.dif < 0;
    btnConfirmar.textContent = st.dif < 0 ? 'Falta ' + fmt(-st.dif) : 'Confirmar cobro';
    // resumen corto en la página
    var partes = ls.map(function (l) { var o = opcion(l); return (o ? o.textContent.trim() : '') + ' ' + fmt(l._paga); });
    q('[data-resumen-corto]').textContent = partes.join(' · ') + (st.dif > 0 ? ' · vuelto ' + fmt(st.dif) : st.dif < 0 ? ' · falta ' + fmt(-st.dif) : '');
    botonesRapidos(st);
  }

  // ---- carga rápida de lo que entrega el cliente, en el medio activo (el último que se tocó; si no, el primero que da vuelto)
  var zonaB = q('[data-v-billetes]');
  V.BILLETES.slice().reverse().forEach(function (b) { zonaB.appendChild(boton('+' + V.corto(b), 'data-v-sumar', String(b))); });
  zonaB.appendChild(boton('Borrar', 'data-v-borrar', '1', 'vuelto-borrar'));
  function boton(txt, attr, val, extra) {
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'btn btn-ghost btn-sm vuelto-btn' + (extra ? ' ' + extra : '');
    b.textContent = txt; b.setAttribute(attr, val);
    return b;
  }
  function lineaActiva() {
    var ls = lineas();
    if (activa && ls.indexOf(activa) >= 0) return activa;
    return ls.filter(function (l) { var o = opcion(l); return o && o.getAttribute('data-vuelto') === '1'; })[0] || ls[0];
  }
  var ultimaSug = '';
  function botonesRapidos(st) {
    var l = lineaActiva();
    if (!l) return;
    var otros = st.pagado - l._paga, falta = r2(st.total - otros);
    var o = opcion(l);
    q('[data-medio-activo]').textContent = o ? '(' + o.textContent.trim().toLowerCase() + ')' : '';
    var lista = V.sugeridos(Math.max(0, falta)), clave = falta + ':' + lista.join(',');
    if (clave === ultimaSug) return;
    ultimaSug = clave;
    var z = q('[data-v-sugeridos]');
    z.innerHTML = '';
    if (falta > 0) z.appendChild(boton('Justo ' + fmt(falta), 'data-v-monto', String(falta), 'vuelto-justo'));
    lista.forEach(function (v) { z.appendChild(boton(V.corto(v), 'data-v-monto', String(v))); });
  }
  function poner(l, v) {
    var inp = importeDe(l);
    inp.value = v > 0 ? miles(r2(v)) : '';
    actualizar();
    inp.focus({ preventScroll: true });
  }
  dlg.addEventListener('click', function (ev) {
    var b = ev.target.closest('button');
    if (!b) return;
    var l = lineaActiva();
    if (b.hasAttribute('data-v-monto')) poner(l, parseFloat(b.getAttribute('data-v-monto')));
    else if (b.hasAttribute('data-v-sumar')) {
      var base = num(importeDe(l).value);
      poner(l, base + parseFloat(b.getAttribute('data-v-sumar')));
    } else if (b.hasAttribute('data-v-borrar')) poner(l, 0);
  });
  sec.addEventListener('focusin', function (ev) {
    var l = ev.target.closest && ev.target.closest('[data-linea]');
    if (l && l !== activa) { activa = l; ultimaSug = ''; actualizar(); }
  });

  // ---- medios: agregar, quitar
  function agregar() {
    var ls = lineas(), total = totalActual();
    // al pasar de uno a varios, el primero pasa a llevar su importe (el total) para poder repartirlo
    if (ls.length === 1 && num(importeDe(ls[0]).value) === 0) importeDe(ls[0]).value = miles(total);
    var st = estado();
    var div = document.createElement('div');
    div.innerHTML = plantilla.innerHTML.replace(/__N__/g, String(siguiente++));
    var nueva = div.firstElementChild;
    cuerpo.appendChild(nueva);
    if (st.dif < 0) importeDe(nueva).value = miles(-st.dif); // propone lo que falta
    activa = nueva; ultimaSug = '';
    actualizar();
    nueva.querySelector('[data-fpago]').focus();
  }
  q('[data-agregar-pago]').addEventListener('click', agregar);
  sec.addEventListener('click', function (ev) {
    if (ev.target.matches('[data-quitar-pago]')) { ev.target.closest('[data-linea]').remove(); activa = null; ultimaSug = ''; actualizar(); }
  });
  sec.addEventListener('input', actualizar);
  sec.addEventListener('change', actualizar);
  document.querySelectorAll('[name="forma_pago"]').forEach(function (x) { x.addEventListener('change', actualizar); });

  // ---- la ventana
  function abrir() {
    if (!dlg.open) { if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', ''); }
    actualizar();
    var l = lineaActiva(), inp = l ? importeDe(l) : null;
    if (inp) { inp.focus(); try { inp.select(); } catch (e) { /* nada */ } }
  }
  function cerrar() { if (dlg.open) { if (dlg.close) dlg.close(); else dlg.removeAttribute('open'); } }
  q('[data-abrir-cobro]').addEventListener('click', abrir);
  q('[data-cerrar-cobro]').addEventListener('click', cerrar);
  function confirmar() {
    var st = estado();
    if (st.dif < 0) { btnConfirmar.classList.remove('sacudir'); void btnConfirmar.offsetWidth; btnConfirmar.classList.add('sacudir'); return; }
    confirmado = true;
    cerrar();
    if (form.requestSubmit) form.requestSubmit(btnEmitir || undefined); else (btnEmitir || form).click();
  }
  btnConfirmar.addEventListener('click', confirmar);
  // Enter en la ventana: en un importe, si ya alcanza confirma; si no, pasa al medio siguiente (nunca envía solo)
  dlg.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter' || ev.isComposing || ev.target.tagName === 'BUTTON') return;
    ev.preventDefault();
    if (ev.target.matches('[data-importe-pago]')) {
      if (estado().dif >= 0) { confirmar(); return; }
      var ls = lineas(), i = ls.indexOf(ev.target.closest('[data-linea]'));
      if (i < ls.length - 1) importeDe(ls[i + 1]).focus(); else q('[data-agregar-pago]').focus();
    } else if (ev.target.matches('[data-fpago]')) {
      importeDe(ev.target.closest('[data-linea]')).focus();
    }
  });
  // Emitir al contado abre la ventana (Mercado Pago, que lleva su formaction, sigue directo)
  if (form) {
    form.addEventListener('submit', function (ev) {
      if (!contado() || confirmado || totalActual() <= 0 || sec.closest('[hidden]')) return;
      var sub = ev.submitter;
      if (sub && sub.hasAttribute('formaction')) return;
      ev.preventDefault();
      ev.stopImmediatePropagation();
      abrir();
    }, true);
  }
  sec.addEventListener('pago:total', actualizar); // el total puede cambiar (cambio de mercadería: la diferencia a cobrar)
  actualizar();
  // desde el kiosco (o si volvió con un error del cobro) la ventana se abre sola
  if (contado() && (sec.hasAttribute('data-abrir-solo') || sec.hasAttribute('data-con-errores'))) abrir();
})();
