/*
 * 0.48.0: cargar datos sin que la página salte (pedido de Hernán).
 *
 * 1) Enter pasa al campo siguiente, como en el sistema de escritorio. En un formulario que graba (method="post") Enter en un campo
 *    ya no envía el formulario: el foco va al campo siguiente y, desde el último, al botón principal (un segundo Enter recién ahí envía).
 *    Así no se emite ni se graba nada por apretar Enter en un importe, y la página no se recarga a mitad de la carga.
 *    Quedan como siempre: las áreas de texto, los buscadores (que ya manejan su Enter), los formularios de un solo campo, los de
 *    filtro (method="get") y los que llevan data-enter="enviar" (ingreso, por ejemplo). Un campo puede pedir lo mismo con data-enter="enviar".
 *
 * 2) Si igual se envía y el servidor vuelve a la MISMA pantalla (grabar un renglón, un error de validación, cambiar un filtro),
 *    la página vuelve a donde estaba: misma altura y el cursor en el mismo campo. Si volvió con errores en los campos, va al primero.
 *    Los mensajes de arriba («Grabado», errores) se muestran además flotando, para verlos sin tener que subir.
 */
(function () {
  'use strict';
  var CLAVE = 'fw-posicion';
  var SALTEAR = /^(button|submit|reset|file|image|hidden)$/i;

  // ---------- 1) Enter = campo siguiente ----------
  function visible(el) {
    if (el.disabled || el.tabIndex < 0) return false;
    if (el.type === 'hidden' || el.closest('[hidden]')) return false;
    return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
  }
  function campos(form) {
    return Array.prototype.filter.call(form.elements, function (el) {
      return /^(INPUT|SELECT|TEXTAREA|BUTTON)$/.test(el.tagName) && visible(el) && !(el.readOnly && el.tagName !== 'BUTTON');
    });
  }
  function llenables(lista) {
    return lista.filter(function (el) { return el.tagName !== 'BUTTON' && !(el.tagName === 'INPUT' && SALTEAR.test(el.type)); });
  }
  function principal(form, lista) {
    var b = form.querySelector('button[type="submit"]:not([formaction]), button:not([type]):not([formaction]), input[type="submit"]');
    if (!b) b = lista.filter(function (el) { return el.tagName === 'BUTTON' && (el.type || 'submit') === 'submit'; })[0] || null;
    if (!b && form.id) b = document.querySelector('button[type="submit"][form="' + form.id + '"]');
    return b && visible(b) && !b.disabled ? b : null;
  }
  window.addEventListener('keydown', function (ev) {
    // window, en burbuja: corre después de los manejadores propios (buscadores, combos, kiosco), que ya cortan con preventDefault.
    if (ev.key !== 'Enter' || ev.defaultPrevented || ev.isComposing || ev.altKey || ev.ctrlKey || ev.metaKey || ev.shiftKey) return;
    var el = ev.target;
    if (!el || el.tagName !== 'INPUT' || SALTEAR.test(el.type)) return;
    var form = el.form;
    if (!form || (form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
    if (form.getAttribute('data-enter') === 'enviar' || el.getAttribute('data-enter') === 'enviar') return;
    if (el.closest('dialog[open]') && !form.closest('dialog')) return;
    var lista = campos(form);
    if (llenables(lista).length <= 1) return; // un solo campo: Enter envía, como siempre
    ev.preventDefault();
    var i = lista.indexOf(el), sig = null;
    for (var k = i + 1; k < lista.length; k++) {
      var c = lista[k];
      if (c.tagName === 'BUTTON' && c.type !== 'submit') continue;     // «+ Agregar», «✕»: no son el paso siguiente
      if (c.type === 'radio' && c.name === el.name) continue;
      sig = c; break;
    }
    if (!sig || sig.tagName === 'BUTTON' || (sig.tagName === 'INPUT' && SALTEAR.test(sig.type))) sig = principal(form, lista) || sig;
    if (!sig) return;
    sig.focus();
    if (sig.select && sig.tagName === 'INPUT' && /^(text|search|tel|number|email|url|password|)$/i.test(sig.type || '')) {
      try { sig.select(); } catch (e) { /* algunos tipos no dejan seleccionar */ }
    }
  });

  // ---------- 2) Volver a la misma altura ----------
  function guardar(form) {
    if (!form || (form.getAttribute('target') || '') === '_blank') return;
    var a = document.activeElement, nombre = '';
    if (a && a.form === form && a.name && a.tagName !== 'BUTTON') nombre = a.name;
    var mismos = nombre ? Array.prototype.slice.call(form.querySelectorAll('[name="' + nombre.replace(/"/g, '\\"') + '"]')) : [];
    try {
      sessionStorage.setItem(CLAVE, JSON.stringify({
        ruta: location.pathname, y: window.scrollY || window.pageYOffset || 0, t: Date.now(),
        form: form.id || '', campo: nombre, n: Math.max(0, mismos.indexOf(a))
      }));
    } catch (e) { /* sin sessionStorage: la página carga arriba, como antes */ }
  }
  document.addEventListener('submit', function (ev) { if (!ev.defaultPrevented) guardar(ev.target); });
  // form.submit() (selectores que envían solos) no dispara «submit»: se guarda igual.
  var enviarOriginal = HTMLFormElement.prototype.submit;
  HTMLFormElement.prototype.submit = function () { guardar(this); return enviarOriginal.apply(this, arguments); };

  function leer() {
    var g = null;
    try { g = JSON.parse(sessionStorage.getItem(CLAVE) || 'null'); sessionStorage.removeItem(CLAVE); } catch (e) { g = null; }
    if (!g || g.ruta !== location.pathname || Date.now() - g.t > 60000) return null;
    return g;
  }
  function flotarAvisos() {
    var avisos = document.querySelectorAll('main > .alerta');
    if (!avisos.length) return;
    var primero = avisos[0].getBoundingClientRect();
    if (primero.bottom > 0 && primero.top < window.innerHeight) return; // ya se ven
    var caja = document.createElement('div');
    caja.className = 'avisos-flotantes';
    caja.setAttribute('aria-live', 'polite');
    Array.prototype.forEach.call(avisos, function (a) {
      var c = a.cloneNode(true);
      c.removeAttribute('role');
      var x = document.createElement('button');
      x.type = 'button'; x.className = 'aviso-cerrar'; x.textContent = '✕';
      x.setAttribute('aria-label', 'Cerrar aviso');
      x.addEventListener('click', function () { c.remove(); if (!caja.children.length) caja.remove(); });
      c.appendChild(x);
      caja.appendChild(c);
      if (!/alerta-(error|warn|clave)/.test(a.className)) setTimeout(function () { c.remove(); if (!caja.children.length) caja.remove(); }, 6000);
    });
    document.body.appendChild(caja);
  }
  function restaurar() {
    var g = leer();
    if (!g) return;
    if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
    var error = Array.prototype.filter.call(document.querySelectorAll('main .has-error, main .field-error'), visible)[0];
    if (error) {
      var campoErr = (error.closest('.field') || error).querySelector('input:not([type="hidden"]), select, textarea');
      (error.closest('.field') || error).scrollIntoView({ block: 'center' });
      if (campoErr && visible(campoErr)) campoErr.focus({ preventScroll: true });
    } else {
      window.scrollTo(0, g.y);
      // Las grillas y los gráficos terminan de acomodarse después: si nadie movió la página, se vuelve a la altura guardada.
      var y0 = window.scrollY;
      window.addEventListener('load', function () { if (Math.abs(window.scrollY - y0) < 2 && Math.abs(window.scrollY - g.y) > 2) window.scrollTo(0, g.y); });
      var form = g.form ? document.getElementById(g.form) : null;
      var el = null;
      if (g.campo) {
        var todos = (form || document).querySelectorAll('[name="' + g.campo.replace(/"/g, '\\"') + '"]');
        el = todos[g.n] || todos[0] || null;
      }
      if (el && visible(el)) {
        el.focus({ preventScroll: true });
        var r = el.getBoundingClientRect();
        if (r.top < 0 || r.bottom > window.innerHeight) el.scrollIntoView({ block: 'center' });
      }
    }
    flotarAvisos();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', restaurar); else restaurar();
})();
