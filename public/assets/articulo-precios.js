/*
 * Ficha del artículo: recalcula en el momento los precios de cada lista con el costo y el margen (artículo → rubro → general de Parámetros),
 * el IVA (si los precios lo incluyen) y el redondeo hacia arriba. Con «Precio automático» los precios con margen quedan de solo lectura
 * (el servidor los vuelve a calcular al guardar). Muestra el margen real de cada lista.
 */
(function () {
  'use strict';
  var form = document.querySelector('form[data-precios]');
  if (!form) return;
  var cfg;
  try { cfg = JSON.parse(form.getAttribute('data-precios')); } catch (e) { return; }
  var caja = form.querySelector('[data-precios-resumen]');
  var LISTAS = { l1: 'Lista 1', l2: 'Lista 2', l3: 'Lista 3', oferta: 'Oferta' };

  function campo(n) { return form.querySelector('[name="' + n + '"]:not([type="hidden"])') || form.querySelector('[name="' + n + '"]'); }
  function num(txt) {
    txt = String(txt || '').replace(/[\s$]/g, '');
    if (txt === '') return NaN;
    if (txt.indexOf(',') >= 0) txt = txt.replace(/\./g, '').replace(',', '.');
    else if (/^-?[1-9]\d{0,2}(\.\d{3})+$/.test(txt)) txt = txt.replace(/\./g, '');
    return /^-?\d+(\.\d+)?$/.test(txt) ? parseFloat(txt) : NaN;
  }
  function fmt(v, d) { return v.toLocaleString('es-AR', { minimumFractionDigits: d, maximumFractionDigits: d }); }
  function esc(s) { var x = document.createElement('div'); x.textContent = s; return x.innerHTML; }
  function precio(costo, margen, iva) {
    var p = costo * (1 + margen / 100) * (1 + iva / 100);
    if (cfg.redondeo > 0) return Math.ceil(Math.round(p / cfg.redondeo * 1e6) / 1e6) * cfg.redondeo;
    return Math.round((p + 1e-9) * 100) / 100;
  }
  function chequeado(n) { var c = form.querySelector('input[type="checkbox"][name="' + n + '"]'); return !!c && c.checked; }

  function recalcular() {
    var costo = num(campo('costo') ? campo('costo').value : '');
    var rubro = campo('rubro') ? campo('rubro').value : '';
    var alic = campo('alicuota') ? campo('alicuota').value : '';
    var grav = form.querySelector('input[type="checkbox"][name="grabado"]') ? chequeado('grabado') : true;
    var iva = cfg.suma_iva && grav ? (cfg.iva[alic] || 0) : 0;
    var auto = chequeado('precio_automatico');
    var filas = '', hay = false;
    Object.keys(LISTAS).forEach(function (k) {
      var inp = campo(cfg.columnas[k]);
      var propio = num(campo('margen_' + k) ? campo('margen_' + k).value : '');
      var m = null, origen = '';
      if (!isNaN(propio)) { m = propio; origen = 'del artículo'; }
      else if (cfg.rubros[rubro] && cfg.rubros[rubro][k] !== undefined) { m = cfg.rubros[rubro][k]; origen = 'del rubro'; }
      else if (cfg.general[k] !== null && cfg.general[k] !== undefined) { m = cfg.general[k]; origen = 'general'; }
      var calc = m !== null && costo > 0 ? precio(costo, m, iva) : null;
      if (inp && !inp.disabled) {
        if (auto && calc !== null) { inp.value = fmt(calc, 2); inp.readOnly = true; inp.title = 'Precio automático: se calcula con el costo y el margen'; }
        else { inp.readOnly = false; inp.title = ''; }
      }
      var actual = inp ? num(inp.value) : NaN;
      var real = costo > 0 && actual > 0 ? (actual / (1 + iva / 100) - costo) / costo * 100 : null;
      if (m !== null || real !== null) hay = true;
      filas += '<tr><td>' + LISTAS[k] + '</td><td class="num">' + (m === null ? '<span class="muted">sin margen</span>' : esc(fmt(m, 2) + ' % ' + origen)) + '</td>'
        + '<td class="num">' + (calc === null ? '—' : '$ ' + esc(fmt(calc, 2))) + '</td>'
        + '<td class="num">' + (real === null ? '—' : '<b class="' + (real < 0 ? 'texto-error' : '') + '">' + esc(fmt(real, 1)) + ' %</b>') + '</td></tr>';
    });
    if (!caja) return;
    caja.hidden = !hay;
    caja.innerHTML = '<p class="small muted">' + (auto ? 'Precio automático: las listas con margen se calculan solas.' : 'Precio manual: lo calculado es solo una guía.')
      + (iva > 0 ? ' Incluye IVA ' + esc(fmt(iva, 1)) + ' %.' : ' Sin IVA.') + (cfg.redondeo > 0 ? ' Redondeo hacia arriba a $ ' + cfg.redondeo + '.' : '') + '</p>'
      + '<div class="table-wrap"><table class="table" data-grilla="no"><thead><tr><th>Lista</th><th class="num">Margen</th><th class="num">Precio calculado</th><th class="num">Margen real</th></tr></thead><tbody>'
      + filas + '</tbody></table></div>';
  }
  form.addEventListener('input', recalcular);
  form.addEventListener('change', recalcular);
  recalcular();
})();
