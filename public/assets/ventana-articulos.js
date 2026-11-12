/*
 * Ventana de búsqueda de artículos: lista los artículos activos (por descripción, de a 20) con un campo para filtrar por código, SKU, código de
 * barras o palabras. El usuario elige uno haciendo clic en la fila (o con el botón «Elegir»); nunca se elige solo.
 *   VentanaArticulos.abrir(urlBuscador, function (articulo) { ... })     urlBuscador = la ruta de buscar-articulo de la pantalla
 */
(function () {
  'use strict';
  var dlg = null, buscar, cuerpo, info, ant, sig, estado = { url: '', cb: null, pagina: 1, q: '' }, timer = null, pedido = 0;

  function crear() {
    dlg = document.createElement('dialog');
    dlg.className = 'ventana-articulos';
    dlg.innerHTML = '<form method="dialog" class="va-cab"><h2>Buscar artículo</h2><button class="btn btn-ghost" value="x" aria-label="Cerrar">Cerrar</button></form>' +
      '<input type="search" class="va-buscar" placeholder="Filtrar por código, SKU, código de barras o parte de la descripción" autocomplete="off">' +
      '<div class="va-tabla"><table class="table"><thead><tr><th>Código</th><th>Descripción</th><th>SKU / EAN</th><th class="num">Precio</th><th></th></tr></thead><tbody></tbody></table></div>' +
      '<div class="va-pie"><button type="button" class="btn btn-ghost" data-ant>‹ Anterior</button><span class="muted va-info"></span><button type="button" class="btn btn-ghost" data-sig>Siguiente ›</button></div>';
    document.body.appendChild(dlg);
    buscar = dlg.querySelector('.va-buscar'); cuerpo = dlg.querySelector('tbody'); info = dlg.querySelector('.va-info');
    ant = dlg.querySelector('[data-ant]'); sig = dlg.querySelector('[data-sig]');
    buscar.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { estado.q = buscar.value.trim(); estado.pagina = 1; cargar(); }, 250); });
    buscar.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') ev.preventDefault(); });
    ant.addEventListener('click', function () { if (estado.pagina > 1) { estado.pagina--; cargar(); } });
    sig.addEventListener('click', function () { estado.pagina++; cargar(); });
  }
  function elegir(a) { var cb = estado.cb; dlg.close(); if (cb) cb(a); }
  function cargar() {
    var n = ++pedido;
    fetch(estado.url + '?listar=1&pagina=' + estado.pagina + '&q=' + encodeURIComponent(estado.q), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (n !== pedido) return;
        cuerpo.innerHTML = '';
        if (!d.items.length) {
          var tr0 = document.createElement('tr'), td0 = document.createElement('td');
          td0.colSpan = 5; td0.className = 'muted'; td0.textContent = 'Sin coincidencias'; tr0.appendChild(td0); cuerpo.appendChild(tr0);
        }
        d.items.forEach(function (a) {
          var tr = document.createElement('tr'); tr.className = 'va-fila'; tr.tabIndex = 0;
          [a.codigo, a.descripcion, [a.sku, a.ean13].filter(Boolean).join(' · '), a.precio ? '$ ' + a.precio : ''].forEach(function (t, i) {
            var td = document.createElement('td'); td.textContent = t; if (i === 3) td.className = 'num'; tr.appendChild(td);
          });
          var tdb = document.createElement('td'), b = document.createElement('button');
          b.type = 'button'; b.className = 'btn btn-sm'; b.textContent = 'Elegir'; tdb.appendChild(b); tr.appendChild(tdb);
          tr.addEventListener('click', function () { elegir(a); });
          tr.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); elegir(a); } });
          cuerpo.appendChild(tr);
        });
        ant.disabled = estado.pagina <= 1; sig.disabled = !d.hay_mas;
        info.textContent = 'Página ' + estado.pagina;
      });
  }
  window.VentanaArticulos = {
    abrir: function (url, cb) {
      if (!dlg) crear();
      estado = { url: url, cb: cb, pagina: 1, q: '' };
      buscar.value = '';
      dlg.showModal();
      buscar.focus();
      cargar();
    }
  };
})();
