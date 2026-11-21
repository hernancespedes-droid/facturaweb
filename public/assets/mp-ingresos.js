/*
 * Campanita de ingresos de Mercado Pago (0.41.0). Cada 30 segundos le pregunta al servidor si entró plata (el servidor consulta a Mercado Pago como mucho una vez por
 * minuto, para todas las pantallas juntas) y muestra un cartel por cada ingreso nuevo con un sonido corto. El sonido se apaga o prende desde la pantalla de ingresos
 * (se recuerda en este navegador). Con la pestaña en segundo plano pregunta cada 2 minutos.
 */
(function () {
  'use strict';
  var campana = document.querySelector('[data-mp-campana]');
  var caja = document.querySelector('[data-mp-avisos]');
  if (!campana || !caja) return;
  var url = campana.getAttribute('data-url');
  var ultimo = parseInt(campana.getAttribute('data-ultimo'), 10) || 0;
  var n = campana.querySelector('[data-mp-n]');
  var timer = null;

  function sonidoActivo() {
    try { return localStorage.getItem('mp_sonido') !== '0'; } catch (e) { return true; }
  }
  window.MpIngresos = {
    sonido: function (on) {
      try { localStorage.setItem('mp_sonido', on ? '1' : '0'); } catch (e) { /* sin almacenamiento: queda prendido */ }
    },
    sonidoActivo: sonidoActivo
  };

  function tono() {
    if (!sonidoActivo()) return;
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return;
      var ctx = new Ctx(), t = ctx.currentTime;
      [880, 1320].forEach(function (f, i) {
        var o = ctx.createOscillator(), g = ctx.createGain();
        o.frequency.value = f; o.type = 'sine';
        g.gain.setValueAtTime(0.0001, t + i * 0.18);
        g.gain.exponentialRampToValueAtTime(0.25, t + i * 0.18 + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, t + i * 0.18 + 0.16);
        o.connect(g); g.connect(ctx.destination);
        o.start(t + i * 0.18); o.stop(t + i * 0.18 + 0.17);
      });
      setTimeout(function () { ctx.close(); }, 800);
    } catch (e) { /* el navegador no deja sonar sin un clic previo */ }
  }

  function cartel(texto) {
    var d = document.createElement('div');
    d.className = 'mp-aviso'; d.setAttribute('role', 'status');
    var a = document.createElement('a');
    a.href = campana.getAttribute('href');
    var b = document.createElement('b'); b.textContent = 'Mercado Pago';
    a.appendChild(b); a.appendChild(document.createTextNode(texto));
    var x = document.createElement('button');
    x.type = 'button'; x.setAttribute('aria-label', 'Cerrar aviso'); x.textContent = '×';
    x.addEventListener('click', function () { d.remove(); });
    d.appendChild(a); d.appendChild(x);
    caja.appendChild(d);
    while (caja.children.length > 4) caja.removeChild(caja.firstChild);
    setTimeout(function () { d.remove(); }, 20000);
  }

  function contador(k) {
    if (!n) return;
    n.textContent = k > 99 ? '99+' : String(k);
    n.hidden = k <= 0;
    campana.setAttribute('aria-label', 'Ingresos de Mercado Pago: ' + k + ' sin ver');
  }

  function preguntar() {
    fetch(url + '?desde=' + ultimo, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) return;
        contador(d.sin_ver || 0);
        if (d.nuevos && d.nuevos.length) {
          d.nuevos.forEach(function (x) { cartel(x.texto); });
          tono();
          campana.classList.remove('mp-latido'); void campana.offsetWidth; campana.classList.add('mp-latido');
          document.dispatchEvent(new CustomEvent('mp-ingresos', { detail: d }));
        }
        if (d.ultimo_id > ultimo) ultimo = d.ultimo_id;
      })
      .catch(function () {})
      .then(programar);
  }

  function programar() {
    clearTimeout(timer);
    timer = setTimeout(preguntar, document.hidden ? 120000 : 30000);
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { clearTimeout(timer); preguntar(); } });
  window.MpIngresos.ahora = preguntar; // para la pantalla de ingresos y las pruebas
  programar();
})();
