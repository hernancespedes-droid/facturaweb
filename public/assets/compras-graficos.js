/*
 * Gráficos del tablero de compras (sin librerías): SVG dibujado con una sola escala por gráfico, colores del tema (var(--serie-N)) y
 * un cartel al pasar el mouse (o tocar). Los datos vienen en <script type="application/json" id="datos-tablero">.
 *   [data-graf="presupuesto"]  barras horizontales: gastado del mes por centro, con la marca del presupuesto (rojo si se pasó)
 *   [data-graf="centros"]      columnas apiladas: gasto por centro, mes a mes
 *   [data-graf="ventas"]       líneas: ventas y compras por mes (misma escala)
 *   [data-graf="conceptos"]    barras horizontales: en qué se gastó en el mes
 */
(function () {
  'use strict';
  var nodo = document.getElementById('datos-tablero');
  if (!nodo) return;
  var D = JSON.parse(nodo.textContent);
  var NS = 'http://www.w3.org/2000/svg';
  var fmt = function (v) { return Math.round(v).toLocaleString('es-AR'); };
  var plata = function (v) { return '$ ' + Math.round(v).toLocaleString('es-AR'); };

  function el(tag, attrs, padre, estilo) {
    var e = document.createElementNS(NS, tag);
    for (var k in attrs) e.setAttribute(k, attrs[k]);
    if (estilo) e.setAttribute('style', estilo);
    if (padre) padre.appendChild(e);
    return e;
  }
  function texto(padre, x, y, t, clase, anchor) {
    var e = el('text', { x: x, y: y, 'class': clase || 'ax', 'text-anchor': anchor || 'start' }, padre);
    e.textContent = t;
    return e;
  }
  // Escala "linda": 0..tope con 4 o 5 marcas redondas
  function marcas(max) {
    if (max <= 0) max = 1;
    var paso = Math.pow(10, Math.floor(Math.log10(max / 4)));
    [1, 2, 2.5, 5, 10].some(function (m) { if (max / (paso * m) <= 5) { paso = paso * m; return true; } return false; });
    var out = [];
    for (var v = 0; v <= max + paso * 0.001; v += paso) out.push(v);
    if (out[out.length - 1] < max) out.push(out[out.length - 1] + paso);
    return out;
  }
  function corto(v) {
    var a = Math.abs(v);
    if (a >= 1e9) return (v / 1e9).toLocaleString('es-AR', { maximumFractionDigits: 1 }) + ' mil M';
    if (a >= 1e6) return (v / 1e6).toLocaleString('es-AR', { maximumFractionDigits: 1 }) + ' M';
    if (a >= 1e3) return (v / 1e3).toLocaleString('es-AR', { maximumFractionDigits: a < 1e4 ? 1 : 0 }) + ' mil';
    return fmt(v);
  }
  function cartel(caja) {
    var t = document.createElement('div');
    t.className = 'graf-tip'; t.hidden = true; caja.appendChild(t);
    return {
      ver: function (ev, html) {
        t.innerHTML = html; t.hidden = false;
        var r = caja.getBoundingClientRect(), x = ev.clientX - r.left + 12, y = ev.clientY - r.top + 12;
        if (x + t.offsetWidth > r.width) x = Math.max(4, ev.clientX - r.left - t.offsetWidth - 12);
        t.style.left = x + 'px'; t.style.top = y + 'px';
      },
      ocultar: function () { t.hidden = true; }
    };
  }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function zona(svg, attrs, tip, html) {
    var z = el('rect', attrs, svg, 'fill:transparent');
    z.addEventListener('mousemove', function (ev) { tip.ver(ev, html()); });
    z.addEventListener('mouseleave', tip.ocultar);
    z.addEventListener('touchstart', function (ev) { tip.ver(ev.touches[0], html()); }, { passive: true });
    return z;
  }
  function vacio(caja, msj) { var p = document.createElement('p'); p.className = 'vacio-graf'; p.textContent = msj; caja.appendChild(p); }

  // Barras horizontales (con marca opcional). filas: [{nombre, valor, marca?}]
  function barras(caja, filas, opciones) {
    if (!filas.length) { vacio(caja, opciones.vacio); return; }
    var tip = cartel(caja), W = 480, alto = 30, L = Math.min(170, 12 + 6.2 * Math.max.apply(null, filas.map(function (f) { return f.nombre.length; }))), R = 64;
    var H = filas.length * alto + 22;
    var max = Math.max.apply(null, filas.map(function (f) { return Math.max(f.valor, f.marca || 0); }));
    var ms = marcas(max), tope = ms[ms.length - 1], x = function (v) { return L + (W - L - R) * Math.max(0, v) / tope; };
    var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': opciones.titulo }, caja);
    ms.forEach(function (m) { el('line', { x1: x(m), x2: x(m), y1: 0, y2: H - 20, 'class': 'gl' }, svg); texto(svg, x(m), H - 6, corto(m), 'ax', 'middle'); });
    filas.forEach(function (f, i) {
      var y = i * alto + 4, pasado = f.marca && f.valor > f.marca + 0.005;
      var nom = f.nombre.length > 26 ? f.nombre.slice(0, 25) + '…' : f.nombre;
      texto(svg, L - 8, y + 15, nom, 'lbl', 'end');
      if (f.valor > 0) el('rect', { x: L, y: y + 4, width: Math.max(2, x(f.valor) - L), height: 16, rx: 4 }, svg, 'fill:' + (pasado ? 'var(--danger)' : 'var(' + (opciones.color || '--serie-1') + ')'));
      if (f.marca) el('rect', { x: x(f.marca) - 1.5, y: y, width: 3, height: 24, rx: 1.5 }, svg, 'fill:var(--text)');
      texto(svg, Math.max(x(f.valor), f.marca ? x(f.marca) : 0) + 6, y + 16, corto(f.valor));
      zona(svg, { x: 0, y: y - 2, width: W, height: alto }, tip, function () { return opciones.cartel(f); });
    });
  }

  // 1. Presupuesto contra gastado
  var c1 = document.querySelector('[data-graf="presupuesto"]');
  if (c1) {
    var hayPres = D.presupuesto.some(function (x) { return x.presupuesto > 0; });
    barras(c1, D.presupuesto.map(function (x) { return { nombre: x.nombre, valor: x.gastado, marca: hayPres ? x.presupuesto : 0 }; }), {
      titulo: 'Gastado por centro en el mes', vacio: 'Todavía no hay gastos en este mes.',
      cartel: function (f) {
        var h = '<b>' + esc(f.nombre) + '</b><br>Gastado ' + plata(f.valor);
        if (f.marca) h += '<br>Presupuesto ' + plata(f.marca) + '<br>' + (f.valor > f.marca ? 'Se pasó ' + plata(f.valor - f.marca) : 'Le queda ' + plata(f.marca - f.valor));
        return h;
      }
    });
  }

  // 2. Gasto por centro, mes a mes (columnas apiladas)
  var c2 = document.querySelector('[data-graf="centros"]');
  if (c2) {
    var ley = c2.querySelector('.leyenda');
    D.centros.forEach(function (s, i) { var sp = document.createElement('span'); sp.innerHTML = '<i style="background:var(--serie-' + (i + 1) + ')"></i>' + esc(s.nombre); ley.appendChild(sp); });
    var tot = D.etiquetas.map(function (_, j) { return D.centros.reduce(function (a, s) { return a + Math.max(0, s.valores[j]); }, 0); });
    if (!D.centros.length) vacio(c2, 'Todavía no hay gastos cargados en estos meses.');
    else {
      var tip2 = cartel(c2), W = 480, H = 240, L = 52, B = 22, T = 14;
      var ms = marcas(Math.max.apply(null, tot)), tope = ms[ms.length - 1], y = function (v) { return T + (H - T - B) * (1 - v / tope); };
      var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': 'Gasto por centro, mes a mes' }, c2);
      ms.forEach(function (m) { el('line', { x1: L, x2: W, y1: y(m), y2: y(m), 'class': 'gl' }, svg); texto(svg, L - 6, y(m) + 4, corto(m), 'ax', 'end'); });
      var bw = (W - L) / D.etiquetas.length, cw = Math.min(46, bw * 0.58);
      D.etiquetas.forEach(function (et, j) {
        var cx = L + j * bw + (bw - cw) / 2, acc = 0, ultimo = -1;
        D.centros.forEach(function (s, i) { if (s.valores[j] > 0) ultimo = i; });
        D.centros.forEach(function (s, i) {
          var v = Math.max(0, s.valores[j]);
          if (v <= 0) return;
          var y0 = y(acc), y1 = y(acc + v), h = Math.max(1, y0 - y1 - (i === ultimo ? 0 : 2));
          el(i === ultimo ? 'path' : 'rect', i === ultimo
            ? { d: 'M' + cx + ',' + (y1 + h) + 'V' + (y1 + Math.min(4, h)) + 'q0,-4 4,-4h' + (cw - 8) + 'q4,0 4,4V' + (y1 + h) + 'Z' }
            : { x: cx, y: y1, width: cw, height: h }, svg, 'fill:var(--serie-' + (i + 1) + ')');
          acc += v;
        });
        texto(svg, cx + cw / 2, H - 6, et, 'ax', 'middle');
        if (j === D.etiquetas.length - 1 && tot[j] > 0) texto(svg, cx + cw / 2, y(tot[j]) - 5, corto(tot[j]), 'lbl', 'middle');
        zona(svg, { x: L + j * bw, y: 0, width: bw, height: H }, tip2, function () {
          return '<b>' + esc(et) + ' · ' + plata(tot[j]) + '</b><br>' + D.centros.map(function (s) { return esc(s.nombre) + ': ' + plata(s.valores[j]); }).join('<br>');
        });
      });
    }
  }

  // 3. Ventas y compras por mes (líneas, una sola escala)
  var c3 = document.querySelector('[data-graf="ventas"]');
  if (c3) {
    var series = [{ nombre: 'Ventas', v: D.ventas, c: '--serie-1' }, { nombre: 'Compras y gastos', v: D.compras, c: '--serie-2' }];
    var maxv = Math.max.apply(null, D.ventas.concat(D.compras));
    if (maxv <= 0) vacio(c3, 'Sin ventas ni compras en estos meses.');
    else {
      var tip3 = cartel(c3), W3 = 480, H3 = 230, L3 = 52, R3 = 70, B3 = 22, T3 = 12, n = D.etiquetas.length;
      var ms3 = marcas(maxv), tope3 = ms3[ms3.length - 1], y3 = function (v) { return T3 + (H3 - T3 - B3) * (1 - Math.max(0, v) / tope3); };
      var x3 = function (j) { return L3 + (W3 - L3 - R3) * (n === 1 ? 0.5 : j / (n - 1)); };
      var svg3 = el('svg', { viewBox: '0 0 ' + W3 + ' ' + H3, role: 'img', 'aria-label': 'Ventas y compras por mes' }, c3);
      ms3.forEach(function (m) { el('line', { x1: L3, x2: W3 - R3, y1: y3(m), y2: y3(m), 'class': 'gl' }, svg3); texto(svg3, L3 - 6, y3(m) + 4, corto(m), 'ax', 'end'); });
      D.etiquetas.forEach(function (et, j) { texto(svg3, x3(j), H3 - 6, et, 'ax', 'middle'); });
      var guia = el('line', { x1: 0, x2: 0, y1: T3, y2: H3 - B3, visibility: 'hidden' }, svg3, 'stroke:var(--line-2)');
      var ys = series.map(function (s) { return y3(s.v[n - 1]); });
      if (Math.abs(ys[0] - ys[1]) < 13) { var m = (ys[0] + ys[1]) / 2; ys = ys[0] <= ys[1] ? [m - 7, m + 7] : [m + 7, m - 7]; }
      series.forEach(function (s, i) {
        el('polyline', { points: s.v.map(function (v, j) { return x3(j) + ',' + y3(v); }).join(' '), fill: 'none', 'stroke-width': 2, 'stroke-linejoin': 'round' }, svg3, 'stroke:var(' + s.c + ')');
        el('circle', { cx: x3(n - 1), cy: y3(s.v[n - 1]), r: 4, 'stroke-width': 2 }, svg3, 'fill:var(' + s.c + ');stroke:var(--panel)');
        texto(svg3, x3(n - 1) + 8, ys[i] + 4, s.nombre === 'Ventas' ? 'Ventas' : 'Compras', 'lbl');
      });
      var paso = (W3 - L3 - R3) / Math.max(1, n - 1);
      D.etiquetas.forEach(function (et, j) {
        var z = zona(svg3, { x: x3(j) - paso / 2, y: 0, width: paso, height: H3 }, tip3, function () {
          return '<b>' + esc(et) + '</b><br>Ventas ' + plata(D.ventas[j]) + '<br>Compras y gastos ' + plata(D.compras[j]) + '<br>Diferencia ' + plata(D.ventas[j] - D.compras[j]);
        });
        z.addEventListener('mouseenter', function () { guia.setAttribute('x1', x3(j)); guia.setAttribute('x2', x3(j)); guia.setAttribute('visibility', 'visible'); });
        z.addEventListener('mouseleave', function () { guia.setAttribute('visibility', 'hidden'); });
      });
    }
  }

  // 4. Conceptos del mes
  var c4 = document.querySelector('[data-graf="conceptos"]');
  if (c4) {
    var totC = D.conceptos.reduce(function (a, x) { return a + x.importe; }, 0);
    barras(c4, D.conceptos.map(function (x) { return { nombre: x.nombre, valor: x.importe }; }), {
      titulo: 'Gasto por concepto en el mes', vacio: 'Todavía no hay gastos en este mes.',
      cartel: function (f) { return '<b>' + esc(f.nombre) + '</b><br>' + plata(f.valor) + (totC > 0 ? ' · ' + Math.round(f.valor / totC * 100) + ' % del mes' : ''); }
    });
  }
})();
