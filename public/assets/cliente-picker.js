/*
 * Buscador de clientes de las pantallas de cuenta corriente (mismo comportamiento que el de la factura): se escribe nombre, código o CUIT,
 * se muestran las coincidencias debajo y el usuario SIEMPRE elige una (mouse, ↑ ↓ o Enter sobre la marcada). Nunca se toma la primera sola.
 * Marcado: <div data-picker data-url="…buscar-cliente"> con [data-cliente-q], [data-cliente] (oculto), [data-cliente-lista] y, opcional,
 * [data-lista-clientes] (la lupa). Si el formulario lleva data-enviar-al-elegir, al elegir el cliente se envía (pantallas de "primero el cliente").
 */
(function () {
  'use strict';
  function esc(t) { return String(t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  document.querySelectorAll('[data-picker][data-url]').forEach(function (picker) {
    var url = picker.getAttribute('data-url');
    var q = picker.querySelector('[data-cliente-q]');
    var hid = picker.querySelector('[data-cliente]');
    var lista = picker.querySelector('[data-cliente-lista]');
    var form = picker.closest('form');
    var timer = null, marcada = -1;

    function elegir(c) {
      hid.value = c.codigo;
      q.value = c.razon_social;
      lista.hidden = true;
      lista.innerHTML = '';
      if (form && form.hasAttribute('data-enviar-al-elegir')) form.submit();
    }
    function marcar(i) {
      var lis = lista.children;
      if (!lis.length) return;
      marcada = (i + lis.length) % lis.length;
      for (var k = 0; k < lis.length; k++) lis[k].setAttribute('aria-selected', k === marcada ? 'true' : 'false');
    }
    function buscar(txt) {
      fetch(url + '?q=' + encodeURIComponent(txt), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (arr) {
          lista.innerHTML = ''; marcada = -1;
          arr.forEach(function (c) {
            var li = document.createElement('li');
            li.setAttribute('role', 'option');
            li.tabIndex = 0;
            li.innerHTML = '<b>' + esc(c.razon_social) + '</b> <small>' + esc(c.codigo + (c.cuit ? ' · ' + c.cuit : '') + (c.localidad ? ' · ' + c.localidad : '')) + '</small>';
            li.addEventListener('click', function () { elegir(c); });
            li.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); elegir(c); } });
            li._cliente = c;
            lista.appendChild(li);
          });
          lista.hidden = arr.length === 0;
        });
    }
    q.addEventListener('input', function () {
      hid.value = ''; // si cambia el texto, hay que volver a elegir
      clearTimeout(timer);
      var t = q.value.trim();
      if (t.length < 2) { lista.hidden = true; return; }
      timer = setTimeout(function () { buscar(t); }, 200);
    });
    q.addEventListener('keydown', function (ev) {
      if (ev.key === 'ArrowDown') { ev.preventDefault(); marcar(marcada + 1); }
      else if (ev.key === 'ArrowUp') { ev.preventDefault(); marcar(marcada - 1); }
      else if (ev.key === 'Enter') {
        ev.preventDefault(); // Enter nunca envía el formulario ni elige solo: elige la marcada, o busca
        if (marcada >= 0 && lista.children[marcada]) elegir(lista.children[marcada]._cliente);
        else if (q.value.trim().length >= 1) buscar(q.value.trim());
      } else if (ev.key === 'Escape') { lista.hidden = true; }
    });
    document.addEventListener('click', function (ev) { if (!picker.contains(ev.target)) lista.hidden = true; });
    var lupa = picker.querySelector('[data-lista-clientes]');
    if (lupa) lupa.addEventListener('click', function () {
      if (window.VentanaLista) window.VentanaLista.clientes(url, function (c) { elegir(c); });
    });
  });
})();
