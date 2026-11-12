/*
 * Ventana «ver lista»: lista de a 20 con un campo para filtrar. El usuario elige una fila (clic, o Enter sobre ella, o el botón «Elegir»);
 * nunca se elige solo. Sirve para artículos y para clientes:
 *   VentanaLista.articulos(urlBuscador, function (articulo) { ... })   urlBuscador = la ruta de buscar-articulo de la pantalla
 *   VentanaLista.clientes(urlBuscador, function (cliente) { ... })     urlBuscador = la ruta de buscar-cliente
 * (VentanaArticulos.abrir es lo mismo que VentanaLista.articulos.) El servidor responde con ?listar=1&pagina=N&q=… → {items, hay_mas}.
 */
(function () {
  'use strict';
  var dlg = null, titulo, buscar, cuerpo, cabeza, info, ant, sig, cfg = null, estado = { pagina: 1, q: '' }, timer = null, pedido = 0;

  var PRESETS = {
    articulos: {
      titulo: 'Buscar artículo', placeholder: 'Filtrar por código, SKU, código de barras o parte de la descripción',
      columnas: [
        { rotulo: 'Código', valor: function (a) { return a.codigo; } },
        { rotulo: 'Descripción', valor: function (a) { return a.descripcion; } },
        { rotulo: 'SKU / EAN', valor: function (a) { return [a.sku, a.ean13].filter(Boolean).join(' · '); }, chica: true },
        { rotulo: 'Precio', valor: function (a) { return a.precio ? '$ ' + a.precio : ''; }, num: true }
      ]
    },
    clientes: {
      titulo: 'Buscar cliente', placeholder: 'Filtrar por nombre, código o CUIT',
      columnas: [
        { rotulo: 'Código', valor: function (c) { return c.codigo; } },
        { rotulo: 'Razón social', valor: function (c) { return c.razon_social; } },
        { rotulo: 'CUIT', valor: function (c) { return c.cuit; }, chica: true },
        { rotulo: 'Condición de IVA', valor: function (c) { return c.iva_texto; }, chica: true },
        { rotulo: 'Localidad', valor: function (c) { return c.localidad; }, chica: true }
      ]
    }
  };

  function crear() {
    dlg = document.createElement('dialog');
    dlg.className = 'ventana-articulos ventana-lista';
    dlg.innerHTML = '<form method="dialog" class="va-cab"><h2></h2><button class="btn btn-ghost" value="x" aria-label="Cerrar">Cerrar</button></form>' +
      '<input type="search" class="va-buscar" autocomplete="off">' +
      '<div class="va-tabla"><table class="table"><thead><tr></tr></thead><tbody></tbody></table></div>' +
      '<div class="va-pie"><button type="button" class="btn btn-ghost" data-ant>‹ Anterior</button><span class="muted va-info"></span><button type="button" class="btn btn-ghost" data-sig>Siguiente ›</button></div>';
    document.body.appendChild(dlg);
    titulo = dlg.querySelector('h2'); buscar = dlg.querySelector('.va-buscar'); cuerpo = dlg.querySelector('tbody'); cabeza = dlg.querySelector('thead tr'); info = dlg.querySelector('.va-info');
    ant = dlg.querySelector('[data-ant]'); sig = dlg.querySelector('[data-sig]');
    buscar.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { estado.q = buscar.value.trim(); estado.pagina = 1; cargar(); }, 250); });
    buscar.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') ev.preventDefault(); });
    ant.addEventListener('click', function () { if (estado.pagina > 1) { estado.pagina--; cargar(); } });
    sig.addEventListener('click', function () { estado.pagina++; cargar(); });
  }
  function elegir(x) { var cb = cfg.alElegir; dlg.close(); if (cb) cb(x); }
  function cargar() {
    var n = ++pedido;
    fetch(cfg.url + '?listar=1&pagina=' + estado.pagina + '&q=' + encodeURIComponent(estado.q), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (n !== pedido) return;
        var cols = cfg.columnas;
        cuerpo.innerHTML = '';
        if (!d.items.length) {
          var tr0 = document.createElement('tr'), td0 = document.createElement('td');
          td0.colSpan = cols.length + 1; td0.className = 'muted'; td0.textContent = 'Sin coincidencias'; tr0.appendChild(td0); cuerpo.appendChild(tr0);
        }
        d.items.forEach(function (x) {
          var tr = document.createElement('tr'); tr.className = 'va-fila'; tr.tabIndex = 0;
          cols.forEach(function (c) {
            var td = document.createElement('td'); td.textContent = c.valor(x) || '';
            if (c.num) td.className = 'num'; if (c.chica) td.setAttribute('data-chica', '');
            tr.appendChild(td);
          });
          var tdb = document.createElement('td'), b = document.createElement('button');
          b.type = 'button'; b.className = 'btn btn-sm'; b.textContent = 'Elegir'; tdb.appendChild(b); tr.appendChild(tdb);
          tr.addEventListener('click', function () { elegir(x); });
          tr.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); elegir(x); } });
          cuerpo.appendChild(tr);
        });
        ant.disabled = estado.pagina <= 1; sig.disabled = !d.hay_mas;
        info.textContent = 'Página ' + estado.pagina;
      });
  }
  function abrir(preset, url, cb) {
    if (!dlg) crear();
    cfg = { url: url, alElegir: cb, columnas: PRESETS[preset].columnas };
    titulo.textContent = PRESETS[preset].titulo;
    buscar.placeholder = PRESETS[preset].placeholder;
    cabeza.innerHTML = '';
    cfg.columnas.forEach(function (c) {
      var th = document.createElement('th'); th.textContent = c.rotulo; if (c.num) th.className = 'num'; if (c.chica) th.setAttribute('data-chica', ''); cabeza.appendChild(th);
    });
    cabeza.appendChild(document.createElement('th'));
    estado = { pagina: 1, q: '' };
    buscar.value = '';
    dlg.showModal();
    buscar.focus();
    cargar();
  }
  window.VentanaLista = {
    articulos: function (url, cb) { abrir('articulos', url, cb); },
    clientes: function (url, cb) { abrir('clientes', url, cb); }
  };
  window.VentanaArticulos = { abrir: window.VentanaLista.articulos };
})();
