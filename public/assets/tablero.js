/* Tablero de inicio: línea guía y cartel con los valores al pasar el mouse (o tocar) por los gráficos,
   y el cambio de sucursal sin botón. Los gráficos los dibuja el servidor (App\Modules\Tablero\Graficos). */
(function () {
  'use strict';
  document.querySelectorAll('[data-tb-enviar]').forEach(function (s) {
    s.addEventListener('change', function () { s.form.submit(); });
  });
  document.querySelectorAll('[data-tb-grafico]').forEach(function (caja) {
    var svg = caja.querySelector('svg');
    var tip = caja.querySelector('.tb-tip');
    if (!svg || !tip) { return; }
    var guia = svg.querySelector('.g-guia');
    var pa = svg.querySelector('.g-pa');
    var pb = svg.querySelector('.g-pb');
    var vb = svg.viewBox.baseVal;
    // los elementos SVG no tienen la propiedad .hidden: se usa el atributo
    function ver(el, si) { if (el) { if (si) { el.removeAttribute('hidden'); } else { el.setAttribute('hidden', ''); } } }
    // en pantallas angostas el SVG se achica: se agranda la letra en la misma proporción (hasta 1,6 veces) para que se lea
    function escala() { svg.style.setProperty('--g-esc', Math.min(1.6, Math.max(1, vb.width / Math.max(1, svg.clientWidth))).toFixed(2)); }
    escala();
    window.addEventListener('resize', escala);
    function mostrar(z) {
      var renglones = (z.getAttribute('data-tip') || '').split('|');
      tip.textContent = '';
      renglones.forEach(function (t, i) {
        var el = document.createElement(i === 0 ? 'b' : 'div');
        el.textContent = t;
        tip.appendChild(el);
      });
      var x = parseFloat(z.getAttribute('data-x'));
      if (isNaN(x)) { x = parseFloat(z.getAttribute('x')) + parseFloat(z.getAttribute('width')) / 2; }
      if (guia) {
        guia.setAttribute('x1', x); guia.setAttribute('x2', x); ver(guia, true);
        [[pa, 'data-ya'], [pb, 'data-yb']].forEach(function (p) {
          var y = z.getAttribute(p[1]);
          if (!p[0]) { return; }
          if (y === null || y === '') { ver(p[0], false); return; }
          p[0].setAttribute('cx', x); p[0].setAttribute('cy', y); ver(p[0], true);
        });
      }
      var i = z.getAttribute('data-i');
      if (i !== null) {
        svg.classList.add('g-sobre');
        svg.querySelectorAll('.g-col').forEach(function (c) { c.classList.toggle('g-activa', c.getAttribute('data-i') === i); });
      }
      tip.hidden = false;
      var ancho = caja.clientWidth;
      var px = x / vb.width * ancho;
      var medio = tip.offsetWidth / 2;
      tip.style.left = Math.min(Math.max(px, medio), ancho - medio) + 'px';
    }
    function ocultar() {
      tip.hidden = true;
      ver(guia, false); ver(pa, false); ver(pb, false);
      svg.classList.remove('g-sobre');
    }
    svg.querySelectorAll('.g-zona').forEach(function (z) {
      z.addEventListener('pointerenter', function () { mostrar(z); });
      z.addEventListener('pointerdown', function () { mostrar(z); });
    });
    svg.addEventListener('pointerleave', ocultar);
  });
})();
