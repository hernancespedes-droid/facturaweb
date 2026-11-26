/* Calendario para los campos de fecha (0.64.0, D-152).
 *
 * Todo <input data-fecha> (texto dd/mm/aaaa) suma a su derecha un botón con un calendario: se elige el día con el mouse,
 * el dedo o el teclado y queda escrito como dd/mm/aaaa (lo que leen Valida::fecha y los filtros). Se puede seguir escribiendo a mano.
 * En los filtros con «Desde» y «Hasta» (name desde/hasta o data-fecha="desde|hasta") el calendario ofrece además atajos
 * (hoy, ayer, esta semana, este mes, mes pasado, este año) que completan los dos campos.
 * En el celular se abre como una hoja desde abajo. Teclado: flechas mueven el día, RePág/AvPág el mes, Enter elige, Esc cierra.
 * Los campos que una pantalla agrega o copia después (renglones nuevos) se preparan solos.
 */
(function () {
  'use strict';

  var MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  var DIAS = ['lu', 'ma', 'mi', 'ju', 'vi', 'sá', 'do'];
  var ICONO = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
    + '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/><path d="M8 14h2M14 14h2M8 17.5h2"/></svg>';

  var pop = null;      // ventana abierta
  var campo = null;    // input que la abrió
  var vista = null;    // primer día del mes que se ve
  var foco = null;     // día con el foco del teclado

  function dos(n) { return (n < 10 ? '0' : '') + n; }
  function texto(d) { return dos(d.getDate()) + '/' + dos(d.getMonth() + 1) + '/' + d.getFullYear(); }
  function hoy() { var d = new Date(); return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }
  function igual(a, b) { return !!a && !!b && a.getTime() === b.getTime(); }
  function sumarDias(d, n) { return new Date(d.getFullYear(), d.getMonth(), d.getDate() + n); }
  function sumarMeses(d, n) {
    var ult = new Date(d.getFullYear(), d.getMonth() + n + 1, 0).getDate();
    return new Date(d.getFullYear(), d.getMonth() + n, Math.min(d.getDate(), ult));
  }

  /** dd/mm/aaaa, d/m/aa, ddmmaaaa o aaaa-mm-dd → Date (o null). */
  function leer(v) {
    v = String(v || '').trim();
    var m, d, me, a;
    if ((m = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(v))) { a = +m[1]; me = +m[2]; d = +m[3]; }
    else if ((m = /^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2}|\d{4})$/.exec(v))) { d = +m[1]; me = +m[2]; a = +m[3]; }
    else if ((m = /^(\d{2})(\d{2})(\d{4})$/.exec(v))) { d = +m[1]; me = +m[2]; a = +m[3]; }
    else { return null; }
    if (a < 100) { a += 2000; }
    var f = new Date(a, me - 1, d);
    return (f.getFullYear() === a && f.getMonth() === me - 1 && f.getDate() === d && a >= 1900 && a <= 2100) ? f : null;
  }

  /** El otro campo del par desde/hasta del mismo formulario (o null). */
  function par(input) {
    var rol = rolDe(input);
    if (!rol || !input.form) { return null; }
    var otro = rol === 'desde' ? 'hasta' : 'desde';
    var lista = input.form.querySelectorAll('input[data-fecha]');
    for (var i = 0; i < lista.length; i++) { if (lista[i] !== input && rolDe(lista[i]) === otro) { return lista[i]; } }
    return null;
  }
  function rolDe(input) {
    var r = input.getAttribute('data-fecha');
    if (r === 'desde' || r === 'hasta') { return r; }
    return input.name === 'desde' || input.name === 'hasta' ? input.name : '';
  }

  function poner(input, fecha) {
    input.value = fecha ? texto(fecha) : '';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function atajos(input) {
    var h = hoy(), lunes = sumarDias(h, -((h.getDay() + 6) % 7));
    return [
      ['Hoy', h, h],
      ['Ayer', sumarDias(h, -1), sumarDias(h, -1)],
      ['Esta semana', lunes, h],
      ['Este mes', new Date(h.getFullYear(), h.getMonth(), 1), h],
      ['Mes pasado', new Date(h.getFullYear(), h.getMonth() - 1, 1), new Date(h.getFullYear(), h.getMonth(), 0)],
      ['Últimos 30 días', sumarDias(h, -29), h],
      ['Este año', new Date(h.getFullYear(), 0, 1), h],
    ];
  }

  function cerrar(devolverFoco) {
    if (!pop) { return; }
    pop.remove();
    var fondo = document.querySelector('.cal-fondo');
    if (fondo) { fondo.remove(); }
    var c = campo;
    pop = null; campo = null;
    if (c) {
      var b = c.parentNode.querySelector('.cal-btn');
      if (b) { b.setAttribute('aria-expanded', 'false'); }
      if (devolverFoco) { c.focus(); }
    }
  }

  function elegir(fecha) {
    var c = campo;
    poner(c, fecha);
    cerrar(true);
  }

  function dibujar() {
    var elegido = leer(campo.value), h = hoy();
    var anio = vista.getFullYear(), mes = vista.getMonth();
    if (!foco || foco.getMonth() !== mes || foco.getFullYear() !== anio) {
      foco = elegido && elegido.getMonth() === mes && elegido.getFullYear() === anio ? elegido
        : (h.getMonth() === mes && h.getFullYear() === anio ? h : new Date(anio, mes, 1));
    }
    var opcMes = MESES.map(function (n, i) { return '<option value="' + i + '"' + (i === mes ? ' selected' : '') + '>' + n + '</option>'; }).join('');
    var opcAnio = '';
    for (var a = Math.max(1990, Math.min(anio, h.getFullYear()) - 15); a <= Math.max(anio, h.getFullYear()) + 3; a++) {
      opcAnio += '<option' + (a === anio ? ' selected' : '') + '>' + a + '</option>';
    }
    var html = '<div class="cal-cab">'
      + '<button type="button" class="cal-nav" data-mes="-1" aria-label="Mes anterior">‹</button>'
      + '<select class="cal-mes" aria-label="Mes">' + opcMes + '</select>'
      + '<select class="cal-anio" aria-label="Año">' + opcAnio + '</select>'
      + '<button type="button" class="cal-nav" data-mes="1" aria-label="Mes siguiente">›</button></div>'
      + '<table class="cal-grilla" role="grid" data-grilla="no"><thead><tr>'
      + DIAS.map(function (d) { return '<th scope="col">' + d + '</th>'; }).join('') + '</tr></thead><tbody>';
    var inicio = sumarDias(new Date(anio, mes, 1), -((new Date(anio, mes, 1).getDay() + 6) % 7));
    for (var s = 0; s < 6; s++) {
      html += '<tr>';
      for (var d = 0; d < 7; d++) {
        var f = sumarDias(inicio, s * 7 + d);
        var cls = ['cal-dia'];
        if (f.getMonth() !== mes) { cls.push('fuera'); }
        if (igual(f, h)) { cls.push('hoy'); }
        if (igual(f, elegido)) { cls.push('elegido'); }
        if (d >= 5) { cls.push('finde'); }
        html += '<td><button type="button" class="' + cls.join(' ') + '" data-dia="' + texto(f) + '" tabindex="' + (igual(f, foco) ? '0' : '-1') + '"'
          + (igual(f, elegido) ? ' aria-pressed="true"' : '') + ' aria-label="' + f.getDate() + ' de ' + MESES[f.getMonth()] + ' de ' + f.getFullYear() + '">'
          + f.getDate() + '</button></td>';
      }
      html += '</tr>';
    }
    html += '</tbody></table><div class="cal-pie">'
      + '<button type="button" class="btn btn-sm" data-dia="' + texto(h) + '">Hoy</button>'
      + (campo.required ? '' : '<button type="button" class="btn btn-sm btn-ghost" data-borrar>Borrar</button>')
      + '<button type="button" class="btn btn-sm btn-ghost" data-cerrar>Cerrar</button></div>';
    var otro = par(campo);
    if (otro) {
      html += '<div class="cal-atajos" aria-label="Períodos rápidos">' + atajos(campo).map(function (x, i) {
        return '<button type="button" class="cal-atajo" data-atajo="' + i + '">' + x[0] + '</button>';
      }).join('') + '</div>';
    }
    pop.querySelector('.cal-cuerpo').innerHTML = html;
  }

  function enfocar() {
    var b = pop && pop.querySelector('.cal-dia[tabindex="0"]');
    if (b) { b.focus(); }
  }

  function ubicar() {
    if (!pop || !campo) { return; }
    var celular = window.matchMedia('(max-width: 600px)').matches;
    pop.classList.toggle('cal-hoja', celular);
    if (celular) { pop.style.left = pop.style.top = ''; return; }
    var r = campo.getBoundingClientRect(), w = pop.offsetWidth, hgt = pop.offsetHeight;
    var x = Math.min(Math.max(8, r.left), window.innerWidth - w - 8);
    var y = r.bottom + 4;
    if (y + hgt > window.innerHeight - 8 && r.top - hgt - 4 > 8) { y = r.top - hgt - 4; }
    pop.style.left = (x + window.scrollX) + 'px';
    pop.style.top = (y + window.scrollY) + 'px';
  }

  function abrir(input) {
    if (campo === input) { cerrar(true); return; }
    cerrar(false);
    campo = input;
    var f = leer(input.value) || hoy();
    vista = new Date(f.getFullYear(), f.getMonth(), 1);
    foco = null;
    pop = document.createElement('div');
    pop.className = 'cal-pop';
    pop.setAttribute('role', 'dialog');
    var rotulo = input.closest('label');
    var nombre = rotulo && rotulo.querySelector('span') ? rotulo.querySelector('span').textContent.trim() : 'Fecha';
    pop.setAttribute('aria-label', 'Elegir fecha: ' + nombre);
    pop.innerHTML = '<div class="cal-titulo">' + nombre.replace(/[<>&]/g, '') + '</div><div class="cal-cuerpo"></div>';
    if (window.matchMedia('(max-width: 600px)').matches) {
      var fondo = document.createElement('div');
      fondo.className = 'cal-fondo';
      fondo.addEventListener('click', function () { cerrar(false); });
      document.body.appendChild(fondo);
    }
    document.body.appendChild(pop);
    dibujar();
    ubicar();
    var b = input.parentNode.querySelector('.cal-btn');
    if (b) { b.setAttribute('aria-expanded', 'true'); }
    pop.addEventListener('click', alClic);
    pop.addEventListener('change', alCambiar);
    pop.addEventListener('keydown', alTecla);
    enfocar();
  }

  function alClic(ev) {
    var t = ev.target.closest('button');
    if (!t) { return; }
    ev.preventDefault();
    if (t.hasAttribute('data-mes')) { vista = sumarMeses(vista, +t.getAttribute('data-mes')); foco = null; dibujar(); return; }
    if (t.hasAttribute('data-dia')) { elegir(leer(t.getAttribute('data-dia'))); return; }
    if (t.hasAttribute('data-borrar')) { elegir(null); return; }
    if (t.hasAttribute('data-cerrar')) { cerrar(true); return; }
    if (t.hasAttribute('data-atajo')) {
      var x = atajos(campo)[+t.getAttribute('data-atajo')], otro = par(campo);
      var desde = rolDe(campo) === 'desde' ? campo : otro, hasta = desde === campo ? otro : campo;
      poner(desde, x[1]);
      poner(hasta, x[2]);
      cerrar(true);
    }
  }

  function alCambiar(ev) {
    if (ev.target.classList.contains('cal-mes') || ev.target.classList.contains('cal-anio')) {
      vista = new Date(+pop.querySelector('.cal-anio').value, +pop.querySelector('.cal-mes').value, 1);
      foco = null;
      dibujar();
      pop.querySelector(ev.target.classList.contains('cal-mes') ? '.cal-mes' : '.cal-anio').focus();
    }
  }

  function alTecla(ev) {
    if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); cerrar(true); return; }
    if (ev.key === 'Tab') {
      // el foco queda dentro de la ventana
      var lista = Array.prototype.filter.call(pop.querySelectorAll('button, select'), function (b) { return b.tabIndex !== -1; });
      var i = lista.indexOf(document.activeElement);
      if (ev.shiftKey && i <= 0) { ev.preventDefault(); lista[lista.length - 1].focus(); }
      else if (!ev.shiftKey && i === lista.length - 1) { ev.preventDefault(); lista[0].focus(); }
      return;
    }
    var enDia = document.activeElement && document.activeElement.classList.contains('cal-dia');
    if (ev.key === 'Enter') {
      // carga.js escucha Enter para pasar de campo: acá elige (o hace lo del botón) y no sigue.
      ev.preventDefault();
      if (document.activeElement && document.activeElement.tagName === 'BUTTON') { document.activeElement.click(); }
      return;
    }
    if (!enDia) { return; }
    var mov = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[ev.key];
    var actual = leer(document.activeElement.getAttribute('data-dia'));
    var nuevo = null;
    if (mov) { nuevo = sumarDias(actual, mov); }
    else if (ev.key === 'PageUp') { nuevo = sumarMeses(actual, ev.shiftKey ? -12 : -1); }
    else if (ev.key === 'PageDown') { nuevo = sumarMeses(actual, ev.shiftKey ? 12 : 1); }
    else if (ev.key === 'Home') { nuevo = sumarDias(actual, -((actual.getDay() + 6) % 7)); }
    else if (ev.key === 'End') { nuevo = sumarDias(actual, 6 - ((actual.getDay() + 6) % 7)); }
    if (!nuevo) { return; }
    ev.preventDefault();
    foco = nuevo;
    vista = new Date(nuevo.getFullYear(), nuevo.getMonth(), 1);
    dibujar();
    enfocar();
  }

  function preparar(raiz) {
    (raiz || document).querySelectorAll('input[data-fecha]').forEach(function (input) {
      if (input.parentNode && input.parentNode.classList && input.parentNode.classList.contains('cal-caja')) { return; } // ya está (o es un renglón copiado)
      if (!input.getAttribute('autocomplete')) { input.setAttribute('autocomplete', 'off'); }
      var caja = document.createElement('span');
      caja.className = 'cal-caja';
      input.parentNode.insertBefore(caja, input);
      caja.appendChild(input);
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'btn-icono cal-btn';
      b.title = 'Elegir en el calendario';
      b.setAttribute('aria-label', 'Elegir en el calendario');
      b.setAttribute('aria-haspopup', 'dialog');
      b.setAttribute('aria-expanded', 'false');
      b.tabIndex = -1; // con Tab se sigue de campo en campo; el calendario se abre con el botón, Alt+↓ o F4
      b.innerHTML = ICONO;
      if (input.disabled || input.readOnly) { b.disabled = true; }
      caja.appendChild(b);
    });
  }

  // Todo delegado: también sirve para los renglones que las pantallas copian (cloneNode) o agregan después.
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest && ev.target.closest('.cal-btn');
    if (!b) { return; }
    var input = b.parentNode.querySelector('input[data-fecha]');
    if (input && !input.disabled && !input.readOnly) { ev.preventDefault(); abrir(input); }
  });
  document.addEventListener('keydown', function (ev) {
    var input = ev.target;
    if (!input.matches || !input.matches('input[data-fecha]')) { return; }
    if ((ev.altKey && ev.key === 'ArrowDown') || ev.key === 'F4') { ev.preventDefault(); abrir(input); }
  });
  // Al salir del campo, «1/3/26» queda «01/03/2026».
  document.addEventListener('focusout', function (ev) {
    var input = ev.target;
    if (!input.matches || !input.matches('input[data-fecha]')) { return; }
    var f = leer(input.value);
    if (f && input.value !== texto(f)) { input.value = texto(f); }
  });
  if (window.MutationObserver) {
    new MutationObserver(function (cambios) {
      for (var i = 0; i < cambios.length; i++) {
        for (var j = 0; j < cambios[i].addedNodes.length; j++) {
          var n = cambios[i].addedNodes[j];
          if (n.nodeType === 1 && !(pop && pop.contains(n)) && (n.matches('input[data-fecha]') || n.querySelector('input[data-fecha]'))) { preparar(n.parentNode || n); }
        }
      }
    }).observe(document.documentElement, { childList: true, subtree: true });
  }

  document.addEventListener('mousedown', function (ev) {
    if (pop && !pop.contains(ev.target) && !(campo && campo.parentNode.contains(ev.target))) { cerrar(false); }
  });
  window.addEventListener('resize', ubicar);
  document.addEventListener('scroll', function (ev) { if (pop && !pop.contains(ev.target)) { ubicar(); } }, true);

  window.Calendario = { preparar: preparar, leer: leer, texto: texto, abrir: abrir, cerrar: cerrar };
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { preparar(); }); } else { preparar(); }
})();
