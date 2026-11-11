// Comportamientos chicos de la interfaz. Todo funciona igual sin JavaScript.
document.addEventListener('DOMContentLoaded', function () {
  // Selector de empresa del encabezado: cambia al elegir.
  document.querySelectorAll('select[data-autosubmit], input[data-autosubmit]').forEach(function (s) {
    s.addEventListener('change', function () { if (s.value) s.form.submit(); });
  });
  // Alta de campo del diccionario: mostrar solo lo que aplica al tipo elegido.
  var tipo = document.getElementById('tipo');
  if (tipo) {
    var mostrar = function () {
      document.querySelectorAll('[data-solo-tipo]').forEach(function (el) {
        el.hidden = el.getAttribute('data-solo-tipo') !== tipo.value;
      });
    };
    tipo.addEventListener('change', mostrar);
    mostrar();
  }
  // Confirmaciones de acciones importantes.
  document.querySelectorAll('form[data-confirmar]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (!window.confirm(f.getAttribute('data-confirmar'))) ev.preventDefault();
    });
  });
  // 0.9.37: [data-tildar-todas="<name>"] tilda o destilda todas las casillas con ese name; un botón con
  // data-requiere-tildadas="<name>" no envía si no hay ninguna tildada.
  document.addEventListener('change', function (ev) {
    var t = ev.target.closest && ev.target.closest('[data-tildar-todas]');
    if (!t) return;
    (t.form || document).querySelectorAll('input[type="checkbox"][name="' + t.getAttribute('data-tildar-todas') + '"]').forEach(function (c) { c.checked = t.checked; });
  });
  document.addEventListener('submit', function (ev) {
    var b = ev.submitter, n = b && b.getAttribute('data-requiere-tildadas');
    if (n && !(b.form || document).querySelector('input[type="checkbox"][name="' + n + '"]:checked')) {
      ev.preventDefault();
      ev.stopImmediatePropagation();
      window.alert('Tildá al menos una.');
    }
  }, true);
  // 0.9.32: confirmación de un botón puntual del formulario (button[data-confirmar-boton]).
  document.addEventListener('submit', function (ev) {
    var b = ev.submitter;
    if (b && b.hasAttribute('data-confirmar-boton') && !window.confirm(b.getAttribute('data-confirmar-boton'))) ev.preventDefault();
  });
});
// Importar: confirmación del botón Importar y aviso de "procesando".
document.addEventListener('DOMContentLoaded', function () {
  var f = document.getElementById('form-importar');
  if (!f) return;
  f.addEventListener('submit', function (ev) {
    var b = ev.submitter;
    if (b && b.hasAttribute('data-confirmar-form') && !window.confirm(b.getAttribute('data-confirmar-form'))) {
      ev.preventDefault();
      return;
    }
    var c = f.querySelector('.cargando');
    if (c) c.hidden = false;
    // Deshabilitar después de que el navegador tome el valor del botón apretado.
    setTimeout(function () { f.querySelectorAll('button').forEach(function (x) { x.disabled = true; }); }, 0);
  });
});
// Consulta: el buscador queda listo para el lector de códigos (lo escrito queda seleccionado: la próxima lectura lo reemplaza).
document.addEventListener('DOMContentLoaded', function () {
  var q = document.querySelector('input[data-lector]');
  if (!q || window.matchMedia('(pointer: coarse)').matches) return;
  q.focus();
  q.select();
});
// "Volver" de la ficha: si se vino de una búsqueda del sistema, vuelve a esa búsqueda (con sus filtros y página).
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('a[data-volver]').forEach(function (a) {
    a.addEventListener('click', function (ev) {
      if (document.referrer && new URL(document.referrer).host === location.host && history.length > 1) {
        ev.preventDefault();
        history.back();
      }
    });
  });
});
// Formularios largos: cada sección se comprime o expande tocando su título. El navegador recuerda cuáles se
// comprimieron (por pantalla); una sección con errores se abre siempre.
document.addEventListener('DOMContentLoaded', function () {
  var secciones = document.querySelectorAll('details.seccion[data-seccion]');
  if (!secciones.length) return;
  var clave = 'secciones:' + location.pathname.replace(/\/\d+(?=\/|$)/g, '/n');
  var cerradas = {};
  try { cerradas = JSON.parse(localStorage.getItem(clave) || '{}') || {}; } catch (e) { cerradas = {}; }
  var guardar = function () { try { localStorage.setItem(clave, JSON.stringify(cerradas)); } catch (e) { /* sin almacenamiento */ } };
  secciones.forEach(function (d) {
    var n = d.getAttribute('data-seccion');
    if (cerradas[n] && !d.hasAttribute('data-con-error')) d.open = false;
    d.addEventListener('toggle', function () {
      if (d.open) delete cerradas[n]; else cerradas[n] = 1;
      guardar();
    });
  });
  document.querySelectorAll('[data-secciones-acciones]').forEach(function (barra) {
    barra.hidden = false;
    barra.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-secciones]');
      if (!b) return;
      var abrir = b.getAttribute('data-secciones') === 'abrir';
      secciones.forEach(function (d) { d.open = abrir || d.hasAttribute('data-con-error'); });
    });
  });
});
// Roles: "Es administrador/master" tilda todo y bloquea los permisos (al sacarlo vuelve lo elegido);
// una acción se habilita con su opción del menú.
document.addEventListener('DOMContentLoaded', function () {
  var caja = document.querySelector('[data-permisos]');
  if (!caja) return;
  var todos = document.querySelectorAll('input[data-todo]');
  var checks = caja.querySelectorAll('input[data-permiso]');
  var elegidos = null;
  var esTodo = function () { return Array.prototype.some.call(todos, function (t) { return t.checked; }); };
  function refrescar() {
    var todo = esTodo();
    if (todo && elegidos === null) {
      elegidos = Array.prototype.map.call(checks, function (i) { return i.checked; });
    } else if (!todo && elegidos !== null) {
      checks.forEach(function (i, n) { i.checked = elegidos[n]; });
      elegidos = null;
    }
    caja.querySelectorAll('fieldset').forEach(function (g) {
      var p = g.querySelector('input[data-principal]');
      g.querySelectorAll('input[data-permiso]').forEach(function (i) {
        if (todo) i.checked = true;
        i.disabled = todo || (p && i !== p && !p.checked);
      });
    });
  }
  todos.forEach(function (t) { t.addEventListener('change', refrescar); });
  caja.addEventListener('change', refrescar);
  // Un rol que ya es administrador llega con todo tildado: al sacarle la marca arranca vacío.
  if (caja.hasAttribute('data-bloqueado')) elegidos = Array.prototype.map.call(checks, function () { return false; });
  refrescar();
});
// Secciones plegables que recuerdan si el usuario las achicó (ej. la búsqueda de productos del carrito).
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('details[data-plegable]').forEach(function (d) {
    var clave = 'plegable:' + d.getAttribute('data-plegable');
    try {
      if (!d.hasAttribute('data-forzar-abierto') && localStorage.getItem(clave) === 'cerrado') d.open = false;
    } catch (e) { /* sin almacenamiento */ }
    d.addEventListener('toggle', function () {
      try { localStorage.setItem(clave, d.open ? 'abierto' : 'cerrado'); } catch (e) { /* */ }
    });
  });
});
// Listas que dependen de otra (ej. Modelo según la Marca): muestra solo las opciones de lo elegido arriba.
//  - select[data-depende="<name del otro select>"][data-mapa='{"FORD":["FIESTA",...]}'] (formulario de carga)
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('select[data-depende]').forEach(function (hijo) {
    var padre = hijo.form && hijo.form.querySelector('[name="' + hijo.getAttribute('data-depende') + '"]');
    if (!padre) return;
    var mapa = JSON.parse(hijo.getAttribute('data-mapa') || '{}');
    var marcaInicial = padre.value;
    var valorInicial = hijo.value;
    function filtrar() {
      var actual = hijo.value;
      var lista = mapa[padre.value] || [];
      hijo.innerHTML = '';
      hijo.add(new Option('—', ''));
      lista.forEach(function (m) { hijo.add(new Option(m, m, false, m === actual)); });
      // El modelo que ya tenía el vehículo (aunque esté inactivo) sigue elegible mientras no cambie la marca.
      if (valorInicial && padre.value === marcaInicial && lista.indexOf(valorInicial) < 0) {
        hijo.add(new Option(valorInicial, valorInicial, false, actual === valorInicial));
      }
    }
    filtrar();
    padre.addEventListener('change', filtrar);
  });

  // Remitos a cobrar: se eligen remitos de un solo cliente (un pago no mezcla clientes) y se ve cuánto suman.
  // (en su propia función: las variables de este archivo se comparten y "boton", "plata"... se usan más abajo)
  (function (cobranzas) {
    if (!cobranzas) return;
    var marcas = cobranzas.querySelectorAll('input[name="remitos[]"]');
    var texto = cobranzas.querySelector('[data-cobrar-texto]');
    var boton = cobranzas.querySelector('[data-cobrar]');
    var plata = function (c) { return '$ ' + (c / 100).toLocaleString('es-AR', { minimumFractionDigits: c % 100 ? 2 : 0, maximumFractionDigits: 2 }); };
    var actualizar = function () {
      var elegidos = Array.prototype.filter.call(marcas, function (m) { return m.checked; });
      var total = elegidos.reduce(function (t, m) { return t + Number(m.getAttribute('data-saldo')); }, 0);
      texto.textContent = elegidos.length ? elegidos.length + ' remito(s) de ' + elegidos[0].getAttribute('data-cliente-nombre') + ' · ' + plata(total) : 'Tildá los remitos a cobrar.';
      boton.disabled = elegidos.length === 0;
    };
    marcas.forEach(function (m) {
      m.addEventListener('change', function () {
        var otro = Array.prototype.find.call(marcas, function (x) { return x !== m && x.checked && x.getAttribute('data-cliente') !== m.getAttribute('data-cliente'); });
        if (m.checked && otro) {
          m.checked = false;
          alert('Un pago es de un solo cliente. Ya elegiste remitos de ' + otro.getAttribute('data-cliente-nombre') + ': para cobrarle a ' + m.getAttribute('data-cliente-nombre') + ', destildá los otros.');
        }
        actualizar();
      });
    });
    actualizar();
  })(document.querySelector('form[data-cobranzas]'));

  // Formulario de pago: más formas de pago, campos de cheque/referencia según la forma, distribuir y totales.
  var pago = document.querySelector('form[data-pago]');
  if (pago) {
    var num = function (t) {
      t = String(t || '').replace(/[\s$]/g, '');
      if (!t) return 0;
      t = /^\d{1,3}(\.\d{3})+(,\d{1,2})?$/.test(t) ? t.replace(/\./g, '').replace(',', '.') : t.replace(',', '.');
      var n = Math.round(parseFloat(t) * 100);
      return isNaN(n) ? 0 : n;
    };
    var txt = function (c) { return (c / 100).toLocaleString('es-AR', { minimumFractionDigits: c % 100 ? 2 : 0, maximumFractionDigits: 2 }); };
    var plata = function (c) { return (c < 0 ? '−' : '') + '$ ' + txt(Math.abs(c)); };
    var lineas = pago.querySelectorAll('[data-linea]');
    var otra = pago.querySelector('[data-otra-forma]');
    var ajustarLinea = function (l) {
      var op = l.querySelector('[data-forma]').selectedOptions[0];
      l.querySelectorAll('[data-solo-cheque]').forEach(function (el) { el.hidden = !op || op.getAttribute('data-cheque') !== '1'; });
      l.querySelectorAll('[data-solo-ref]').forEach(function (el) { el.hidden = !op || op.getAttribute('data-ref') !== '1'; });
    };
    var ocultas = function () { return Array.prototype.filter.call(lineas, function (l) { return l.hidden; }); };
    lineas.forEach(function (l) {
      if (l.hasAttribute('data-extra')) l.hidden = true;
      ajustarLinea(l);
      l.querySelector('[data-forma]').addEventListener('change', function () { ajustarLinea(l); });
    });
    if (otra) {
      otra.hidden = ocultas().length === 0;
      otra.addEventListener('click', function () {
        var l = ocultas()[0];
        if (l) { l.hidden = false; l.querySelector('[data-forma]').focus(); }
        otra.hidden = ocultas().length === 0;
      });
    }
    var resumen = pago.querySelector('[data-resumen]');
    var favor = pago.querySelector('[data-favor]');
    var imputar = pago.querySelectorAll('[data-imputar]');
    var totales = function () {
      var f = 0, i = 0;
      pago.querySelectorAll('[data-importe]').forEach(function (x) { f += num(x.value); });
      imputar.forEach(function (x) { i += num(x.value); });
      var u = favor ? num(favor.value) : 0;
      resumen.querySelector('[data-r="formas"]').textContent = plata(f);
      resumen.querySelector('[data-r="favor"]').textContent = plata(u);
      resumen.querySelector('[data-r="imputado"]').textContent = plata(i);
      var c = resumen.querySelector('[data-r="cuenta"]');
      c.textContent = plata(f + u - i);
      c.classList.toggle('saldo-deuda', f + u - i < 0);
      return f + u;
    };
    if (resumen) {
      resumen.hidden = false;
      pago.addEventListener('input', totales);
      totales();
    }
    var dist = pago.querySelector('[data-distribuir]');
    if (dist) {
      dist.hidden = false;
      dist.addEventListener('click', function () {
        var resto = totales();
        imputar.forEach(function (x) {
          var v = Math.min(resto, parseInt(x.getAttribute('data-saldo'), 10) || 0);
          x.value = v > 0 ? txt(v) : '';
          resto -= Math.max(v, 0);
        });
        totales();
      });
    }
  }

  // Agrupadores del menú.
  //  - Horizontal (escritorio): el agrupador despliega sus opciones; se cierra al tocar afuera o con Esc.
  //  - Lateral y celular: cada agrupador se pliega y despliega con su título. Arranca abierto solo el de la
  //    pantalla actual; lo que el usuario abre o cierra se recuerda en este navegador.
  var grupos = document.querySelectorAll('.menu-grupo');
  var desplegable = function () { return !document.body.classList.contains('menu-lateral') && window.matchMedia('(min-width: 821px)').matches; };
  var PLEGADO = 'oesteparts-menu-plegado';
  var guardado = {};
  try { guardado = JSON.parse(localStorage.getItem(PLEGADO) || '{}') || {}; } catch (e) { guardado = {}; }
  var boton = function (g) { return g.querySelector('.menu-grupo-btn'); };
  var plegar = function (g, si) { g.classList.toggle('plegado', si); boton(g).setAttribute('aria-expanded', si ? 'false' : 'true'); };
  var cerrarGrupos = function (salvo) {
    grupos.forEach(function (g) {
      if (g !== salvo) { g.classList.remove('abierto'); boton(g).setAttribute('aria-expanded', 'false'); }
    });
  };
  // Al pasar el mouse por otro agrupador, se cierra el que estaba abierto con clic (nunca quedan dos abiertos).
  grupos.forEach(function (g) {
    g.addEventListener('mouseenter', function () { if (desplegable() && !g.classList.contains('abierto')) cerrarGrupos(null); });
  });
  var aplicarPlegado = function () {
    grupos.forEach(function (g) {
      if (desplegable()) { g.classList.remove('plegado'); boton(g).setAttribute('aria-expanded', g.classList.contains('abierto') ? 'true' : 'false'); return; }
      var n = g.getAttribute('data-grupo');
      plegar(g, g.classList.contains('activo') ? false : (Object.prototype.hasOwnProperty.call(guardado, n) ? !!guardado[n] : true));
    });
  };
  aplicarPlegado();
  window.addEventListener('resize', aplicarPlegado);
  grupos.forEach(function (g) {
    boton(g).addEventListener('click', function () {
      if (desplegable()) {
        var abrir = !g.classList.contains('abierto');
        cerrarGrupos(g);
        g.classList.toggle('abierto', abrir);
        boton(g).setAttribute('aria-expanded', abrir ? 'true' : 'false');
        return;
      }
      var si = !g.classList.contains('plegado');
      plegar(g, si);
      guardado[g.getAttribute('data-grupo')] = si;
      try { localStorage.setItem(PLEGADO, JSON.stringify(guardado)); } catch (e) { /* sin almacenamiento: no se recuerda */ }
    });
  });
  document.addEventListener('click', function (ev) { if (desplegable() && !ev.target.closest('.menu-grupo')) cerrarGrupos(null); });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && desplegable()) cerrarGrupos(null); });
  // Menú lateral: arranca justo debajo del encabezado.
  var top = document.querySelector('.topbar');
  if (top && document.body.classList.contains('menu-lateral')) {
    var alto = function () { document.documentElement.style.setProperty('--alto-topbar', top.offsetHeight + 'px'); };
    alto();
    window.addEventListener('resize', alto);
  }

  // Ventanas: [data-abrir-ventana="<id del dialog>"] la abre, [data-cerrar-ventana] la cierra; con data-abrir-sola se abre al cargar (vuelve con errores).
  if (typeof HTMLDialogElement !== 'undefined') {
    document.querySelectorAll('[data-abrir-ventana]').forEach(function (b) {
      var v = document.getElementById(b.getAttribute('data-abrir-ventana'));
      if (v) b.addEventListener('click', function () { v.showModal(); var p = v.querySelector('input:not([type=hidden])'); if (p) p.focus(); });
    });
    document.querySelectorAll('dialog [data-cerrar-ventana]').forEach(function (b) {
      b.addEventListener('click', function () { b.closest('dialog').close(); });
    });
    document.querySelectorAll('dialog[data-abrir-sola]').forEach(function (v) { v.showModal(); });
  }

  // Fotos que se amplían: a[data-ampliar] abre la foto grande en un visor (sin JavaScript, se abre en otra pestaña).
  var visor = null;
  document.addEventListener('click', function (ev) {
    // a[data-ampliar] (la miniatura enlaza a la foto grande) o [data-ampliar-src] (una foto dentro de otro enlace,
    // como las tarjetas de Consulta: se amplía en vez de abrir la ficha).
    var a = ev.target.closest('a[data-ampliar], [data-ampliar-src]');
    if (!a || typeof HTMLDialogElement === 'undefined') return;
    ev.preventDefault();
    if (!visor) {
      visor = document.createElement('dialog');
      visor.className = 'visor';
      visor.innerHTML = '<button type="button" class="btn btn-sm visor-cerrar" aria-label="Cerrar">✕</button><img alt=""><p></p>';
      document.body.appendChild(visor);
      visor.addEventListener('click', function () { visor.close(); });
    }
    var img = a.tagName === 'IMG' ? a : a.querySelector('img');
    visor.querySelector('img').src = a.getAttribute('data-ampliar-src') || a.href;
    visor.querySelector('img').alt = img ? img.alt : '';
    visor.querySelector('p').textContent = (img ? img.alt + ' · ' : '') + 'Tocá para cerrar';
    visor.showModal();
  });

  // Botón Dark/Light: fondo oscuro (el de siempre) o blanco. Se guarda en una cookie de este navegador (un año).
  document.querySelectorAll('[data-tema-cambiar]').forEach(function (b) {
    b.addEventListener('click', function () {
      var claro = document.documentElement.getAttribute('data-tema') !== 'claro';
      document.documentElement.setAttribute('data-tema', claro ? 'claro' : 'oscuro');
      document.cookie = 'tema=' + (claro ? 'claro' : 'oscuro') + '; path=/; max-age=31536000; SameSite=Lax';
      b.querySelector('[data-tema-icono]').textContent = claro ? '☾' : '☀';
      b.querySelector('[data-tema-texto]').textContent = claro ? 'Dark' : 'Light';
      b.setAttribute('aria-label', claro ? 'Pasar a fondo oscuro' : 'Pasar a fondo blanco');
    });
  });

  // Tablas que entran enteras (sin páginas), con data-ordenable: se ordenan tocando el título de la columna
  // (▲/▼; números, importes y fechas como tales). En el celular, donde la tabla se ve como tarjetas sin títulos,
  // un selector "Ordenar por" hace lo mismo. La fila de totales (.fila-total) queda siempre al final.
  document.querySelectorAll('table[data-ordenable]').forEach(function (tabla) {
    var cuerpo = tabla.tBodies[0];
    if (!tabla.tHead || !cuerpo) return;
    var ths = [].slice.call(tabla.tHead.rows[0].cells);
    var valor = function (td) {
      var t = (td ? td.textContent : '').trim();
      var f = t.match(/^(\d{2})\/(\d{2})\/(\d{4})/);
      if (f) return { n: Number(f[3] + f[2] + f[1]) };
      var m = t.replace(/[\s$]/g, '').replace(/^[−-]/, '-');
      if (/^-?\d[\d.]*(,\d+)?$/.test(m)) return { n: parseFloat(m.replace(/\./g, '').replace(',', '.')) };
      return { s: t };
    };
    var botones = [];
    var sel = document.createElement('select');
    var ordenar = function (i, desc) {
      var filas = [].slice.call(cuerpo.rows);
      var fijas = filas.filter(function (r) { return r.classList.contains('fila-total'); });
      filas = filas.filter(function (r) { return !r.classList.contains('fila-total'); });
      filas.sort(function (a, b) {
        var x = valor(a.cells[i]), y = valor(b.cells[i]);
        var r = x.n !== undefined && y.n !== undefined ? x.n - y.n
          : String(x.s !== undefined ? x.s : x.n).localeCompare(String(y.s !== undefined ? y.s : y.n), 'es', { numeric: true, sensitivity: 'base' });
        return desc ? -r : r;
      });
      filas.concat(fijas).forEach(function (r) { cuerpo.appendChild(r); });
      botones.forEach(function (b) {
        var th = b.parentNode, esta = Number(b.getAttribute('data-col')) === i;
        if (esta) th.setAttribute('aria-sort', desc ? 'descending' : 'ascending'); else th.removeAttribute('aria-sort');
        b.textContent = b.getAttribute('data-t') + (esta ? (desc ? ' ▼' : ' ▲') : '');
        b.classList.toggle('activa', esta);
      });
      sel.value = i + (desc ? 'd' : 'a');
    };
    ths.forEach(function (th, i) {
      var t = th.textContent.trim();
      if (!t || th.hasAttribute('data-no-ordenar')) return;
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'th-orden';
      b.setAttribute('data-t', t);
      b.setAttribute('data-col', String(i));
      b.title = 'Ordenar por ' + t.toLowerCase();
      b.textContent = t;
      th.textContent = '';
      th.appendChild(b);
      b.addEventListener('click', function () { ordenar(i, th.getAttribute('aria-sort') === 'ascending'); });
      botones.push(b);
    });
    if (!botones.length) return;
    sel.className = 'orden-movil';
    sel.setAttribute('aria-label', 'Ordenar por');
    sel.add(new Option('Ordenar por…', ''));
    botones.forEach(function (b) {
      var i = b.getAttribute('data-col'), t = b.getAttribute('data-t');
      sel.add(new Option(t + ' ▲', i + 'a'));
      sel.add(new Option(t + ' ▼', i + 'd'));
    });
    sel.addEventListener('change', function () { if (sel.value) ordenar(Number(sel.value.slice(0, -1)), sel.value.slice(-1) === 'd'); });
    var envoltorio = tabla.closest('.table-wrap') || tabla;
    envoltorio.parentNode.insertBefore(sel, envoltorio);
  });
});

