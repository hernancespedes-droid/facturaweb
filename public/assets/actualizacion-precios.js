/*
 * Actualización de precios por filtro (0.60.0): muestra la lista solo con «Una lista», la opción de los automáticos solo cuando se tocan listas
 * sin el costo, cambia el título y la ayuda del valor según la forma, y la lupa de «Desde / Hasta código» abre la lista de artículos.
 */
(function () {
  'use strict';
  var form = document.querySelector('.actualizacion-form');
  if (!form) return;
  var ayudas = {
    coeficiente: ['Coeficiente', 'Multiplica: 1,10 = 10 % más; 0,95 = 5 % menos.'],
    porcentaje: ['Porcentaje', 'Suma el porcentaje (negativo baja): 8 = 8 % más.'],
    monto: ['Monto fijo', 'Suma ese importe en pesos a cada precio (negativo resta). No se aplica a los artículos en dólares.']
  };
  function valor(nombre) { var r = form.querySelector('input[name="' + nombre + '"]:checked'); return r ? r.value : ''; }
  function mostrar(sel, si) { form.querySelectorAll(sel).forEach(function (el) { el.hidden = !si; }); }
  function actualizar() {
    var que = valor('que'), forma = valor('forma');
    mostrar('[data-solo-lista]', que === 'lista');
    mostrar('[data-solo-listas]', que === 'lista' || que === 'listas');
    mostrar('[data-solo-costo]', que === 'costo' || que === 'todo');
    var a = ayudas[forma];
    if (a) {
      form.querySelector('[data-valor-titulo]').textContent = a[0];
      form.querySelector('[data-valor-ayuda]').textContent = a[1];
    }
  }
  form.addEventListener('change', function (ev) { if (ev.target.matches('[data-que], [data-forma]')) actualizar(); });
  actualizar();
  form.querySelectorAll('[data-lista-desde-hasta]').forEach(function (b) {
    b.addEventListener('click', function () {
      var inp = b.parentNode.querySelector('input');
      if (!window.VentanaLista) return;
      window.VentanaLista.articulos(inp.getAttribute('data-buscador-url'), function (a) { inp.value = a.codigo; inp.focus(); });
    });
  });
})();
