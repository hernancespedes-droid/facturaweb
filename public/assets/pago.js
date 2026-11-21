/*
 * «Cómo se paga» (pantalla de revisión y cambio de mercadería): Contado muestra una o varias formas de pago (efectivo, tarjeta, cheque, billetera virtual,
 * hasta una parte a cuenta corriente) que tienen que sumar el total; con una sola, el importe puede quedar vacío (es el total). Si la forma acepta vuelto
 * (efectivo) se pide lo recibido y se avisa cuánto vuelto hay que dar. El servidor vuelve a validar todo.
 */
(function () {
  'use strict';
  var sec = document.querySelector('[data-pago]');
  if (!sec) return;
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
  function totalActual() { return parseFloat(sec.getAttribute('data-total')) || 0; }
  var q = function (s) { return sec.querySelector(s); };
  var lineas = function () { return Array.prototype.slice.call(sec.querySelectorAll('[data-linea]')); };
  var bloque = q('[data-contado]'), cuerpo = q('[data-lineas]'), aviso = q('[data-vuelto-aviso]'), resumen = q('[data-resumen-pago]');
  var btnAgregar = q('[data-agregar-pago]'), btnCompletar = q('[data-completar-pago]'), plantilla = q('template[data-plantilla-pago]');
  var siguiente = lineas().length;

  // calculadora de vuelto (vuelto.js) de cada línea que acepta vuelto: se arma la primera vez y se esconde si cambia la forma de pago
  function calculadora(l, acepta) {
    if (!window.Vuelto) return null;
    if (!l._vuelto && acepta) l._vuelto = window.Vuelto.crear(l.querySelector('[data-recibido]'), l.querySelector('[data-recibido-caja]'));
    if (l._vuelto) l._vuelto.caja.hidden = !acepta;
    return acepta ? l._vuelto : null;
  }
  // lo recibido no alcanza en alguna línea: no se emite (el servidor también lo rechaza, pero así no se pierde el paso)
  function faltaRecibido() {
    var r = document.querySelector('[name="forma_pago"]:checked');
    if (!r || r.value !== '1') return null;
    var malo = null;
    lineas().forEach(function (l) {
      var campo = l.querySelector('[data-recibido]');
      if (!malo && !l.querySelector('[data-recibido-caja]').hidden && campo.getAttribute('aria-invalid') === 'true') malo = campo;
    });
    return malo;
  }

  function actualizar() {
    var total = totalActual();
    var r = document.querySelector('[name="forma_pago"]:checked');
    var contado = !!r && r.value === '1';
    bloque.hidden = !contado;
    var ls = lineas();
    var varias = ls.length > 1;
    var suma = 0, msgs = [], vueltoTotal = 0;
    ls.forEach(function (l) {
      var o = l.querySelector('[data-fpago]').options[l.querySelector('[data-fpago]').selectedIndex];
      var tipo = o ? o.getAttribute('data-tipo') : '';
      l.querySelector('[data-cheque]').hidden = !(contado && tipo === '5');
      var acepta = contado && o && o.getAttribute('data-vuelto') === '1';
      l.querySelector('[data-recibido-caja]').hidden = !acepta;
      var imp = num(l.querySelector('[data-importe-pago]').value);
      if (!varias && imp === 0) imp = total; // una sola forma de pago: el importe vacío es el total
      suma += imp;
      var quitar = l.querySelector('[data-quitar-pago]');
      if (quitar) quitar.hidden = !varias;
      var calc = calculadora(l, acepta);
      if (acepta) {
        var rec = num(l.querySelector('[data-recibido]').value);
        if (calc) calc.actualizar(imp);
        if (rec > 0) {
          var dif = r2(rec - imp);
          if (dif > 0) { vueltoTotal += dif; msgs.push('DAR VUELTO AL CLIENTE: ' + fmt(dif) + ' (recibís ' + fmt(rec) + ' por ' + fmt(imp) + ')'); }
          else if (dif < 0) msgs.push('Falta cobrar ' + fmt(-dif) + ' para llegar a ' + fmt(imp) + '.');
        }
      }
    });
    aviso.hidden = msgs.length === 0;
    aviso.textContent = msgs.join(' · ');
    suma = r2(suma);
    resumen.hidden = !(contado && varias);
    btnCompletar.hidden = !(contado && varias);
    if (contado && varias) {
      var d = r2(total - suma);
      resumen.textContent = 'Suman ' + fmt(suma) + ' de ' + fmt(total) + (d > 0 ? ' · faltan ' + fmt(d) : d < 0 ? ' · se pasan ' + fmt(-d) : ' · cuadra');
      resumen.className = 'small ' + (d === 0 ? 'texto-ok' : 'texto-error');
    }
  }

  function agregar() {
    var ls = lineas();
    var total = totalActual();
    // al pasar de una a varias, la primera pasa a llevar su importe (el total) para poder repartirlo
    if (ls.length === 1 && num(ls[0].querySelector('[data-importe-pago]').value) === 0) {
      ls[0].querySelector('[data-importe-pago]').value = total.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    var div = document.createElement('div');
    div.innerHTML = plantilla.innerHTML.replace(/__N__/g, String(siguiente++));
    var nueva = div.firstElementChild;
    cuerpo.appendChild(nueva);
    nueva.querySelector('[data-importe-pago]').focus();
    actualizar();
  }
  function completar() {
    var ls = lineas();
    var otros = 0;
    ls.forEach(function (l, i) { if (i < ls.length - 1) otros += num(l.querySelector('[data-importe-pago]').value); });
    var falta = Math.max(0, r2(totalActual() - otros));
    var ult = ls[ls.length - 1].querySelector('[data-importe-pago]');
    ult.value = falta > 0 ? falta.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '';
    actualizar();
  }

  document.querySelectorAll('[name="forma_pago"]').forEach(function (x) { x.addEventListener('change', actualizar); });
  sec.addEventListener('input', actualizar);
  sec.addEventListener('change', actualizar);
  sec.addEventListener('click', function (ev) {
    if (ev.target.matches('[data-quitar-pago]')) { ev.target.closest('[data-linea]').remove(); actualizar(); }
  });
  btnAgregar.addEventListener('click', agregar);
  btnCompletar.addEventListener('click', completar);
  // Enter en «Recibido» con plata de menos no emite: marca lo que falta
  sec.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' && ev.target.matches('[data-recibido]') && ev.target.getAttribute('aria-invalid') === 'true') {
      ev.preventDefault();
      avisarFalta(ev.target);
    }
  });
  function avisarFalta(campo) {
    var caja = campo.closest('[data-linea]').querySelector('[data-vuelto-caja]');
    if (caja) { caja.classList.remove('sacudir'); void caja.offsetWidth; caja.classList.add('sacudir'); }
    campo.focus({ preventScroll: true });
  }
  var form = sec.querySelector('[data-recibido]') ? document.getElementById(sec.querySelector('[data-recibido]').getAttribute('form')) : null;
  if (form) {
    form.addEventListener('submit', function (ev) {
      var malo = faltaRecibido();
      if (malo) {
        ev.preventDefault();
        ev.stopImmediatePropagation();
        malo.scrollIntoView({ block: 'center' });
        avisarFalta(malo);
      }
    }, true);
  }
  sec.addEventListener('pago:total', actualizar); // el total puede cambiar (cambio de mercadería: la diferencia a cobrar)
  actualizar();
  // desde el kiosco el cajero va directo a «Recibido» (una sola forma que acepta vuelto)
  if (sec.hasAttribute('data-enfocar-recibido') && lineas().length === 1) {
    var rc = lineas()[0].querySelector('[data-recibido]');
    if (rc && !lineas()[0].querySelector('[data-recibido-caja]').hidden) { rc.scrollIntoView({ block: 'center' }); rc.focus({ preventScroll: true }); }
  }
})();