// Tablas más anchas que la pantalla (ej. Vehículos con muchas columnas): una barra de desplazamiento horizontal fija
// abajo de la ventana, mientras la tabla está a la vista y su propia barra no; las dos se mueven juntas.
// También se puede mover con Shift + rueda del mouse. En el celular las tablas se ven como tarjetas y no hace falta.
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.table-wrap').forEach(function (wrap) {
    var barra = document.createElement('div');
    barra.className = 'scroll-flotante';
    barra.hidden = true;
    barra.setAttribute('aria-hidden', 'true');
    barra.appendChild(document.createElement('div'));
    document.body.appendChild(barra);
    var moviendo = false;
    barra.addEventListener('scroll', function () { if (!moviendo) { moviendo = true; wrap.scrollLeft = barra.scrollLeft; moviendo = false; } });
    wrap.addEventListener('scroll', function () { if (!moviendo) { moviendo = true; barra.scrollLeft = wrap.scrollLeft; moviendo = false; } });
    var ubicar = function () {
      var r = wrap.getBoundingClientRect();
      var ancha = wrap.scrollWidth > wrap.clientWidth + 1;
      // Visible si la tabla es ancha, su parte de arriba ya se ve y su borde de abajo (con su barra) todavía no.
      var mostrar = ancha && r.top < window.innerHeight - 60 && r.bottom > window.innerHeight;
      barra.hidden = !mostrar;
      if (!mostrar) return;
      barra.style.left = r.left + 'px';
      barra.style.width = r.width + 'px';
      barra.firstChild.style.width = wrap.scrollWidth + 'px';
      barra.scrollLeft = wrap.scrollLeft;
    };
    window.addEventListener('scroll', ubicar, { passive: true });
    window.addEventListener('resize', ubicar);
    ubicar();
  });
});


