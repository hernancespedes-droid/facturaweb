/*
 * Cargar una compra desde el QR de la factura: lee el código con la cámara (o de una foto) usando el lector del navegador
 * (BarcodeDetector) y manda el contenido al servidor, que lo interpreta. Si el navegador no tiene lector, queda pegar el enlace.
 */
(function () {
  'use strict';
  var form = document.getElementById('form-qr');
  if (!form) return;
  var $ = function (s) { return form.querySelector(s); };
  var estado = $('[data-qr-estado]'), texto = $('[data-qr-texto]'), video = $('[data-qr-video]'), zona = $('[data-qr-zona]');
  var lector = null;
  try {
    if ('BarcodeDetector' in window) lector = new window.BarcodeDetector({ formats: ['qr_code'] });
  } catch (e) { lector = null; }

  function sinLector() {
    $('[data-qr-sin-lector]').hidden = false;
    $('[data-qr-camara]').hidden = true;
    $('[data-qr-foto]').closest('label').hidden = true;
  }
  if (!lector) { sinLector(); return; }
  if (window.BarcodeDetector.getSupportedFormats) {
    window.BarcodeDetector.getSupportedFormats().then(function (f) { if (f.indexOf('qr_code') < 0) sinLector(); }).catch(function () {});
  }

  var flujo = null, buscando = false;
  function apagar() {
    buscando = false;
    if (flujo) flujo.getTracks().forEach(function (t) { t.stop(); });
    flujo = null;
    zona.hidden = true;
  }
  function encontrado(valor) {
    apagar();
    texto.value = valor;
    estado.textContent = 'Código leído. Abriendo la compra…';
    form.submit();
  }
  function cuadro() {
    if (!buscando) return;
    lector.detect(video).then(function (r) {
      if (r && r.length && r[0].rawValue) encontrado(r[0].rawValue);
      else setTimeout(cuadro, 250);
    }).catch(function () { setTimeout(cuadro, 400); });
  }
  $('[data-qr-camara]').addEventListener('click', function () {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      estado.textContent = 'No se puede abrir la cámara desde acá (hace falta entrar por https). Probá con una foto o pegá el enlace.';
      return;
    }
    estado.textContent = 'Pidiendo permiso para usar la cámara…';
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }).then(function (s) {
      flujo = s;
      video.srcObject = s;
      zona.hidden = false;
      return video.play();
    }).then(function () {
      estado.textContent = 'Apuntá al código QR de la factura.';
      buscando = true;
      cuadro();
    }).catch(function () {
      apagar();
      estado.textContent = 'No se pudo usar la cámara (¿se negó el permiso?). Probá con una foto o pegá el enlace.';
    });
  });
  $('[data-qr-foto]').addEventListener('change', function (ev) {
    var f = ev.target.files && ev.target.files[0];
    if (!f) return;
    estado.textContent = 'Buscando el código en la foto…';
    var img = new Image();
    img.onload = function () {
      lector.detect(img).then(function (r) {
        URL.revokeObjectURL(img.src);
        if (r && r.length && r[0].rawValue) encontrado(r[0].rawValue);
        else estado.textContent = 'No se encontró un código QR en la foto. Probá con otra más cerca y con buena luz.';
      }).catch(function () { estado.textContent = 'No se pudo leer la foto.'; });
    };
    img.src = URL.createObjectURL(f);
  });
  window.addEventListener('pagehide', apagar);
})();
