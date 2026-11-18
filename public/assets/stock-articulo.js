/*
 * Stock de un artículo en los buscadores (sugerencias y «ver lista»): el servidor manda a.stock = {controla, total, depositos: [{n, s}]}
 * (o nada si el stock no está activado). Con un solo depósito dice «Stock 12»; con varios, el total y cuánto hay en cada uno, para ver dónde está.
 */
(function () {
  'use strict';
  function fmt(n) { return Number(n).toLocaleString('es-AR', { maximumFractionDigits: 3 }); }
  function reservado(s) {
    return s.reservas && s.reservas.length ? ' · Reservado: ' + s.reservas.map(function (r) { return r.n + ' ' + fmt(r.c); }).join(', ') : '';
  }
  function texto(a) {
    var s = a && a.stock;
    if (!s) return '';
    if (!s.controla) return 'No controla stock';
    if (s.depositos.length <= 1) return 'Stock ' + fmt(s.total) + reservado(s);
    return 'Stock ' + fmt(s.total) + ' (' + s.depositos.map(function (d) { return d.n + ' ' + fmt(d.s); }).join(' · ') + ')' + reservado(s);
  }
  // Estado de una línea de la venta que contestó /stock/reservar: {txt, mal}
  function estadoReserva(e) {
    var otros = (e.otros || []).map(function (o) { return o.nombre + ' ' + fmt(o.cantidad); }).join(', ');
    if (e.falta > 0) return { mal: true, txt: 'No alcanza: hay ' + fmt(Math.max(0, e.disponible)) + ' disponible' + (otros ? ' (reservado por ' + otros + ')' : '') + '. Al emitir pide autorización de un supervisor.' };
    if (otros) return { mal: false, txt: 'Reservado por ' + otros + '; alcanza para esta venta.' };
    return { mal: false, txt: 'Reservado para esta venta.' };
  }
  window.StockArticulo = {
    fmt: fmt,
    texto: texto,
    estadoReserva: estadoReserva,
    sinStock: function (a) { return !!(a && a.stock && a.stock.controla && a.stock.total <= 0); },
    // columnas para la ventana «ver lista»: una por depósito (y el total si hay más de uno)
    columnas: function (depositos) {
      if (!depositos || !depositos.length) return [];
      var cols = depositos.map(function (nombre, i) {
        return { rotulo: depositos.length === 1 ? 'Stock' : nombre, num: true,
          valor: function (a) { return a.stock ? (a.stock.controla ? fmt(a.stock.depositos[i] ? a.stock.depositos[i].s : 0) : '—') : ''; } };
      });
      if (depositos.length > 1) cols.push({ rotulo: 'Total', num: true, valor: function (a) { return a.stock ? (a.stock.controla ? fmt(a.stock.total) : '—') : ''; } });
      return cols;
    }
  };
})();