// Importes (input[inputmode="decimal"]: precios, costos, pagos, filtros de precio, campos decimales del diccionario):
// mientras se escribe se agregan los puntos de miles, como en el resto del sistema: 12345 → 12.345, 1234567,5 → 1.234.567,5.
// El punto o la coma que escribe el usuario es la coma decimal (también el punto del teclado numérico y del celular);
// los puntos de miles los pone el sistema. Al salir del campo se quita una coma que quedó sola al final.
(function () {
  var esImporte = function (el) { return el && el.tagName === 'INPUT' && el.getAttribute('inputmode') === 'decimal'; };
  var grupos = function (d) { return d.replace(/\B(?=(\d{3})+(?!\d))/g, '.'); };
  document.addEventListener('input', function (ev) {
    var el = ev.target;
    if (!esImporte(el) || ev.isComposing) return;
    var v = el.value;
    var pos = el.selectionStart === null ? v.length : el.selectionStart;
    // El separador recién escrito (punto o coma) es la coma decimal.
    if ((ev.data === '.' || ev.data === ',') && pos > 0) v = v.slice(0, pos - 1).replace(/,/g, '') + ',' + v.slice(pos).replace(/,/g, '');
    // Pegado con punto decimal ("1500.50"): el punto es la coma.
    else if (ev.inputType === 'insertFromPaste' && v.indexOf(',') < 0 && /^\s*-?\$?\s*\d+\.\d{1,2}\s*$/.test(v)) v = v.replace('.', ',');
    var neg = /^\s*-/.test(v);
    // Cuántos dígitos (y coma) hay antes del cursor, para dejarlo en el mismo lugar después de formatear.
    var antes = v.slice(0, pos).replace(/[^\d,]/g, '').length;
    var limpio = v.replace(/[^\d,]/g, '');
    var coma = limpio.indexOf(',');
    var entero = (coma < 0 ? limpio : limpio.slice(0, coma)).replace(/^0+(?=\d)/, '');
    var maxDec = parseInt(el.getAttribute('data-decimales') || '2', 10);
    var dec = coma < 0 ? null : limpio.slice(coma + 1).replace(/,/g, '').slice(0, maxDec);
    var cero = dec !== null && entero === ''; // ",5" → "0,5" (el cursor corre uno por el 0 agregado)
    if (cero) antes++;
    var nuevo = (neg ? '-' : '') + grupos(entero) + (dec === null ? '' : (cero ? '0' : '') + ',' + dec);
    if (nuevo === el.value) return;
    el.value = nuevo;
    var i = neg ? 1 : 0, cuenta = 0;
    while (i < nuevo.length && cuenta < antes) { if (/[\d,]/.test(nuevo.charAt(i))) cuenta++; i++; }
    try { el.setSelectionRange(i, i); } catch (e) { /* tipo de campo sin selección */ }
  });
  document.addEventListener('change', function (ev) {
    if (esImporte(ev.target) && /,$/.test(ev.target.value)) ev.target.value = ev.target.value.slice(0, -1);
  }, true);
})();

