/*
 * Grillas de todo el sistema (las tablas .table, también las que tienen campos para completar; no las marcadas data-grilla="no"):
 *  - Orden por columna: las grillas con páginas ya traen el título como link (el servidor ordena todo, no solo la página); en las demás se
 *    ordena acá al tocar el título (▲ / ▼), entendiendo números con puntos de miles, importes y fechas dd/mm/aaaa.
 *  - "Excel": la misma página con ?excel=grilla&tabla=N (el servidor exporta TODO lo filtrado, con los permisos de siempre).
 *  - Alto: la grilla entra en la pantalla (sin mover la página) y se desplaza adentro, con los títulos fijos y scroll a la derecha.
 */
(function () {
  'use strict';
  var esCelular = function () { return window.matchMedia('(max-width: 760px)').matches; };

  function esGrilla(t) {
    // Todas, también las que tienen campos para completar (cobrar, aplicar…); solo se excluyen las marcadas data-grilla="no"
    // (los renglones de un comprobante, que se agregan y se quitan mientras se carga).
    return t.getAttribute('data-grilla') !== 'no';
  }
  var grillas = Array.prototype.filter.call(document.querySelectorAll('table.table'), esGrilla);
  if (!grillas.length) return;
  var conPaginas = !!document.querySelector('.paginador');
  var yaTieneExcel = !!document.querySelector('a[href*="excel=1"]'); // pantallas con su propio Excel (con columnas armadas a mano)

  function valor(txt) {
    var s = txt.replace(/ /g, ' ').trim();
    var f = s.match(/^(\d{2})\/(\d{2})\/(\d{4})/);
    if (f) return { n: Number(f[3] + f[2] + f[1]), t: s };
    var limpio = s.replace(/[$\s%]/g, '').replace('−', '-');
    if (/^-?(\d{1,3}(\.\d{3})+|\d+)(,\d+)?$/.test(limpio)) return { n: Number(limpio.replace(/\./g, '').replace(',', '.')), t: s };
    return { n: null, t: s.toLocaleLowerCase('es') };
  }

  function ordenar(t, col, desc) {
    var cuerpo = t.tBodies[0];
    if (!cuerpo) return;
    var filas = Array.prototype.slice.call(cuerpo.rows);
    filas.sort(function (a, b) {
      var x = valor(a.cells[col] ? a.cells[col].textContent : ''), y = valor(b.cells[col] ? b.cells[col].textContent : '');
      var r = (x.n !== null && y.n !== null) ? x.n - y.n : x.t.localeCompare(y.t, 'es', { numeric: true });
      return desc ? -r : r;
    });
    filas.forEach(function (f) { cuerpo.appendChild(f); });
  }

  grillas.forEach(function (t, indice) {
    var wrap = t.closest('.table-wrap');
    // Orden en la página (solo si no hay páginas: con páginas ordena el servidor)
    var ths = t.tHead ? t.tHead.rows[0].cells : [];
    Array.prototype.forEach.call(ths, function (th, col) {
      if (conPaginas || th.querySelector('a') || th.textContent.trim() === '' || th.colSpan > 1) return;
      th.classList.add('th-ordenable');
      th.setAttribute('tabindex', '0');
      th.title = 'Ordenar por ' + th.textContent.trim().toLowerCase();
      var accion = function () {
        var desc = th.getAttribute('aria-sort') === 'ascending';
        Array.prototype.forEach.call(ths, function (o) { o.removeAttribute('aria-sort'); });
        th.setAttribute('aria-sort', desc ? 'descending' : 'ascending');
        ordenar(t, col, desc);
      };
      th.addEventListener('click', accion);
      th.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); accion(); } });
    });
    // Botón Excel (arriba a la derecha de la grilla)
    if (!yaTieneExcel && wrap && t.tBodies[0] && t.tBodies[0].rows.length && !wrap.previousElementSibling?.classList?.contains('grilla-barra')) {
      var u = new URL(window.location.href);
      u.searchParams.set('excel', 'grilla');
      u.searchParams.set('tabla', String(indice));
      u.searchParams.delete('pagina');
      var barra = document.createElement('div');
      barra.className = 'grilla-barra';
      var a = document.createElement('a');
      a.className = 'btn btn-ghost btn-sm';
      a.href = u.toString();
      a.textContent = 'Excel';
      a.title = 'Bajar esta grilla a Excel (todo lo filtrado, no solo esta página)';
      barra.appendChild(a);
      wrap.parentNode.insertBefore(barra, wrap);
    }
    if (wrap) wrap.classList.add('grilla');
  });

  // Alto: que la grilla entre en la pantalla y se desplace adentro
  function ajustar() {
    grillas.forEach(function (t) {
      var wrap = t.closest('.table-wrap');
      if (!wrap) return;
      if (esCelular()) { wrap.style.maxHeight = ''; return; }
      var arriba = wrap.getBoundingClientRect().top + window.scrollY;
      var abajo = 0; // lo que va debajo de la grilla y conviene ver (paginador, totales)
      var sig = wrap.nextElementSibling;
      if (sig && (sig.matches('.paginador, [data-paginador], .totales, tfoot'))) abajo = sig.offsetHeight + 8;
      var alto = window.innerHeight - Math.min(arriba, window.innerHeight * 0.55) - abajo - 16;
      wrap.style.maxHeight = Math.max(260, Math.floor(alto)) + 'px';
    });
  }
  ajustar();
  window.addEventListener('resize', ajustar);
})();
