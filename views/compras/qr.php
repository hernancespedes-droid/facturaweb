<?php
/** Cargar una compra desde el QR de la factura electrónica. @var ?string $error @var string $texto */
?>
<div class="page-head">
    <div><p class="eyebrow">Compras</p><h1>Cargar desde el QR</h1></div>
    <div class="acciones"><a class="btn btn-ghost" href="<?= e(url('/compras/nueva')) ?>">Cargar a mano</a></div>
</div>
<?php if ($error): ?><div class="alerta alerta-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<p class="small muted">Toda factura electrónica trae un código QR. Al leerlo se abre la compra con el proveedor (si no está, se da de alta), el tipo, el número,
    la fecha, el total y el CAE; solo falta completar el neto y el IVA y elegir el centro de costos.</p>

<form method="post" action="<?= e(url('/compras/qr')) ?>" id="form-qr">
    <?= csrf_field() ?>
    <section class="card qr-lector" data-qr>
        <h2>Con la cámara</h2>
        <div class="qr-video" data-qr-zona hidden>
            <video data-qr-video playsinline muted></video>
            <div class="qr-marco" aria-hidden="true"></div>
        </div>
        <p class="small" data-qr-estado aria-live="polite"></p>
        <div class="toolbar">
            <button type="button" class="btn" data-qr-camara>Abrir la cámara</button>
            <label class="btn btn-ghost">Leer de una foto<input type="file" accept="image/*" capture="environment" data-qr-foto hidden></label>
        </div>
        <p class="small muted" data-qr-sin-lector hidden>Este navegador no lee códigos QR (pasa en iPhone y en algunas computadoras). Leé el QR con la cámara del
            teléfono, copiá el enlace que aparece (empieza con https://www.afip.gob.ar/fe/qr/ o https://www.arca.gob.ar/…) y pegalo acá abajo.</p>
    </section>
    <section class="card">
        <h2>O pegá el enlace del QR</h2>
        <label class="field"><span>Enlace o contenido del QR</span>
            <textarea name="qr" rows="3" data-qr-texto placeholder="https://www.afip.gob.ar/fe/qr/?p=…"><?= e($texto) ?></textarea></label>
        <div class="acciones"><button class="btn" type="submit">Abrir la compra</button></div>
    </section>
</form>
<script src="<?= e(asset('assets/compras-qr.js')) ?>" defer></script>