// Ubicación en las grillas (span.ubic de ubicacion_chip()): al pasar el mouse, o al tocarla en el celular, muestra una
// ventanita con el depósito, el rack dibujado y el código. Se arma fuera de la tabla (posición fija) para que la
// tabla con desplazamiento no la corte.
(function () {
  var flot = null, de = null;
  var cerrar = function () { if (flot) { flot.remove(); flot = null; de = null; } };
  var abrir = function (chip) {
    var pop = chip.querySelector('.ubic-pop');
    if (!pop) return;
    if (de === chip) return;
    cerrar();
    de = chip;
    flot = document.createElement('div');
    flot.className = 'ubic-flotante';
    flot.setAttribute('role', 'tooltip');
    flot.innerHTML = pop.innerHTML;
    document.body.appendChild(flot);
    var r = chip.getBoundingClientRect(), f = flot.getBoundingClientRect();
    var top = r.top - f.height - 8;
    if (top < 8) top = r.bottom + 8;
    var left = Math.min(Math.max(8, r.left + r.width / 2 - f.width / 2), window.innerWidth - f.width - 8);
    flot.style.top = top + 'px';
    flot.style.left = left + 'px';
  };
  document.addEventListener('DOMContentLoaded', function () {
    // El title queda como nombre accesible; la ventanita lo reemplaza a la vista.
    document.querySelectorAll('.ubic[title]').forEach(function (c) { c.setAttribute('aria-label', c.getAttribute('title')); c.removeAttribute('title'); });
  });
  document.addEventListener('mouseover', function (ev) { var c = ev.target.closest && ev.target.closest('.ubic'); if (c) abrir(c); });
  document.addEventListener('mouseout', function (ev) { var c = ev.target.closest && ev.target.closest('.ubic'); if (c && !c.contains(ev.relatedTarget)) cerrar(); });
  document.addEventListener('focusin', function (ev) { var c = ev.target.closest && ev.target.closest('.ubic'); if (c) abrir(c); else cerrar(); });
  document.addEventListener('click', function (ev) { var c = ev.target.closest && ev.target.closest('.ubic'); if (c) abrir(c); else cerrar(); });
  window.addEventListener('scroll', cerrar, { passive: true, capture: true });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') cerrar(); });
})();

// Ventana de foto (Ver rack e inventario): el botón [data-abrir-foto] de cada pieza abre la ventana con la dirección
// de esa pieza. "Sacar foto" abre la cámara del celular (capture); "Subir del dispositivo", la galería o los archivos.
// Se ve una vista previa y se guarda sin salir de la pantalla (vuelve al mismo lugar).
(function () {
  if (typeof HTMLDialogElement === 'undefined') return;
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest && ev.target.closest('[data-abrir-foto]');
    if (!b) return;
    var v = document.getElementById('ventana-foto');
    if (!v) return;
    var f = v.querySelector('[data-foto-form]');
    f.reset();
    f.action = b.getAttribute('data-abrir-foto');
    v.querySelector('[data-foto-pieza]').textContent = b.getAttribute('data-foto-titulo') + ' · ' + b.getAttribute('data-foto-cuantas') + ' foto(s)';
    var previa = v.querySelector('[data-foto-previa]');
    previa.hidden = true;
    previa.removeAttribute('src');
    v.querySelector('[data-foto-guardar]').disabled = true;
    v.showModal();
  });
  document.addEventListener('change', function (ev) {
    var i = ev.target;
    if (!i.matches || !i.matches('[data-foto-input]') || !i.files || !i.files.length) return;
    var f = i.form;
    // Una sola fuente por vez: si elige de la galería, se descarta la de la cámara y al revés.
    f.querySelectorAll('[data-foto-input]').forEach(function (o) { if (o !== i) o.value = ''; });
    var previa = f.querySelector('[data-foto-previa]');
    previa.src = URL.createObjectURL(i.files[0]);
    previa.hidden = false;
    f.querySelector('[data-foto-guardar]').disabled = false;
  });
})();

