/*
 * Enviar un comprobante por WhatsApp: «Abrir WhatsApp» arma el mensaje (wa.me) para el número del cliente; «Compartir el PDF» (solo donde el
 * navegador puede compartir archivos, normalmente el celular) manda el PDF directo. WhatsApp no deja adjuntar un archivo desde un link.
 */
(function () {
  'use strict';
  var caja = document.querySelector('[data-compartir]');
  if (!caja) return;
  var texto = caja.getAttribute('data-texto') || '';
  var inpNum = caja.querySelector('[data-wa-numero]');

  // Argentina: sin 0 ni 15 al principio; con 54 delante (y 9 para celulares). Si ya viene con 54 se respeta.
  function numeroWa(txt) {
    var d = String(txt || '').replace(/\D/g, '');
    if (d === '') return '';
    if (d.indexOf('54') === 0) return d;
    d = d.replace(/^0+/, '');
    d = d.replace(/^(\d{2,4})15(\d{6,8})$/, '$1$2');
    return '549' + d;
  }
  caja.querySelector('[data-wa-abrir]').addEventListener('click', function () {
    var n = numeroWa(inpNum.value);
    window.open('https://wa.me/' + n + '?text=' + encodeURIComponent(texto), '_blank', 'noopener');
  });

  var btn = caja.querySelector('[data-wa-compartir]');
  if (navigator.share && navigator.canShare && btn) {
    btn.hidden = false;
    btn.addEventListener('click', function () {
      btn.disabled = true;
      fetch(caja.getAttribute('data-pdf'), { credentials: 'same-origin' })
        .then(function (r) { return r.blob(); })
        .then(function (b) {
          var f = new File([b], caja.getAttribute('data-nombre') || 'comprobante.pdf', { type: 'application/pdf' });
          if (!navigator.canShare({ files: [f] })) { alert('Este navegador no puede compartir el PDF: bajalo y adjuntalo a mano.'); return; }
          return navigator.share({ files: [f], text: texto });
        })
        .catch(function () {})
        .then(function () { btn.disabled = false; });
    });
  }
})();
