/*
 * Modo Kiosco: los productos en tarjetas (con su stock) y un carrito SIEMPRE visible: tocar una tarjeta suma uno, en el carrito se cambian cantidades o se saca
 * un producto al instante. El carrito queda guardado en el navegador (si se vuelve de «Cobrar» sigue ahí). Al cobrar se arma un presupuesto en el servidor.
 */
(function () {
  'use strict';
  var raiz = document.querySelector('[data-kiosco]');
  if (!raiz) return;
  var q = function (s) { return raiz.querySelector(s); };
  var CLAVE = 'facturaweb.kiosco.carrito';
  var estado = { items: [], cliente: { codigo: raiz.getAttribute('data-cliente-codigo') || '', nombre: raiz.getAttribute('data-cliente-nombre') || '' } };

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
  function cant(v) { return Number(v).toLocaleString('es-AR', { maximumFractionDigits: 3 }); }
  function el(tag, cls, txt) { var e = document.createElement(tag); if (cls) e.className = cls; if (txt !== undefined) e.textContent = txt; return e; }

  // ---- carrito guardado en el navegador (si no se puede, funciona igual en memoria)
  function guardar() { try { localStorage.setItem(CLAVE, JSON.stringify(estado)); } catch (e) {} }
  function cargarGuardado() {
    if (raiz.getAttribute('data-hecho') === '1') { try { localStorage.removeItem(CLAVE); } catch (e) {} return; } // venta hecha: carrito vacío
    try {
      var g = JSON.parse(localStorage.getItem(CLAVE) || 'null');
      if (g && Array.isArray(g.items)) { estado.items = g.items.filter(function (x) { return x && x.codigo && x.cantidad > 0; }); if (g.cliente && g.cliente.codigo) estado.cliente = g.cliente; }
    } catch (e) {}
  }

  // ---- carrito
  var lineas = q('[data-lineas]');
  function totalCarrito() { return r2(estado.items.reduce(function (a, x) { return a + x.cantidad * num(x.precio); }, 0)); }
  function render() {
    lineas.innerHTML = '';
    estado.items.forEach(function (x, i) {
      var f = el('div', 'k-linea');
      var cab = el('div', 'k-linea-cab');
      cab.appendChild(el('b', 'k-linea-desc', x.descripcion));
      var quitar = el('button', 'btn btn-ghost btn-sm', '✕'); quitar.type = 'button'; quitar.title = 'Sacar del carrito'; quitar.setAttribute('aria-label', 'Sacar ' + x.descripcion);
      quitar.addEventListener('click', function () { estado.items.splice(i, 1); cambio(); });
      cab.appendChild(quitar);
      f.appendChild(cab);
      var ctl = el('div', 'k-linea-ctl');
      var menos = el('button', 'btn btn-ghost btn-sm', '−'); menos.type = 'button'; menos.setAttribute('aria-label', 'Una menos');
      menos.addEventListener('click', function () { x.cantidad = Math.max(0, r2(x.cantidad - 1)); if (x.cantidad <= 0) estado.items.splice(i, 1); cambio(); });
      var inp = el('input', 'k-cant'); inp.type = 'text'; inp.inputMode = 'decimal'; inp.value = cant(x.cantidad); inp.setAttribute('aria-label', 'Cantidad de ' + x.descripcion);
      inp.addEventListener('change', function () { var v = num(inp.value); if (v > 0) { x.cantidad = v; } else { estado.items.splice(i, 1); } cambio(); });
      inp.addEventListener('focus', function () { inp.select(); });
      var mas = el('button', 'btn btn-ghost btn-sm', '+'); mas.type = 'button'; mas.setAttribute('aria-label', 'Una más');
      mas.addEventListener('click', function () { x.cantidad = r2(x.cantidad + 1); cambio(); });
      ctl.appendChild(menos); ctl.appendChild(inp); ctl.appendChild(mas);
      ctl.appendChild(el('span', 'k-linea-precio muted small', '× ' + fmt(num(x.precio))));
      ctl.appendChild(el('b', 'k-linea-sub', fmt(r2(x.cantidad * num(x.precio)))));
      f.appendChild(ctl);
      if (x.controla && x.stock !== null && x.cantidad > x.stock) f.appendChild(el('small', 'k-aviso', 'Hay ' + cant(x.stock) + ' en stock (se vende igual).'));
      lineas.appendChild(f);
    });
    var n = estado.items.reduce(function (a, x) { return a + x.cantidad; }, 0);
    q('[data-cant]').textContent = n > 0 ? '(' + cant(n) + ')' : '';
    q('[data-vacio]').hidden = estado.items.length > 0;
    q('[data-total]').textContent = fmt(totalCarrito());
    q('[data-cobrar]').disabled = estado.items.length === 0 || !estado.cliente.codigo;
    q('[data-cliente-nombre]').textContent = estado.cliente.nombre || 'Elegí el cliente';
  }
  function cambio() { guardar(); render(); }
  function agregar(a) {
    var ya = estado.items.filter(function (x) { return x.codigo === a.codigo; })[0];
    if (ya) ya.cantidad = r2(ya.cantidad + (a.cantidad && a.cantidad > 0 ? a.cantidad : 1));
    else estado.items.push({ codigo: a.codigo, descripcion: a.descripcion, precio: a.precio || '0', alicuota: a.alicuota || '', cantidad: a.cantidad && a.cantidad > 0 ? a.cantidad : 1,
      stock: a.stock ? a.stock.total : null, controla: !!(a.stock && a.stock.controla) });
    cambio();
    lineas.scrollTop = lineas.scrollHeight;
  }
  q('[data-vaciar]').addEventListener('click', function () { if (estado.items.length && window.confirm('¿Vaciar el carrito?')) { estado.items = []; cambio(); } });

  // ---- cliente (por omisión el consumidor final; el usuario elige otro de la lista)
  var cajaCli = q('[data-cliente-caja]'), inpCli = q('[data-cliente-q]'), listaCli = q('[data-cliente-lista]'), timerCli = null;
  q('[data-cambiar-cliente]').addEventListener('click', function () { cajaCli.hidden = !cajaCli.hidden; if (!cajaCli.hidden) inpCli.focus(); });
  inpCli.addEventListener('input', function () {
    clearTimeout(timerCli);
    var t = inpCli.value.trim();
    if (t.length < 2) { listaCli.hidden = true; return; }
    timerCli = setTimeout(function () {
      fetch(raiz.getAttribute('data-url-cliente') + '?q=' + encodeURIComponent(t), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (arr) {
        listaCli.innerHTML = '';
        if (!arr.length) { var v = el('li', 'muted', 'Sin coincidencias'); listaCli.appendChild(v); }
        arr.forEach(function (c) {
          var li = el('li'); li.appendChild(el('b', '', c.razon_social)); li.appendChild(el('small', 'muted', ' ' + c.codigo + (c.cuit ? ' · CUIT ' + c.cuit : '')));
          li.addEventListener('mousedown', function (ev) {
            ev.preventDefault();
            estado.cliente = { codigo: String(c.codigo), nombre: c.razon_social };
            cajaCli.hidden = true; inpCli.value = ''; listaCli.hidden = true; cambio();
          });
          listaCli.appendChild(li);
        });
        listaCli.hidden = false;
      });
    }, 250);
  });

  // ---- cobrar: el servidor arma el presupuesto y pasa a «Cómo se paga»
  q('[data-cobrar]').addEventListener('click', function () {
    var f = q('[data-form-cobrar]');
    Array.prototype.slice.call(f.querySelectorAll('[data-dato]')).forEach(function (e) { e.remove(); });
    function campo(nombre, valor) { var i = document.createElement('input'); i.type = 'hidden'; i.name = nombre; i.value = valor; i.setAttribute('data-dato', ''); f.appendChild(i); }
    campo('cliente', estado.cliente.codigo);
    var suc = q('[data-sucursal]'); if (suc) campo('sucursal', suc.value);
    estado.items.forEach(function (x, i) {
      campo('items[' + i + '][articulo]', x.codigo);
      campo('items[' + i + '][cantidad]', String(x.cantidad).replace('.', ','));
      campo('items[' + i + '][precio]', x.precio);
      campo('items[' + i + '][alicuota]', x.alicuota);
    });
    f.submit();
  });

  // ---- tarjetas
  var cont = q('[data-tarjetas]'), buscar = q('[data-buscar]'), masBtn = q('[data-ver-mas]'), sinRes = q('[data-sin-resultados]');
  var vista = { q: '', rubro: '', pagina: 1, pedido: 0 };
  function tarjeta(a) {
    var b = el('button', 'k-tarjeta'); b.type = 'button';
    var sin = window.StockArticulo && window.StockArticulo.sinStock(a);
    if (sin) b.className += ' sin-stock';
    b.appendChild(el('span', 'k-cod', a.codigo));
    b.appendChild(el('span', 'k-desc', a.descripcion));
    b.appendChild(el('span', 'k-precio', a.precio && num(a.precio) > 0 ? fmt(num(a.precio)) : 'Sin precio'));
    var st = window.StockArticulo ? window.StockArticulo.texto(a) : '';
    if (st) b.appendChild(el('span', 'k-stock' + (sin ? ' sin' : ''), sin ? 'Sin stock' : st));
    b.addEventListener('click', function () { agregar(a); });
    return b;
  }
  function cargar(mas) {
    var n = ++vista.pedido;
    if (!mas) vista.pagina = 1;
    var url = raiz.getAttribute('data-url-articulos') + '?q=' + encodeURIComponent(vista.q) + '&rubro=' + encodeURIComponent(vista.rubro) + '&pagina=' + vista.pagina;
    fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (n !== vista.pedido) return;
      if (!mas) cont.innerHTML = '';
      d.items.forEach(function (a) { cont.appendChild(tarjeta(a)); });
      masBtn.hidden = !d.hay_mas;
      sinRes.hidden = cont.children.length > 0;
    });
  }
  masBtn.addEventListener('click', function () { vista.pagina++; cargar(true); });
  var timer = null;
  buscar.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { vista.q = buscar.value.trim(); cargar(false); }, 250); });
  // Enter con un código exacto (pistola): lo suma directo; si no, muestra las opciones
  buscar.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter') return;
    ev.preventDefault();
    var t = buscar.value.trim();
    if (!t) return;
    fetch(raiz.getAttribute('data-url-articulos') + '?exacto=1&q=' + encodeURIComponent(t), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.items.length === 1) { agregar(d.items[0]); buscar.value = ''; vista.q = ''; cargar(false); }
      else { vista.q = t; cargar(false); }
    });
  });
  // rubros
  var chips = q('[data-rubros-chips]');
  var rubros = [];
  try { rubros = JSON.parse(raiz.getAttribute('data-rubros') || '[]'); } catch (e) {}
  function chip(txt, valor) {
    var b = el('button', 'k-chip' + (vista.rubro === valor ? ' activo' : ''), txt); b.type = 'button';
    b.addEventListener('click', function () {
      vista.rubro = valor;
      Array.prototype.forEach.call(chips.children, function (c) { c.classList.remove('activo'); }); b.classList.add('activo');
      cargar(false);
    });
    return b;
  }
  if (rubros.length) { chips.appendChild(chip('Todos', '')); rubros.forEach(function (r) { chips.appendChild(chip(r.descripcion, r.codigo)); }); chips.firstChild.classList.add('activo'); }

  cargarGuardado();
  render();
  cargar(false);
})();