// Buscador de clientes (select[data-combo], lo arma combo_clientes()): al tocarlo despliega todos los clientes y la
// lista se achica a medida que se escribe (cada palabra tiene que estar en el nombre, fantasía, CUIT/DNI o localidad;
// sin importar tildes ni mayúsculas). Flechas y Enter para elegir, Esc para cerrar. Con data-combo-enviar, elegir
// envía el formulario. Sin JavaScript queda el select común.
(function () {
  var norm = function (t) { return (t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); };
  var armar = function (sel) {
    var caja = document.createElement('div');
    caja.className = 'combo';
    var input = document.createElement('input');
    input.type = 'search';
    input.autocomplete = 'off';
    input.className = 'combo-input';
    input.placeholder = sel.getAttribute('data-combo-vacio') || 'Buscar';
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-label', sel.getAttribute('aria-label') || input.placeholder);
    if (sel.id) { input.id = sel.id; sel.removeAttribute('id'); }
    sel.removeAttribute('aria-label');
    sel.setAttribute('aria-hidden', 'true');
    if (sel.hasAttribute('autofocus')) input.autofocus = true;
    var lista = document.createElement('ul');
    lista.className = 'combo-lista';
    lista.setAttribute('role', 'listbox');
    lista.id = 'combo-' + Math.random().toString(36).slice(2);
    input.setAttribute('aria-controls', lista.id);
    lista.hidden = true;
    var cuenta = document.createElement('li');
    cuenta.className = 'combo-cuenta';
    lista.appendChild(cuenta);
    var items = [];
    Array.prototype.forEach.call(sel.options, function (o) {
      if (o.value === '') return;
      var li = document.createElement('li');
      li.setAttribute('role', 'option');
      li.id = lista.id + '-' + o.value;
      li.dataset.valor = o.value;
      var b = document.createElement('b');
      b.textContent = o.text;
      li.appendChild(b);
      var det = o.getAttribute('data-detalle');
      if (det) { var s = document.createElement('span'); s.className = 'small muted'; s.textContent = det; li.appendChild(s); }
      var buscar = o.getAttribute('data-buscar') || '';
      items.push({ li: li, texto: o.text, clave: norm(o.text + ' ' + (det || '') + ' ' + buscar), digitos: (buscar + ' ' + (det || '')).replace(/\D/g, '') });
      lista.appendChild(li);
    });
    sel.parentNode.insertBefore(caja, sel);
    caja.appendChild(input);
    caja.appendChild(lista);
    caja.appendChild(sel);
    sel.hidden = true;
    sel.tabIndex = -1;
    var etiqueta = function () { var o = sel.options[sel.selectedIndex]; return o && o.value !== '' ? o.text : ''; };
    input.value = etiqueta();
    var activo = -1, visibles = [];
    var marcar = function (i) {
      if (activo >= 0 && visibles[activo]) visibles[activo].li.classList.remove('activo');
      activo = i;
      if (i >= 0 && visibles[i]) {
        visibles[i].li.classList.add('activo');
        visibles[i].li.scrollIntoView({ block: 'nearest' });
        input.setAttribute('aria-activedescendant', visibles[i].li.id);
      } else input.removeAttribute('aria-activedescendant');
    };
    var filtrar = function () {
      var txt = input.value === etiqueta() ? '' : input.value;
      var palabras = norm(txt).split(/\s+/).filter(Boolean);
      visibles = [];
      items.forEach(function (it) {
        var ok = palabras.every(function (p) {
          var d = p.replace(/\D/g, '');
          return it.clave.indexOf(p) !== -1 || (d.length >= 3 && d.length === p.replace(/[\s.\-\/]/g, '').length && it.digitos.indexOf(d) !== -1);
        });
        it.li.hidden = !ok;
        it.li.classList.remove('activo');
        if (ok) visibles.push(it);
      });
      activo = -1;
      cuenta.textContent = visibles.length === 0 ? 'Ningún cliente coincide' : visibles.length + (visibles.length === 1 ? ' cliente' : ' clientes');
      if (palabras.length && visibles.length) marcar(0);
    };
    var abrir = function () { filtrar(); lista.hidden = false; input.setAttribute('aria-expanded', 'true'); };
    var cerrar = function () { lista.hidden = true; input.setAttribute('aria-expanded', 'false'); marcar(-1); };
    var elegir = function (it) {
      sel.value = it.li.dataset.valor;
      input.value = it.texto;
      cerrar();
      sel.dispatchEvent(new Event('change', { bubbles: true }));
      if (sel.hasAttribute('data-combo-enviar') && sel.form) {
        if (sel.form.requestSubmit) sel.form.requestSubmit(); else sel.form.submit();
      }
    };
    input.addEventListener('focus', abrir);
    input.addEventListener('click', function () { if (lista.hidden) abrir(); });
    input.addEventListener('input', function () { if (input.value === '' && sel.value !== '') { sel.value = ''; } abrir(); });
    input.addEventListener('keydown', function (ev) {
      if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
        ev.preventDefault();
        if (lista.hidden) abrir();
        if (!visibles.length) return;
        var i = activo + (ev.key === 'ArrowDown' ? 1 : -1);
        marcar(Math.max(0, Math.min(visibles.length - 1, i)));
      } else if (ev.key === 'Enter') {
        if (!lista.hidden && visibles[activo]) { ev.preventDefault(); elegir(visibles[activo]); }
        else if (!lista.hidden && visibles.length === 1) { ev.preventDefault(); elegir(visibles[0]); }
      } else if (ev.key === 'Escape') {
        if (!lista.hidden) { ev.preventDefault(); cerrar(); }
      }
    });
    // mousedown: antes de que el campo pierda el foco.
    lista.addEventListener('mousedown', function (ev) {
      var li = ev.target.closest('li[role="option"]');
      ev.preventDefault();
      if (!li) return;
      items.forEach(function (it) { if (it.li === li) elegir(it); });
    });
    input.addEventListener('blur', function () {
      cerrar();
      if (input.value.trim() === '') { if (sel.value !== '') { sel.value = ''; sel.dispatchEvent(new Event('change', { bubbles: true })); } input.value = ''; }
      else input.value = etiqueta();
    });
  };
  var iniciar = function () { document.querySelectorAll('select[data-combo]').forEach(armar); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar); else iniciar();
})();

// Grillas: las columnas que son solo números o importes ("$ 1.500", "12", "3/4") se alinean a la derecha.
(function () {
  var numero = /^(\$\s?)?-?[\d.]+(,\d+)?(\s*\/\s*\d+)?$/;
  var iniciar = function () {
    document.querySelectorAll('table.table').forEach(function (t) {
      var filas = t.tBodies.length ? Array.prototype.slice.call(t.tBodies[0].rows) : [];
      var ths = t.tHead && t.tHead.rows[0] ? t.tHead.rows[0].cells : [];
      for (var i = 0; i < ths.length; i++) {
        var hay = 0, todas = true;
        filas.forEach(function (f) {
          var c = f.cells[i];
          if (!c) return;
          if (c.querySelector('input:not([type="hidden"]), select, button, img')) { todas = false; return; }
          var txt = c.textContent.replace(/\s+/g, ' ').trim();
          if (txt === '') return;
          if (numero.test(txt)) hay++; else todas = false;
        });
        if (hay > 0 && todas) {
          ths[i].classList.add('num');
          filas.forEach(function (f) { if (f.cells[i]) f.cells[i].classList.add('num'); });
        }
      }
    });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar); else iniciar();
})();

