/*
 * Buscador genérico: cualquier <input data-buscador-url="..."> sugiere coincidencias mientras se escribe (código, código de barras, SKU o
 * palabras de la descripción) en una lista DEBAJO del campo; el usuario SIEMPRE elige una (mouse o ↑ ↓ Enter). Al elegir, el campo queda con el
 * código y debajo se muestra la descripción. Enter nunca envía el formulario desde el buscador: si lo escrito es un código exacto
 * (pistola de códigos) lo resuelve; si no, muestra las opciones. La respuesta es una lista de {codigo, descripcion, sku, ean13, precio}.
 */
(function () {
  'use strict';
  var lista = document.createElement('ul');
  lista.className = 'sugerencias';
  lista.setAttribute('role', 'listbox');
  lista.hidden = true;
  document.body.appendChild(lista);
  var activo = null, opciones = [], marcada = -1, timer = null, pedido = 0;

  function cerrar() { lista.hidden = true; lista.innerHTML = ''; opciones = []; marcada = -1; }
  function etiqueta(inp) {
    var e = inp.parentNode.querySelector('[data-buscador-etiqueta]');
    if (!e) { e = document.createElement('small'); e.setAttribute('data-buscador-etiqueta', ''); e.className = 'muted'; inp.insertAdjacentElement('afterend', e); }
    return e;
  }
  function elegir(inp, a) {
    inp.value = a.codigo;
    etiqueta(inp).textContent = a.descripcion || '';
    cerrar();
    inp.dispatchEvent(new CustomEvent('buscador:elegido', { bubbles: true, detail: a }));
  }
  function marcar(i) {
    var lis = lista.children;
    if (!lis.length) return;
    marcada = (i + lis.length) % lis.length;
    for (var k = 0; k < lis.length; k++) lis[k].setAttribute('aria-selected', k === marcada ? 'true' : 'false');
    lis[marcada].scrollIntoView({ block: 'nearest' });
  }
  function mostrar(inp, arr) {
    opciones = arr; marcada = -1; lista.innerHTML = '';
    if (!arr.length) {
      var vacio = document.createElement('li'); vacio.textContent = 'Sin coincidencias'; vacio.className = 'muted'; lista.appendChild(vacio);
    }
    arr.forEach(function (a) {
      var li = document.createElement('li');
      li.setAttribute('role', 'option');
      var cod = document.createElement('b'); cod.textContent = a.codigo;
      var det = document.createElement('span'); det.textContent = ' ' + (a.descripcion || a.razon_social || '');
      var ex = document.createElement('small');
      ex.textContent = [a.sku ? 'SKU ' + a.sku : '', a.ean13 ? 'EAN ' + a.ean13 : '', a.precio ? '$ ' + a.precio : ''].filter(Boolean).join(' · ');
      li.appendChild(cod); li.appendChild(det); li.appendChild(document.createElement('br')); li.appendChild(ex);
      li.addEventListener('mousedown', function (ev) { ev.preventDefault(); elegir(inp, a); });
      lista.appendChild(li);
    });
    var r = inp.getBoundingClientRect();
    lista.style.left = Math.max(8, r.left) + 'px';
    lista.style.top = (r.bottom + 2) + 'px';
    lista.style.minWidth = Math.max(r.width, 320) + 'px';
    lista.hidden = false;
  }
  function buscar(inp, exacto) {
    var n = ++pedido;
    return fetch(inp.getAttribute('data-buscador-url') + '?' + (exacto ? 'exacto=1&' : '') + 'q=' + encodeURIComponent(inp.value.trim()), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (arr) { return n === pedido ? arr : null; });
  }

  document.addEventListener('input', function (ev) {
    var inp = ev.target.closest && ev.target.closest('[data-buscador-url]');
    if (!inp) return;
    activo = inp;
    etiqueta(inp).textContent = '';
    clearTimeout(timer);
    if (inp.value.trim().length < 2) { cerrar(); return; }
    timer = setTimeout(function () { buscar(inp, false).then(function (arr) { if (arr && document.activeElement === inp) mostrar(inp, arr); }); }, 200);
  });
  document.addEventListener('keydown', function (ev) {
    var inp = ev.target.closest && ev.target.closest('[data-buscador-url]');
    if (!inp) return;
    if (ev.key === 'ArrowDown' && !lista.hidden) { ev.preventDefault(); marcar(marcada + 1); }
    else if (ev.key === 'ArrowUp' && !lista.hidden) { ev.preventDefault(); marcar(marcada - 1); }
    else if (ev.key === 'Escape') { cerrar(); }
    else if (ev.key === 'Enter') {
      ev.preventDefault();
      clearTimeout(timer); pedido++;
      if (!lista.hidden && marcada >= 0 && opciones[marcada]) { elegir(inp, opciones[marcada]); return; }
      if (!lista.hidden && opciones.length === 1) { elegir(inp, opciones[0]); return; }
      if (inp.value.trim() === '') return;
      buscar(inp, true).then(function (arr) {   // lo escrito como código exacto (pistola)
        if (arr && arr.length) elegir(inp, arr[0]);
        else buscar(inp, false).then(function (a2) { if (a2) mostrar(inp, a2); });
      });
    }
  });
  document.addEventListener('focusout', function (ev) {
    if (ev.target.closest && ev.target.closest('[data-buscador-url]')) setTimeout(cerrar, 150);
  });
  window.addEventListener('scroll', cerrar, true);
})();
