/*
 * Pantalla del cobro con Mercado Pago: se actualiza sola mientras espera la acreditación, cuenta el tiempo que queda y, cuando la venta se emite,
 * sigue sola (al kiosco o al comprobante). El servidor decide todo; esto solo mira.
 */
(function () {
  'use strict';
  var raiz = document.querySelector('[data-mp-cobro]');
  if (!raiz) return;
  var copiar = raiz.querySelector('[data-mp-copiar]');
  if (copiar) copiar.addEventListener('click', function () {
    var inp = raiz.querySelector('[data-mp-link]');
    inp.select();
    try { navigator.clipboard.writeText(inp.value); } catch (e) { document.execCommand('copy'); }
    copiar.textContent = 'Copiado';
  });
  if (raiz.getAttribute('data-estado') !== 'pendiente') return;
  var seg = parseInt(raiz.getAttribute('data-segundos'), 10) || 0;
  var cuenta = raiz.querySelector('[data-mp-cuenta]');
  function pintar() {
    if (!cuenta) return;
    var m = Math.floor(seg / 60), s = seg % 60;
    cuenta.textContent = m + ':' + (s < 10 ? '0' : '') + s;
  }
  pintar();
  setInterval(function () { if (seg > 0) { seg--; pintar(); } }, 1000);
  function mirar() {
    fetch(raiz.getAttribute('data-url-estado'), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.estado && d.estado !== 'pendiente') { window.location.reload(); }
      })
      .catch(function () { /* sin conexión un momento: se vuelve a probar */ });
  }
  setInterval(mirar, 3000);
})();