// Buscadores que filtran solos (parámetro busqueda.filtrar_al_elegir → body[data-filtrar-solo]): en las barras de
// filtros (form.filtros) y en Consulta y Carrito (form.consulta-form), elegir un filtro aplica al instante y escribir
// aplica al dejar de tipear (desde 2 letras, o al borrar todo). Al volver la página, el cursor queda donde estaba.
// El lector de códigos sigue igual (lee y Enter). Sin el parámetro, como antes: con Buscar / Filtrar.
document.addEventListener('DOMContentLoaded', function () {
  if (document.body.getAttribute('data-filtrar-solo') !== '1') return;
  var CLAVE = 'filtro-foco';
  var texto = 'input[type="search"], input[type="text"], input:not([type]), input[inputmode]';
  var guardado = null;
  try { guardado = JSON.parse(sessionStorage.getItem(CLAVE) || 'null'); sessionStorage.removeItem(CLAVE); } catch (e) { guardado = null; }
  document.querySelectorAll('form.filtros, form.consulta-form').forEach(function (form) {
    if ((form.method || 'get').toLowerCase() !== 'get') return;
    form.classList.add('filtrar-solo');
    var reloj = null;
    var enviar = function (el) {
      clearTimeout(reloj);
      try {
        sessionStorage.setItem(CLAVE, JSON.stringify({ ruta: location.pathname, nombre: el && el.name ? el.name : '', pos: el && el.selectionStart != null ? el.selectionStart : null }));
      } catch (e) { /* sin sessionStorage: igual filtra */ }
      if (form.requestSubmit) form.requestSubmit(); else form.submit();
    };
    form.addEventListener('change', function (ev) {
      var el = ev.target;
      if (el.matches(texto) && !el.matches('input[type="date"]')) return; // los textos filtran al tipear
      enviar(el);
    });
    form.addEventListener('input', function (ev) {
      var el = ev.target;
      if (!el.matches(texto) || el.closest('.combo') || el.matches('input[type="date"]')) return;
      clearTimeout(reloj);
      var v = el.value.trim();
      if (v.length === 1) return;
      reloj = setTimeout(function () { enviar(el); }, 700);
    });
    if (guardado && guardado.ruta === location.pathname && guardado.nombre) {
      var el = form.elements[guardado.nombre];
      if (el && el.focus && !(el instanceof RadioNodeList)) {
        el.focus();
        try { var p = guardado.pos != null ? guardado.pos : el.value.length; el.setSelectionRange(p, p); } catch (e) { /* select o date */ }
      }
    }
  });
});

// Pegar del DNRPA: antes de Cargar, una ventana grande con lo que se va a hacer en cada vehículo (carpeta nueva, piezas
// nuevas o que pasan, datos que se completan y los que se cambian por lo del Registro). Recién con "Sí, hacerlo" se graba.
document.addEventListener('DOMContentLoaded', function () {
  var boton = document.querySelector('button[data-confirmar-pegado]');
  var ventana = document.getElementById('ventana-confirmar-pegado');
  if (!boton || !ventana || !ventana.showModal) return;
  var form = boton.form;
  var confirmado = false;
  var item = function (txt, clase) { var li = document.createElement('li'); li.textContent = txt; if (clase) li.className = clase; return li; };
  boton.addEventListener('click', function (ev) {
    if (confirmado) return;
    ev.preventDefault();
    var caja = ventana.querySelector('[data-resumen-pegado]');
    caja.textContent = '';
    var hay = 0;
    document.querySelectorAll('section.plan[data-plan]').forEach(function (s) {
      var d = s.dataset, dom = d.plan;
      var cambios = Array.prototype.map.call(s.querySelectorAll('input[data-cambio]:checked'), function (i) { return i.getAttribute('data-cambio'); });
      if (d.cargable !== '1' && cambios.length === 0) return;
      var bloque = document.createElement('section');
      bloque.className = 'resumen-vehiculo';
      var h = document.createElement('h3');
      h.textContent = d.titulo;
      bloque.appendChild(h);
      var ul = document.createElement('ul');
      var conf = s.querySelector('input[name="confirmar[' + dom + ']"]');
      if (d.pideConfirmar === '1' && conf && !conf.checked) {
        ul.appendChild(item('No se carga: falta tildar "Crear la carpeta en esta empresa" (tiene carpeta en ' + d.otras + ').', 'resumen-no'));
      } else {
        var nuevas = +d.nuevas, pasan = +d.pasan;
        if (d.creaCarpeta === '1') {
          var nro = s.querySelector('input[name="numeros[' + dom + ']"]');
          ul.appendChild(item('Se crea la carpeta ' + d.prefijo + '-' + (nro ? nro.value : '') + '.'));
        } else if (d.carpeta && (nuevas || pasan)) {
          ul.appendChild(item('En la carpeta ' + d.carpeta + '.'));
        }
        if (nuevas) ul.appendChild(item('Se cargan ' + nuevas + ' pieza(s) nueva(s).'));
        if (pasan) ul.appendChild(item('Pasan ' + pasan + ' pieza(s) desde ' + (d.otras || 'la otra empresa') + ' a esta empresa.', 'resumen-ojo'));
        if (d.completa) ul.appendChild(item('Se completan datos vacíos del vehículo: ' + d.completa + '.'));
        cambios.forEach(function (c) { ul.appendChild(item('Se CAMBIA por lo del Registro — ' + c, 'resumen-cambio')); });
      }
      bloque.appendChild(ul);
      caja.appendChild(bloque);
      hay++;
    });
    if (!hay) caja.appendChild(item('No hay nada para cargar: no hay piezas nuevas ni cambios elegidos.', 'resumen-no'));
    ventana.querySelector('[data-confirmar-si]').hidden = !hay;
    ventana.showModal();
  });
  ventana.querySelector('[data-confirmar-si]').addEventListener('click', function () {
    confirmado = true;
    ventana.close();
    if (form.requestSubmit) form.requestSubmit(boton); else boton.click();
  });
});

// Carpeta: Costo total = Monto + Otros gastos, mientras se escribe (no se edita: es solo la suma).
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-costos]').forEach(function (caja) {
    var total = caja.querySelector('[data-costo-total]');
    var campos = caja.querySelectorAll('[data-costo]');
    var leer = function (v) {
      v = (v || '').trim();
      if (v === '') return 0;
      if (v.indexOf(',') !== -1 || /^-?\d{1,3}(\.\d{3})+$/.test(v)) v = v.replace(/\./g, '').replace(',', '.'); // 1.234,50 o 1.500 (miles)
      var n = parseFloat(v);
      return isNaN(n) ? 0 : n;
    };
    var calcular = function () {
      var s = 0;
      campos.forEach(function (c) { s += leer(c.value); });
      total.textContent = '$ ' + s.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };
    campos.forEach(function (c) { c.addEventListener('input', calcular); c.addEventListener('change', calcular); });
    calcular();
  });
});

