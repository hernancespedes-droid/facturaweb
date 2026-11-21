/*
 * Calculadora de vuelto para el operador (D-129): junto al campo «Recibido» de una forma de pago que acepta vuelto muestra, en grande, cuánto hay que
 * cobrar, cuánto entregó el cliente y el vuelto (o lo que falta), con botones para cargar lo recibido sin teclear: «Justo», los montos redondos más
 * cercanos (lo que suele entregar la gente) y billetes para sumar a medida que se cuentan. Sugiere con qué billetes dar el vuelto.
 * Solo ayuda en pantalla: lo recibido se escribe en el mismo campo y el servidor vuelve a calcular todo.
 *
 * Uso: var v = Vuelto.crear(campoRecibido, dondeInsertar); ...; v.actualizar(importeACobrar);
 */
(function () {
  'use strict';
  var BILLETES = [20000, 10000, 2000, 1000, 500, 200, 100];
  var DENOMINACIONES = [20000, 10000, 2000, 1000, 500, 200, 100, 50, 20, 10, 5, 2, 1];
  var REDONDEOS = [100, 500, 1000, 2000, 10000, 20000];

  function num(txt) {
    txt = String(txt || '').replace(/[\s$]/g, '');
    if (txt === '') return 0;
    if (txt.indexOf(',') >= 0) txt = txt.replace(/\./g, '').replace(',', '.');
    else if (/^\d{1,3}(\.\d{3})+$/.test(txt)) txt = txt.replace(/\./g, '');
    var v = parseFloat(txt);
    return isNaN(v) ? 0 : v;
  }
  function r2(v) { return Math.round((v + (v >= 0 ? 1e-9 : -1e-9)) * 100) / 100; }
  function miles(v) { return v.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function fmt(v) { return '$ ' + miles(v); }
  function corto(v) { return '$ ' + v.toLocaleString('es-AR', { maximumFractionDigits: 2 }); }

  /** Montos redondos por encima del importe que el cliente suele entregar (hasta 4, de menor a mayor). */
  function sugeridos(imp) {
    var out = [];
    if (imp <= 0) return out;
    REDONDEOS.forEach(function (m) {
      var v = Math.ceil(r2(imp) / m) * m;
      if (v <= imp + 0.004) v += m;
      if (out.indexOf(v) < 0) out.push(v);
    });
    out.sort(function (a, b) { return a - b; });
    return out.slice(0, 4);
  }

  /** Con qué billetes y monedas dar el vuelto (el menor número de piezas). */
  function desglose(v) {
    var partes = [], resto = Math.round(v * 100);
    DENOMINACIONES.forEach(function (d) {
      var c = Math.floor(resto / (d * 100));
      if (c > 0) { partes.push(c + ' de ' + corto(d)); resto -= c * d * 100; }
    });
    if (resto > 0) partes.push(fmt(resto / 100) + ' en centavos');
    return partes;
  }

  function boton(txt, attr, valor, extra) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'btn btn-ghost btn-sm vuelto-btn' + (extra ? ' ' + extra : '');
    b.textContent = txt;
    b.setAttribute(attr, valor);
    return b;
  }

  function crear(campo, despuesDe) {
    var caja = document.createElement('div');
    caja.className = 'vuelto-caja';
    caja.setAttribute('data-vuelto-caja', '');
    caja.innerHTML =
      '<div class="vuelto-cifras">' +
        '<div><span>A cobrar</span><b data-v-cobrar></b></div>' +
        '<div><span>Te entrega</span><b data-v-recibe></b></div>' +
        '<div class="vuelto-resultado" data-v-caja-resultado aria-live="polite"><span data-v-etiqueta>Vuelto</span><b data-v-resultado>—</b></div>' +
      '</div>' +
      '<div class="vuelto-botones" role="group" aria-label="Lo que entrega el cliente"><span class="small muted">Te entrega:</span><span data-v-sugeridos></span></div>' +
      '<div class="vuelto-botones" role="group" aria-label="Sumar billetes"><span class="small muted">Sumar billetes:</span><span data-v-billetes></span></div>' +
      '<p class="small vuelto-desglose" data-v-desglose hidden></p>';
    despuesDe.parentNode.insertBefore(caja, despuesDe.nextSibling);
    var q = function (s) { return caja.querySelector(s); };
    var zonaBilletes = q('[data-v-billetes]');
    BILLETES.slice().reverse().forEach(function (b) { zonaBilletes.appendChild(boton('+' + corto(b), 'data-v-sumar', String(b))); });
    zonaBilletes.appendChild(boton('Borrar', 'data-v-borrar', '1', 'vuelto-borrar'));
    var importe = 0, ultimaSugerencia = '';

    function poner(v) {
      campo.value = v > 0 ? miles(r2(v)) : '';
      campo.dispatchEvent(new Event('input', { bubbles: true }));
    }
    caja.addEventListener('click', function (ev) {
      var b = ev.target.closest('button');
      if (!b) return;
      if (b.hasAttribute('data-v-justo')) poner(importe);
      else if (b.hasAttribute('data-v-monto')) poner(parseFloat(b.getAttribute('data-v-monto')));
      else if (b.hasAttribute('data-v-sumar')) poner(num(campo.value) + parseFloat(b.getAttribute('data-v-sumar')));
      else if (b.hasAttribute('data-v-borrar')) poner(0);
      else return;
      campo.focus({ preventScroll: true });
    });

    function actualizar(imp) {
      importe = r2(imp);
      var rec = r2(num(campo.value));
      q('[data-v-cobrar]').textContent = fmt(importe);
      q('[data-v-recibe]').textContent = rec > 0 ? fmt(rec) : '—';
      var lista = sugeridos(importe), clave = importe + ':' + lista.join(',');
      if (clave !== ultimaSugerencia) { // se rearma solo si cambió el importe (no pierde el foco del botón tocado)
        ultimaSugerencia = clave;
        var z = q('[data-v-sugeridos]');
        z.innerHTML = '';
        z.appendChild(boton('Justo', 'data-v-justo', '1', 'vuelto-justo'));
        lista.forEach(function (v) { z.appendChild(boton(corto(v), 'data-v-monto', String(v))); });
      }
      var caj = q('[data-v-caja-resultado]'), etq = q('[data-v-etiqueta]'), res = q('[data-v-resultado]'), des = q('[data-v-desglose]');
      var dif = r2(rec - importe);
      caj.className = 'vuelto-resultado';
      des.hidden = true;
      campo.removeAttribute('aria-invalid');
      if (rec <= 0) { etq.textContent = 'Vuelto'; res.textContent = '—'; }
      else if (dif > 0) {
        etq.textContent = 'Dar de vuelto'; res.textContent = fmt(dif); caj.className += ' es-vuelto';
        des.textContent = 'Por ejemplo: ' + desglose(dif).join(' · ') + '.'; des.hidden = false;
      } else if (dif < 0) {
        etq.textContent = 'Faltan'; res.textContent = fmt(-dif); caj.className += ' es-falta';
        campo.setAttribute('aria-invalid', 'true');
      } else { etq.textContent = 'Justo'; res.textContent = 'Sin vuelto'; caj.className += ' es-justo'; }
      return { recibido: rec, vuelto: dif > 0 ? dif : 0, falta: dif < 0 && rec > 0 ? -dif : 0 };
    }
    return { caja: caja, actualizar: actualizar };
  }

  window.Vuelto = { crear: crear, sugeridos: sugeridos, desglose: desglose, num: num };
})();
