/*
 * Ventana «Nuevo cliente»: alta desde el CUIT con los datos del padrón de ARCA (razón social, condición de IVA, domicilio fiscal,
 * monotributo, actividades). La configuración la deja views/clientes/_alta_rapida.php en [data-alta-cliente].
 *
 *   AltaCliente.disponible()                         si el usuario puede dar de alta clientes en esta pantalla
 *   AltaCliente.abrir({ texto: 'lo que buscó' }, function (cliente) { ... })   cliente = el mismo objeto que devuelve el buscador
 *   AltaCliente.itemLista(q, alElegir)               <li> «+ Nuevo cliente…» para el final de la lista de un buscador
 *   AltaCliente.completarFormulario(form)            botón «Traer datos de ARCA» en la ficha del cliente (llena los campos del formulario)
 *
 * El usuario siempre confirma: nada se graba hasta «Crear cliente y usarlo».
 */
(function () {
  'use strict';
  var cfgEl = null, cfg = null, dlg = null, ui = {}, alElegir = null, ultimoCuit = '', pedido = 0, repetido = false;

  function leerCfg() {
    if (cfg) return cfg;
    cfgEl = document.querySelector('[data-alta-cliente]');
    if (!cfgEl) return null;
    var json = function (a) { try { return JSON.parse(cfgEl.getAttribute(a) || '[]'); } catch (e) { return []; } };
    cfg = {
      padron: cfgEl.getAttribute('data-url-padron'), crear: cfgEl.getAttribute('data-url-crear'),
      csrfNombre: cfgEl.getAttribute('data-csrf-nombre'), csrf: cfgEl.getAttribute('data-csrf'),
      arca: cfgEl.getAttribute('data-arca') === '1', arcaMotivo: cfgEl.getAttribute('data-arca-motivo') || '',
      provincias: json('data-provincias'), condiciones: json('data-condiciones')
    };
    return cfg;
  }

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function digitos(s) { return String(s || '').replace(/\D/g, ''); }
  function formatoCuit(d) { d = digitos(d).slice(0, 11); return d.length <= 2 ? d : d.length <= 10 ? d.slice(0, 2) + '-' + d.slice(2) : d.slice(0, 2) + '-' + d.slice(2, 10) + '-' + d.slice(10); }
  function cuitValido(d) {
    d = digitos(d); if (d.length !== 11) return false;
    var p = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2], s = 0; for (var i = 0; i < 10; i++) s += parseInt(d[i], 10) * p[i];
    var v = 11 - (s % 11); if (v === 11) v = 0; if (v === 10) v = 9;
    return v === parseInt(d[10], 10);
  }
  function fechaHora(s) { var m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/.exec(s || ''); return m ? m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : ''; }
  function opciones(lista, vacio) { return (vacio ? '<option value="">' + esc(vacio) + '</option>' : '') + lista.map(function (o) { return '<option value="' + esc(o[0]) + '">' + esc(o[1]) + '</option>'; }).join(''); }

  function campo(nombre, rotulo, html, extra) {
    return '<label class="field' + (extra || '') + '" data-ac-campo="' + nombre + '"><span>' + rotulo + '</span>' + html + '<small class="field-error" data-ac-err="' + nombre + '" hidden></small></label>';
  }

  function crear() {
    var c = leerCfg();
    dlg = document.createElement('dialog');
    dlg.className = 'ventana-articulos alta-cliente';
    dlg.setAttribute('aria-labelledby', 'ac-titulo');
    dlg.innerHTML =
      '<form method="dialog" class="va-cab"><h2 id="ac-titulo">Nuevo cliente</h2><button class="btn btn-ghost" value="x">Cerrar</button></form>' +
      '<form class="ac-form form" novalidate autocomplete="off">' +
        '<div class="ac-cuit">' +
          '<label class="field" data-ac-campo="cuit"><span>CUIT del cliente</span>' +
            '<div class="campo-con-boton"><input name="cuit" inputmode="numeric" maxlength="13" placeholder="Escribí o pegá el CUIT: 30-12345678-9" data-ac-cuit>' +
            (c.arca ? '<button type="button" class="btn" data-ac-buscar>Buscar en ARCA</button>' : '') + '</div>' +
            '<small class="field-error" data-ac-err="cuit" hidden></small></label>' +
          '<label class="check small"><input type="checkbox" data-ac-sincuit> Sin CUIT (consumidor final)</label>' +
        '</div>' +
        '<div class="ac-estado small" data-ac-estado aria-live="polite"></div>' +
        '<div class="ac-existe" data-ac-existe hidden></div>' +
        '<div class="ac-ficha" data-ac-ficha hidden></div>' +
        '<div class="grid-2 ac-campos">' +
          campo('razon_social', 'Razón social *', '<input name="razon_social" maxlength="100" required>', ' ac-ancho') +
          campo('iva', 'Condición de IVA *', '<select name="iva" required>' + opciones(c.condiciones, 'Elegí…') + '</select>') +
          campo('forma_pago', 'Forma de pago habitual', '<select name="forma_pago"><option value="1">Contado</option><option value="2">Cuenta corriente</option></select>') +
          campo('domicilio', 'Domicilio', '<input name="domicilio" maxlength="80">', ' ac-ancho') +
          campo('localidad', 'Localidad', '<input name="localidad" maxlength="40">') +
          campo('codigo_postal', 'Código postal', '<input name="codigo_postal" maxlength="8">') +
          campo('provincia', 'Provincia', '<select name="provincia">' + opciones(c.provincias, '—') + '</select>') +
          campo('telefono', 'Teléfono / WhatsApp', '<input name="telefono" maxlength="60" inputmode="tel">') +
          campo('emailfc', 'Mail para las facturas', '<input name="emailfc" maxlength="255" inputmode="email" placeholder="uno@mail.com; otro@mail.com">', ' ac-ancho') +
        '</div>' +
        '<div class="alerta alerta-error" data-ac-error hidden></div>' +
        '<div class="actions ac-acciones"><button type="button" class="btn btn-ghost" data-ac-cancelar>Cancelar</button>' +
          '<button type="submit" class="btn" data-ac-crear>Crear cliente y usarlo</button></div>' +
      '</form>';
    document.body.appendChild(dlg);
    var q = function (s) { return dlg.querySelector(s); };
    ui = { form: q('.ac-form'), cuit: q('[data-ac-cuit]'), buscar: q('[data-ac-buscar]'), sinCuit: q('[data-ac-sincuit]'), estado: q('[data-ac-estado]'),
      existe: q('[data-ac-existe]'), ficha: q('[data-ac-ficha]'), error: q('[data-ac-error]'), crear: q('[data-ac-crear]') };

    ui.cuit.addEventListener('input', function () {
      var d = digitos(ui.cuit.value);
      var f = formatoCuit(d);
      if (f !== ui.cuit.value) ui.cuit.value = f;
      marcar('cuit', '');
      if (d.length === 11) {
        if (!cuitValido(d)) { marcar('cuit', 'El CUIT no es válido: revisá los números (el último es el verificador).'); return; }
        if (d !== ultimoCuit) buscar(false);
      }
    });
    ui.cuit.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); buscar(false); } });
    if (ui.buscar) ui.buscar.addEventListener('click', function () { buscar(false); });
    ui.sinCuit.addEventListener('change', function () {
      ui.cuit.disabled = ui.sinCuit.checked;
      if (ui.buscar) ui.buscar.disabled = ui.sinCuit.checked;
      if (ui.sinCuit.checked) { ui.cuit.value = ''; ultimoCuit = ''; limpiarArca(); poner({ iva: '3' }); marcar('cuit', ''); campoEl('razon_social').focus(); }
      else ui.cuit.focus();
    });
    q('[data-ac-cancelar]').addEventListener('click', function () { dlg.close(); });
    ui.form.addEventListener('submit', function (ev) { ev.preventDefault(); grabar(); });
    ui.form.querySelector('[name="iva"]').addEventListener('change', function () { var l = dlg.querySelector('[data-ac-letra]'); if (l) l.hidden = true; });
  }

  function campoEl(n) { return ui.form.querySelector('[name="' + n + '"]'); }
  function marcar(n, msg) {
    var e = dlg.querySelector('[data-ac-err="' + n + '"]'), w = dlg.querySelector('[data-ac-campo="' + n + '"]');
    if (e) { e.textContent = msg; e.hidden = !msg; }
    if (w) w.classList.toggle('has-error', !!msg);
  }
  function poner(datos, pisar) {
    Object.keys(datos).forEach(function (k) {
      var el = campoEl(k); if (!el || k === 'cuit') return;
      if (pisar || !el.value || k === 'iva') el.value = datos[k];
    });
  }
  function limpiarArca() { ui.existe.hidden = true; ui.existe.innerHTML = ''; ui.ficha.hidden = true; ui.ficha.innerHTML = ''; ui.estado.textContent = ''; ui.estado.className = 'ac-estado small'; repetido = false; }
  function estado(texto, clase) { ui.estado.className = 'ac-estado small ' + (clase || ''); ui.estado.innerHTML = texto; }

  function reiniciar(texto) {
    ui.form.reset(); limpiarArca(); ultimoCuit = ''; ui.error.hidden = true; ui.cuit.disabled = false; if (ui.buscar) ui.buscar.disabled = false;
    ['cuit', 'razon_social', 'iva', 'domicilio', 'localidad', 'codigo_postal', 'provincia', 'telefono', 'emailfc'].forEach(function (n) { marcar(n, ''); });
    if (!cfg.arca && cfg.arcaMotivo) estado(esc(cfg.arcaMotivo), 'muted');
    texto = String(texto || '').trim();
    var d = digitos(texto);
    if (d.length >= 10 && /^[\d\s.\-]+$/.test(texto)) { ui.cuit.value = formatoCuit(d); }
    else if (texto) { campoEl('razon_social').value = texto.toUpperCase(); }
  }

  function buscar(forzar) {
    var d = digitos(ui.cuit.value);
    if (!d) { marcar('cuit', 'Escribí el CUIT.'); ui.cuit.focus(); return; }
    if (!cuitValido(d)) { marcar('cuit', 'El CUIT no es válido: revisá los números (el último es el verificador).'); ui.cuit.focus(); return; }
    ultimoCuit = d; marcar('cuit', ''); ui.error.hidden = true;
    var n = ++pedido;
    limpiarArca();
    estado('<span class="ac-girando" aria-hidden="true"></span> ' + (cfg.arca ? 'Buscando el CUIT en ARCA…' : 'Buscando el CUIT entre los clientes…'), 'muted');
    if (ui.buscar) ui.buscar.disabled = true;
    fetch(cfg.padron + '?cuit=' + d + (forzar ? '&forzar=1' : ''), { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (p) { if (n === pedido) mostrar(p); })
      .catch(function () { if (n === pedido) { estado('No se pudo consultar (¿sin conexión?). Completá los datos a mano.', 'ac-mal'); campoEl('razon_social').focus(); } })
      .then(function () { if (ui.buscar) ui.buscar.disabled = false; });
  }

  function mostrar(p) {
    if (!p.ok) { estado(''); marcar('cuit', p.error || 'El CUIT no es válido.'); return; }
    // ¿ya existe? se ofrece usar ese (lo más común: alguien no lo encontró por el nombre)
    if (p.existentes && p.existentes.length) {
      var h = '<p><b>Ya hay ' + (p.existentes.length === 1 ? 'un cliente' : p.existentes.length + ' clientes') + ' con este CUIT:</b></p><ul class="ac-lista-existe">';
      p.existentes.forEach(function (c, i) {
        h += '<li><span><b>' + esc(c.razon_social) + '</b> <small class="muted">' + esc(c.codigo + ' · ' + c.iva_texto + (c.localidad ? ' · ' + c.localidad : '')) + '</small></span>' +
          (c.activo ? '<button type="button" class="btn btn-sm" data-ac-usar="' + i + '">Usar este cliente</button>' : '<span class="badge badge-off">Inactivo</span>') + '</li>';
      });
      h += '</ul><p class="small muted">Si es otro cliente con el mismo CUIT (por ejemplo, otra sucursal), completá abajo y se crea igual.</p>';
      ui.existe.innerHTML = h; ui.existe.hidden = false; repetido = true;
      Array.prototype.forEach.call(ui.existe.querySelectorAll('[data-ac-usar]'), function (b) {
        b.addEventListener('click', function () { var c = p.existentes[+b.getAttribute('data-ac-usar')]; dlg.close(); if (alElegir) alElegir(c); });
      });
    }
    poner(p.datos || {}, !!p.arca);
    if (p.ficha && p.arca) {
      ui.ficha.innerHTML = fichaHtml(p.ficha, p.letra, p.avisos || []);
      ui.ficha.hidden = false;
      var otra = ui.ficha.querySelector('[data-ac-reconsultar]');
      if (otra) otra.addEventListener('click', function () { buscar(true); });
      estado('');
      var vacio = ['telefono', 'emailfc'].filter(function (n) { return !campoEl(n).value; })[0];
      (p.existentes && p.existentes.length ? ui.existe.querySelector('button') || campoEl(vacio || 'razon_social') : campoEl(vacio || 'razon_social')).focus();
    } else {
      estado(esc(p.error || 'ARCA no devolvió datos para este CUIT.') + ' Completá los datos a mano.', p.motivo === 'no_existe' ? 'ac-mal' : 'ac-aviso');
      if (!(p.existentes && p.existentes.length)) campoEl('razon_social').focus();
    }
  }

  function fichaHtml(f, letra, avisos) {
    var h = '<div class="ac-ficha-cab"><span class="badge badge-ok">' + esc(f.condicion) + '</span>' +
      (letra ? ' <span class="badge" data-ac-letra>Le corresponde factura ' + esc(letra) + '</span>' : '') +
      (f.estado && f.estado !== 'ACTIVO' ? ' <span class="badge badge-off">Clave ' + esc(f.estado) + '</span>' : '') +
      '<span class="ac-fuente small muted">' + (f.entorno === 'S' ? 'ARCA SIMULADO · ' : f.entorno === 'H' ? 'ARCA homologación · ' : 'Datos de ARCA · ') +
      esc(fechaHora(f.consultado_en)) + (f.de_cache ? ' (guardados) · <button type="button" class="btn-link" data-ac-reconsultar>volver a consultar</button>' : '') + '</span></div>';
    var act = (f.actividades || [])[0];
    var filas = [];
    if (f.tipo_persona) filas.push(['Tipo', f.tipo_persona === 'JURIDICA' ? 'Persona jurídica' : f.tipo_persona === 'FISICA' ? 'Persona humana' : f.tipo_persona]);
    if (act) filas.push(['Actividad principal', (act.id ? act.id + ' · ' : '') + act.descripcion]);
    if ((f.impuestos || []).length) filas.push(['Impuestos', f.impuestos.map(function (i) { return i.descripcion; }).join(' · ')]);
    if (f.provincia) filas.push(['Domicilio fiscal', [f.domicilio, f.localidad, f.provincia].filter(Boolean).join(', ')]);
    h += '<dl class="ac-datos">' + filas.map(function (x) { return '<dt>' + esc(x[0]) + '</dt><dd>' + esc(x[1]) + '</dd>'; }).join('') + '</dl>';
    if (avisos.length) h += '<ul class="ac-avisos small">' + avisos.map(function (a) { return '<li>' + esc(a) + '</li>'; }).join('') + '</ul>';
    return h;
  }

  function grabar() {
    ui.error.hidden = true;
    ['cuit', 'razon_social', 'iva', 'emailfc'].forEach(function (n) { marcar(n, ''); });
    var fd = new FormData(ui.form);
    if (ui.sinCuit.checked) fd.set('cuit', '');
    fd.append(cfg.csrfNombre, cfg.csrf);
    if (repetido) fd.append('cuit_repetido', '1');
    if (!campoEl('razon_social').value.trim()) { marcar('razon_social', 'Falta la razón social.'); campoEl('razon_social').focus(); return; }
    if (!campoEl('iva').value) { marcar('iva', 'Elegí la condición de IVA.'); campoEl('iva').focus(); return; }
    ui.crear.disabled = true; ui.crear.textContent = 'Creando…';
    fetch(cfg.crear, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, errores: { general: 'Error ' + r.status } }; }); })
      .then(function (r) {
        if (r.ok && r.cliente) { dlg.close(); if (alElegir) alElegir(r.cliente); if (window.FW && window.FW.aviso) window.FW.aviso('Cliente ' + r.cliente.codigo + ' creado.'); return; }
        var errs = r.errores || {}, primero = null;
        Object.keys(errs).forEach(function (k) {
          if (k === 'cuit_repetido') { repetido = true; return; }
          if (dlg.querySelector('[data-ac-err="' + k + '"]')) { marcar(k, errs[k]); primero = primero || k; }
          else { ui.error.textContent = errs[k]; ui.error.hidden = false; }
        });
        if (errs.cuit_repetido) { marcar('cuit', 'Ese CUIT ya lo tiene otro cliente. Si es correcto, tocá otra vez «Crear cliente y usarlo».'); primero = 'cuit'; }
        if (primero && campoEl(primero)) campoEl(primero).focus();
      })
      .catch(function () { ui.error.textContent = 'No se pudo grabar (¿sin conexión?). Probá de nuevo.'; ui.error.hidden = false; })
      .then(function () { ui.crear.disabled = false; ui.crear.textContent = 'Crear cliente y usarlo'; });
  }

  function abrir(opt, cb) {
    if (!leerCfg()) return;
    if (!dlg) crear();
    alElegir = cb || null;
    reiniciar(opt && opt.texto);
    if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
    if (digitos(ui.cuit.value).length === 11) { ui.cuit.dispatchEvent(new Event('input')); } else if (campoEl('razon_social').value) { ui.cuit.focus(); } else { ui.cuit.focus(); }
  }

  /** <li> para el final de la lista de un buscador de clientes. */
  function itemLista(q, cb) {
    var d = digitos(q), esCuit = d.length === 11 && /^[\d\s.\-]+$/.test(q.trim());
    var li = document.createElement('li');
    li.className = 'ac-item';
    li.setAttribute('role', 'option');
    li.tabIndex = 0;
    li.setAttribute('data-alta-item', '');
    li.innerHTML = '<b>+ ' + (esCuit ? 'Dar de alta el CUIT ' + esc(formatoCuit(d)) : 'Nuevo cliente') + '</b><small>' +
      (leerCfg() && cfg.arca ? 'Con el CUIT se traen solos los datos de ARCA' : 'Cargalo sin salir de la venta') + '</small>';
    var ir = function (ev) { if (ev) ev.preventDefault(); abrir({ texto: q }, cb); };
    li.addEventListener('mousedown', ir);
    li.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') ir(ev); });
    return li;
  }

  /** Ficha del cliente: completa el formulario con los datos de ARCA del CUIT escrito. */
  function completarFormulario(form) {
    var c = leerCfg(); if (!c) return;
    var cuit = form.querySelector('[name="cuit"]'), btn = form.querySelector('[data-traer-arca]'), msg = form.querySelector('[data-traer-arca-msg]');
    if (!cuit || !btn) return;
    var decir = function (t, clase) { if (msg) { msg.className = 'small ' + (clase || 'muted'); msg.innerHTML = t; } };
    btn.addEventListener('click', function () {
      var d = digitos(cuit.value);
      if (!cuitValido(d)) { decir('Escribí un CUIT válido (11 números) y tocá de nuevo.', 'ac-mal'); cuit.focus(); return; }
      var soloVacios = btn.getAttribute('data-auto') === '1'; btn.removeAttribute('data-auto');
      btn.disabled = true; decir('<span class="ac-girando" aria-hidden="true"></span> Buscando en ARCA…');
      fetch(c.padron + '?cuit=' + d + (btn.getAttribute('data-forzar') === '1' ? '&forzar=1' : ''), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (p) {
          if (!p.ok || !p.arca) { decir(esc(p.error || 'ARCA no devolvió datos para este CUIT.'), 'ac-aviso'); return; }
          // Con el botón se pisa todo lo distinto (marcado); al escribir el CUIT en un alta solo se completa lo vacío (y la condición de IVA).
          var cambios = [], distintos = [];
          Object.keys(p.datos).forEach(function (k) {
            var el = form.querySelector('[name="' + k + '"]'); if (!el || k === 'cuit') return;
            if (String(el.value).trim().toUpperCase() === String(p.datos[k]).trim().toUpperCase()) return;
            if (soloVacios && String(el.value).trim() !== '' && k !== 'iva') { distintos.push(p.datos[k]); return; }
            el.value = p.datos[k]; el.classList.add('ac-cambiado'); cambios.push(k);
          });
          var otros = (p.existentes || []).filter(function (x) { return String(x.codigo) !== String(form.getAttribute('data-codigo') || ''); });
          decir('<b>' + esc(p.ficha.condicion) + '</b>' + (p.letra ? ' · factura ' + esc(p.letra) : '') + ' · ' +
            (cambios.length ? cambios.length + ' dato(s) actualizados (marcados): revisalos y tocá Guardar.' : 'Los datos ya coinciden con ARCA.') +
            (otros.length ? '<br>Ojo: el CUIT también lo tiene el cliente ' + esc(otros[0].codigo + ' ' + otros[0].razon_social) + '.' : '') +
            (distintos.length ? '<br>En ARCA figura distinto: ' + distintos.map(esc).join(' · ') + ' (tocá «Traer datos de ARCA» para usarlo).' : '') +
            ((p.avisos || []).length ? '<br>' + p.avisos.map(esc).join('<br>') : ''), cambios.length ? 'ac-ok' : 'muted');
        })
        .catch(function () { decir('No se pudo consultar ARCA (¿sin conexión?).', 'ac-mal'); })
        .then(function () { btn.disabled = false; if (!soloVacios) btn.setAttribute('data-forzar', '1'); });
    });
    var auto = !form.getAttribute('data-codigo'), pedido = '';
    cuit.addEventListener('input', function () {
      btn.removeAttribute('data-forzar');
      var d = digitos(cuit.value);
      if (auto && d.length === 11 && d !== pedido && cuitValido(d)) { pedido = d; btn.setAttribute('data-auto', '1'); btn.click(); } // alta: con el CUIT completo se busca solo
    });
  }

  window.AltaCliente = {
    disponible: function () { return !!leerCfg(); },
    abrir: abrir, itemLista: itemLista, completarFormulario: completarFormulario,
    _cuitValido: cuitValido
  };

  document.addEventListener('DOMContentLoaded', function () {
    var f = document.querySelector('form[data-ficha-cliente]');
    if (f) completarFormulario(f);
  });
})();