// Selector de ubicación (0.9.26): se abre tocando [data-ubicar]. Recorre el galpón como se camina: la entrada (filas de
// frente con sus pasillos), la fila desde arriba (estanterías de cada pasillo) y la estantería de frente (niveles).
//   data-ubicar="producto" | "carpeta"  + data-ubicar-id: guarda la ubicación de esa pieza o vehículo (si puede asignar)
//   data-ubicar="campo" + data-ubicar-campo="#id" (+ data-ubicar-texto="#id"): solo completa un campo del formulario
//   data-ubicar="ver": solo muestra dónde está
//   data-ubicacion="<id actual>", data-ubicar-titulo="lo que se ubica", data-en-vehiculo="1" (sigue en el auto armado)
document.addEventListener('DOMContentLoaded', function () {
  var dlg = document.getElementById('selector-ubicacion');
  if (!dlg || !dlg.showModal) return;
  var q = function (s) { return dlg.querySelector(s); };
  var mapa = null, porId = {}, abridor = null, mapas = {}; // 0.9.30: un plano por empresa (modo "Todas")
  var st = { g: null, fila: null, est: null, ub: null };
  var el = function (tag, cls, txt) { var x = document.createElement(tag); if (cls) x.className = cls; if (txt != null) x.textContent = txt; return x; };
  var galpon = function () { return mapa ? mapa.galpones.find(function (g) { return g.id === st.g; }) : null; };
  var fila = function () { var g = galpon(); return g && st.fila != null ? g.filas.find(function (f) { return f.numero === st.fila; }) : null; };
  var est = function () { var f = fila(); return f && st.est ? f.estanterias.find(function (e) { return e.lado + '-' + e.rack === st.est; }) : null; };
  var info = function (id) { return porId[id] || null; };

  function indexar() {
    porId = {};
    mapa.galpones.forEach(function (g) {
      g.filas.forEach(function (f) {
        f.estanterias.forEach(function (e) {
          e.niveles.forEach(function (n) { porId[n.id] = { g: g.id, fila: f.numero, est: e.lado + '-' + e.rack, nivel: n, texto: g.nombre + ' · Fila ' + f.numero + ' · Pasillo ' + e.pasillo + ' · Estantería ' + e.rack + ' · Nivel ' + n.nivel, codigo: n.codigo, autos: f.tipo === 'autos' }; });
        });
      });
      g.lugares.forEach(function (l) { porId[l.id] = { g: g.id, lugar: l, texto: g.nombre + ' · ' + l.nombre, codigo: l.codigo }; });
    });
  }
  function elegir(id) {
    var i = info(id);
    if (!i) return;
    st.g = i.g; st.ub = id;
    if (i.lugar) { st.fila = null; st.est = null; } else { st.fila = i.fila; st.est = i.est; }
    pintar();
  }
  function modo() { return abridor ? abridor.getAttribute('data-ubicar') : 'ver'; }
  function puedeGuardar() { var m = modo(); return m === 'campo' || ((m === 'producto' || m === 'carpeta') && mapa && mapa.puedeAsignar); }

  function pintarCamino() {
    var nav = q('[data-sel-camino]'); nav.textContent = '';
    var g = galpon(), f = fila(), e = est(), i = st.ub ? info(st.ub) : null;
    var partes = [];
    if (g) partes.push([g.nombre, function () { st.fila = st.est = st.ub = null; pintar(); }]);
    if (i && i.lugar) partes.push([i.lugar.nombre, null]);
    if (f) partes.push(['Fila ' + f.numero + (f.tipo === 'autos' ? ' (autos)' : ''), function () { st.est = st.ub = null; pintar(); }]);
    if (e) { partes.push(['Pasillo ' + e.pasillo, null]); partes.push(['Estantería ' + e.rack, function () { st.ub = null; pintar(); }]); }
    if (i && i.nivel) partes.push(['Nivel ' + i.nivel.nivel, null]);
    partes.forEach(function (p, k) {
      if (k) nav.appendChild(el('span', 'sep', '›'));
      var b = el('button', 'paso' + (k === partes.length - 1 ? ' actual' : ''), p[0]); b.type = 'button'; b.disabled = !p[1];
      if (p[1]) b.addEventListener('click', p[1]);
      nav.appendChild(b);
    });
  }
  function pintarGalpones() {
    var w = q('[data-sel-galpones]'); w.textContent = '';
    if (!mapa || mapa.galpones.length < 2) { w.hidden = true; return; }
    w.hidden = false;
    mapa.galpones.forEach(function (g) {
      var b = el('button', 'chip' + (g.id === st.g ? ' activo' : ''), g.nombre); b.type = 'button'; b.setAttribute('aria-pressed', String(g.id === st.g));
      b.addEventListener('click', function () { st.g = g.id; st.fila = st.est = st.ub = null; pintar(); });
      w.appendChild(b);
    });
  }
  function pintarVista() {
    var v = q('[data-sel-vista]'); v.textContent = '';
    var g = galpon(); if (!g) { v.appendChild(el('p', 'muted', 'Todavía no hay galpones armados: se arman en Depósitos y ubicaciones.')); return; }
    var f = fila();
    if (!f) {
      v.appendChild(el('h3', 'sel-subt', 'Entrando al galpón: tocá la fila'));
      if (g.foto) { var gf = el('img', 'sel-foto-galpon'); gf.src = g.foto; gf.alt = 'Foto de ' + g.nombre; gf.loading = 'lazy'; v.appendChild(gf); }
      var entrada = el('div', 'sel-entrada' + (g.espejo ? ' espejo' : '')); // 0.9.31: Fila 1 a la derecha
      if (g.filas.length === 0) entrada.appendChild(el('p', 'muted small', 'Este galpón no tiene filas.'));
      g.filas.forEach(function (fl, k) {
        entrada.appendChild(el('div', 'sel-pasillo', 'Pasillo ' + fl.numero));
        var b = el('button', 'sel-fila' + (fl.tipo === 'autos' ? ' autos' : '') + (fl.foto ? ' con-foto' : '')); b.type = 'button';
        if (fl.foto) b.style.setProperty('--foto', 'url("' + fl.foto + '")'); // 0.9.29: la foto de referencia de la fila
        var c = el('span', 'cartel', 'FILA Nº' + fl.numero); c.appendChild(el('small', null, fl.tipo === 'autos' ? 'Autos enteros' : fl.estanterias.length + ' estanterías')); b.appendChild(c);
        b.setAttribute('aria-label', 'Fila ' + fl.numero + ', entre el pasillo ' + fl.numero + ' y el ' + (fl.numero + 1));
        b.addEventListener('click', function () { st.fila = fl.numero; st.est = st.ub = null; pintar(); });
        entrada.appendChild(b);
        if (k === g.filas.length - 1) entrada.appendChild(el('div', 'sel-pasillo', 'Pasillo ' + (fl.numero + 1)));
      });
      v.appendChild(entrada);
      v.appendChild(el('p', 'sel-porton', '▲ Portón ▲'));
      if (g.lugares.length) {
        v.appendChild(el('h3', 'sel-subt', 'Lugares'));
        var lw = el('div', 'sel-lugares');
        g.lugares.forEach(function (l) {
          var b = el('button', 'chip' + (st.ub === l.id ? ' activo' : ''), '🅿 ' + l.nombre + (l.piezas + l.vehiculos ? ' · ' + (l.piezas + l.vehiculos) : '')); b.type = 'button';
          b.addEventListener('click', function () { st.ub = l.id; pintar(); });
          lw.appendChild(b);
        });
        v.appendChild(lw);
      }
      return;
    }
    v.appendChild(el('h3', 'sel-subt', 'Fila ' + f.numero + ' desde arriba: tocá la estantería'));
    var top = el('div', 'sel-arriba' + (galpon().espejo ? ' espejo' : ''));
    var e = est();
    var pas = function (n) { var p = el('div', 'sel-pas-v' + (e && e.pasillo === n ? ' marcado' : '')); p.appendChild(el('span', null, 'Pasillo ' + n)); return p; };
    top.appendChild(pas(f.numero));
    ['I', 'D'].forEach(function (lado) {
      var col = el('div', 'sel-lado sel-lado-' + lado);
      f.estanterias.filter(function (x) { return x.lado === lado; }).forEach(function (x) {
        var cant = x.niveles.reduce(function (s, n) { return s + n.piezas + n.vehiculos; }, 0);
        var b = el('button', 'sel-est' + (cant ? ' con' : '')); b.type = 'button';
        b.appendChild(el('b', null, (f.tipo === 'autos' ? '🚗 ' : 'E') + x.rack)); b.appendChild(el('small', null, x.niveles.length + ' niv. · ' + cant));
        b.setAttribute('aria-pressed', String(st.est === lado + '-' + x.rack));
        b.setAttribute('aria-label', 'Estantería ' + x.rack + ', se saca desde el pasillo ' + x.pasillo);
        b.addEventListener('click', function () { st.est = lado + '-' + x.rack; st.ub = null; pintar(); });
        col.appendChild(b);
      });
      if (!col.children.length) col.appendChild(el('span', 'muted small', 'Sin estanterías'));
      top.appendChild(col);
    });
    top.appendChild(pas(f.numero + 1));
    v.appendChild(top);
  }
  function pintarFrente() {
    var w = q('[data-sel-frente]'); w.textContent = '';
    var e = est(), f = fila();
    if (!e) { w.hidden = true; return; }
    w.hidden = false;
    w.appendChild(el('h3', 'sel-subt', 'Estantería ' + e.rack + ' de frente'));
    w.appendChild(el('p', 'small sel-donde', 'Se saca desde el Pasillo ' + e.pasillo + ': entrando por ese pasillo queda a tu ' + ((e.lado === 'I') !== !!galpon().espejo ? 'derecha' : 'izquierda') + '.'));
    var rack = el('div', 'sel-rack');
    e.niveles.slice().sort(function (a, b) { return b.nivel - a.nivel; }).forEach(function (n) {
      var b = el('button', 'sel-nivel'); b.type = 'button';
      b.appendChild(el('span', 'n', 'Nivel ' + n.nivel + (n.nivel === 1 ? ' · abajo' : '')));
      b.appendChild(el('span', 'c', f.tipo === 'autos' ? (n.vehiculos ? n.vehiculos + ' auto(s)' : 'libre') : (n.piezas ? n.piezas + ' pieza(s)' : 'libre')));
      b.setAttribute('aria-pressed', String(st.ub === n.id));
      b.addEventListener('click', function () { st.ub = n.id; pintar(); });
      rack.appendChild(b);
    });
    w.appendChild(rack);
  }
  function pintarAcciones() {
    var listo = q('[data-sel-listo]'), sacar = q('[data-sel-sacar]');
    var i = st.ub ? info(st.ub) : null, m = modo();
    listo.hidden = !puedeGuardar();
    sacar.hidden = !((m === 'producto' || m === 'carpeta') && mapa && mapa.puedeAsignar && abridor && abridor.getAttribute('data-ubicacion'));
    sacar.textContent = m === 'carpeta' ? 'Sin ubicación' : 'Sin ubicación propia (con su vehículo)';
    listo.disabled = !i;
    listo.textContent = i ? (m === 'campo' ? 'Usar ' : 'Ubicar en ') + i.codigo : 'Elegí el nivel';
  }
  function pintar() { pintarCamino(); pintarGalpones(); pintarVista(); pintarFrente(); pintarAcciones(); }

  function pintarRecientes() {
    var w = q('[data-sel-recientes]'); w.textContent = '';
    if (!mapa || !mapa.recientes.length || !puedeGuardar()) return;
    w.appendChild(el('span', 'muted small', 'Últimas usadas:'));
    mapa.recientes.forEach(function (r) {
      if (!info(r.id)) return;
      var b = el('button', 'chip', r.corto); b.type = 'button'; b.title = r.texto;
      b.addEventListener('click', function () { elegir(r.id); });
      w.appendChild(b);
    });
  }
  q('[data-sel-buscar]').addEventListener('input', function (ev) {
    var t = ev.target.value.trim().toUpperCase();
    if (!t || !mapa) return;
    var porCodigo = Object.keys(porId).find(function (id) { return porId[id].codigo === t; });
    if (porCodigo) { elegir(+porCodigo); return; }
    var m = t.match(/^F\s*(\d+)(?:\D+E?\s*(\d+))?(?:\D+(\d+))?/);
    var g = galpon(); if (!m || !g) return;
    var f = g.filas.find(function (x) { return x.numero === +m[1]; }); if (!f) return;
    st.fila = f.numero; st.est = st.ub = null;
    if (m[2]) {
      var e = f.estanterias.find(function (x) { return x.rack === +m[2]; });
      if (e) { st.est = e.lado + '-' + e.rack; if (m[3]) { var n = e.niveles.find(function (x) { return x.nivel === +m[3]; }); if (n) st.ub = n.id; } }
    }
    pintar();
  });
  q('[data-sel-buscar]').addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); if (!q('[data-sel-listo]').disabled) q('[data-sel-listo]').click(); } });

  var form = q('[data-sel-form]');
  form.addEventListener('submit', function (ev) {
    var m = modo();
    if (m === 'campo') {
      ev.preventDefault();
      var campo = document.querySelector(abridor.getAttribute('data-ubicar-campo'));
      if (campo) campo.value = String(st.ub);
      var txt = abridor.getAttribute('data-ubicar-texto') && document.querySelector(abridor.getAttribute('data-ubicar-texto'));
      if (txt) txt.textContent = info(st.ub).texto;
      dlg.close();
      return;
    }
    q('[data-sel-id]').value = String(st.ub || '');
  });
  q('[data-sel-sacar]').addEventListener('click', function () { q('[data-sel-id]').value = ''; form.submit(); });

  function abrir(b) {
    abridor = b;
    var m = modo();
    q('[data-sel-que]').textContent = b.getAttribute('data-ubicar-titulo') || '';
    q('#sel-titulo').textContent = m === 'ver' || !puedeGuardar() ? 'Dónde está' : 'Elegir ubicación';
    if (m === 'producto' || m === 'carpeta') {
      form.action = (b.getAttribute('data-ubicar-asignar') || dlg.getAttribute('data-asignar')) + '/' + m + '/' + b.getAttribute('data-ubicar-id');
      q('[data-sel-volver]').value = b.getAttribute('data-volver') || dlg.getAttribute('data-volver') || '/';
      q('[data-sel-todas]').value = b.getAttribute('data-ubicar-asignar') ? '1' : ''; // vuelve a la grilla de "Todas"
    }
    var actual = +(b.getAttribute('data-ubicacion') || 0);
    var cab = q('[data-sel-actual]');
    var i = actual ? info(actual) : null;
    cab.hidden = !i;
    cab.textContent = i ? (b.getAttribute('data-en-vehiculo') === '1' ? '🚗 Está en el auto armado, en: ' : '📍 Está en: ') + i.texto : '';
    st.g = mapa.galpones.length ? mapa.galpones[0].id : null; st.fila = st.est = st.ub = null;
    if (i) elegir(actual); else pintar();
    pintarRecientes();
    q('[data-sel-buscar]').value = '';
    if (!dlg.open) dlg.showModal();
  }
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest && ev.target.closest('[data-ubicar]');
    if (!b || dlg.contains(b)) return;
    ev.preventDefault();
    // En "Todas" la pieza trae el plano de su empresa (data-ubicar-mapa); si no, el de la empresa activa.
    var url = b.getAttribute('data-ubicar-mapa') || dlg.getAttribute('data-mapa');
    var ir = function () { mapa = mapas[url]; indexar(); abrir(b); };
    if (mapas[url]) { ir(); return; }
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        if (!r.ok || (r.headers.get('Content-Type') || '').indexOf('json') < 0) throw new Error('plano');
        return r.json();
      })
      .then(function (d) { mapas[url] = d; ir(); })
      .catch(function () { window.alert('No se pudo abrir el plano. Recargá la página (Ctrl+F5) y probá de nuevo.'); });
  });
});

