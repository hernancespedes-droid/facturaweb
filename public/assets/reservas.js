/*
 * Reservas de stock de una venta en curso (carrito del kiosco o comprobante en carga). Avisa al servidor lo que lleva la venta (con una pausa corta) y se renueva
 * mientras la pantalla está abierta; el servidor contesta cómo está cada producto (lo reservan otros, si no alcanza). Si la pantalla se abandona, la reserva vence sola.
 *   var r = Reservas.crear({ url, csrfNombre, csrf, token, origen, alEstado: function (estados) {} });  r.sync([{ articulo, cantidad }]);  r.liberar(urlLiberar);
 */
(function () {
  'use strict';
  window.Reservas = {
    crear: function (opt) {
      var timer = null, latido = null, lineas = [];
      function enviar() {
        var fd = new FormData();
        fd.append(opt.csrfNombre, opt.csrf); fd.append('token', opt.token); fd.append('origen', opt.origen || 'comprobante'); fd.append('lineas', JSON.stringify(lineas));
        fetch(opt.url, { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (d) { if (d && d.estados && opt.alEstado) opt.alEstado(d.estados); })
          .catch(function () {});
      }
      return {
        sync: function (l) {
          lineas = l;
          clearTimeout(timer);
          timer = setTimeout(enviar, 500);
          if (!latido) latido = setInterval(enviar, 120000); // renueva la reserva mientras la pantalla siga abierta
        },
        liberar: function (urlLiberar) {
          lineas = [];
          clearTimeout(timer);
          var fd = new FormData();
          fd.append(opt.csrfNombre, opt.csrf); fd.append('token', opt.token);
          fetch(urlLiberar, { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function () {});
        }
      };
    }
  };
})();