// 0.9.38: paginador de las grillas. Las flechas ← → del teclado pasan de página (si no se está escribiendo ni hay
// cambios sin guardar en la grilla); "Ir a" no deja pedir una página fuera de rango.
(function () {
  var sucio = false;
  document.addEventListener('input', function (ev) { if (ev.target.closest && ev.target.closest('form[method="post"] table')) sucio = true; });
  document.addEventListener('keydown', function (ev) {
    if ((ev.key !== 'ArrowLeft' && ev.key !== 'ArrowRight') || ev.altKey || ev.ctrlKey || ev.metaKey || ev.shiftKey || sucio) return;
    var a = document.activeElement;
    if (a && (a.isContentEditable || /^(INPUT|SELECT|TEXTAREA)$/.test(a.tagName))) return;
    if (document.querySelector('dialog[open]')) return;
    var nav = document.querySelector('nav.paginador[data-paginador]');
    var l = nav && nav.querySelector(ev.key === 'ArrowLeft' ? 'a[rel="prev"]' : 'a[rel="next"]');
    if (l) { ev.preventDefault(); window.location.href = l.href; }
  });
  document.addEventListener('submit', function (ev) {
    var f = ev.target.closest && ev.target.closest('form[data-pag-ir]');
    if (!f) return;
    var i = f.querySelector('input[name="pagina"]');
    var n = Math.round(Number(i.value)), max = Number(i.max) || 1;
    if (!isFinite(n) || n < 1) n = 1;
    if (n > max) n = max;
    i.value = String(n);
  });
})();
