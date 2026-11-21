<?php
/** @var array<int,array<string,mixed>> $lista */
/** @var array<string,string> $errores */
/** @var array<string,string> $erroresApariencia */
/** @var array<string,mixed>|null $apariencia */
use App\Modules\Configuracion\Apariencia;
?>
<div class="page-head">
    <div>
        <h1>Configuración</h1>
        <p class="muted small">Ajustes del sistema web. Los datos de la empresa están en Tablas → Parámetros.</p>
    </div>
</div>
<form method="post" action="<?= e(url('/configuracion')) ?>" class="card">
    <?= csrf_field() ?>
    <?php foreach ($lista as $p): $id = 'p-' . preg_replace('/[^a-z0-9]/', '-', (string) $p['clave']); ?>
        <div class="field">
            <?php if ($p['tipo'] === 'si_no'): ?>
                <input type="hidden" name="valor[<?= e($p['clave']) ?>]" value="0">
                <label class="check"><input type="checkbox" id="<?= e($id) ?>" name="valor[<?= e($p['clave']) ?>]" value="1" <?= $p['valor'] === '1' ? 'checked' : '' ?>> <?= e($p['descripcion']) ?></label>
            <?php else: ?>
            <label for="<?= e($id) ?>"><?= e($p['descripcion']) ?></label>
            <input id="<?= e($id) ?>" name="valor[<?= e($p['clave']) ?>]" value="<?= e($p['valor']) ?>" <?= $p['tipo'] === 'entero' ? 'inputmode="numeric" class="corto"' : '' ?>>
            <?php endif; ?>
            <?php if (isset($errores[$p['clave']])): ?><p class="field-error"><?= e($errores[$p['clave']]) ?></p><?php endif; ?>
            <p class="small muted"><code><?= e($p['clave']) ?></code>
                <?php if ($p['actualizado_por'] !== null): ?> · cambiado por <?= e($p['actualizado_por']) ?> el <?= e(date('d/m/Y H:i', (int) strtotime((string) $p['actualizado_en']))) ?><?php endif; ?></p>
        </div>
    <?php endforeach; ?>
    <button class="btn" type="submit">Guardar</button>
</form>

<?php
$ap = $apariencia ?? null;
$apErr = $erroresApariencia ?? [];
$apNombre = (string) ($ap['nombre'] ?? Apariencia::nombre());
$apColor = Apariencia::color();
$apColorPropio = $ap !== null ? !empty($ap['color_propio']) : $apColor !== null;
$apColorValor = $ap !== null ? (string) ($ap['color'] ?? '') : (string) ($apColor ?? '#2f6bff');
$apLogo = $ap !== null ? !empty($ap['logo_en_pantalla']) : Apariencia::logoEnPantalla();
$apPlaca = $ap !== null ? !empty($ap['logo_placa']) : Apariencia::logoPlaca();
$apSugerido = '';
try { $apSugerido = (string) (\App\Core\Db::pdo()->query('SELECT empresa FROM parametros WHERE id = 1')->fetchColumn() ?: ''); } catch (\PDOException) {}
$apUrl = Apariencia::urlLogo();
?>
<section class="card" id="apariencia">
    <h2>Apariencia</h2>
    <p class="small muted">Cómo se ve el sistema para todos los usuarios de esta instalación: el logo y el nombre arriba, en la pantalla de ingreso,
        en la pestaña del navegador y en el portal de clientes, y el color de botones y resaltados. Si no se cambia nada se ve como FacturaWeb.</p>
    <form method="post" action="<?= e(url('/configuracion/apariencia')) ?>">
        <?= csrf_field() ?>
        <div class="field">
            <label for="ap-nombre">Nombre a mostrar</label>
            <input id="ap-nombre" name="nombre" value="<?= e($apNombre) ?>" maxlength="<?= Apariencia::NOMBRE_MAX ?>" placeholder="<?= e($apSugerido !== '' ? 'Por ejemplo: ' . $apSugerido : 'Vacío = FacturaWeb') ?>">
            <?php if (isset($apErr['nombre'])): ?><p class="field-error"><?= e($apErr['nombre']) ?></p><?php endif; ?>
            <p class="small muted">Va arriba al lado del logo (en el celular solo el logo) y en el título de la pestaña. Vacío: solo el logo, o «FacturaWeb» si no hay logo.</p>
        </div>
        <div class="field">
            <label class="check"><input type="checkbox" name="color_propio" value="1" <?= $apColorPropio ? 'checked' : '' ?> data-ap-color-propio> Usar un color propio</label>
            <div class="ap-color">
                <input type="color" id="ap-color" name="color" value="<?= e(Apariencia::normalizarColor($apColorValor) ?? '#2f6bff') ?>" aria-label="Color principal" data-ap-color>
                <span class="btn btn-sm ap-muestra" data-ap-muestra>Botón de muestra</span>
            </div>
            <?php if (isset($apErr['color'])): ?><p class="field-error"><?= e($apErr['color']) ?></p><?php endif; ?>
            <p class="small muted">Botones, enlaces, menú y resaltados. El texto de los botones pasa solo a blanco o negro y los enlaces se aclaran u oscurecen para que se lean con fondo oscuro y con fondo blanco. Sin tildar: el azul de FacturaWeb.</p>
        </div>
        <div class="field">
            <input type="hidden" name="logo_en_pantalla" value="0">
            <label class="check"><input type="checkbox" name="logo_en_pantalla" value="1" <?= $apLogo ? 'checked' : '' ?>> Mostrar el logo en la barra y en el ingreso</label>
            <label class="check"><input type="checkbox" name="logo_placa" value="1" <?= $apPlaca ? 'checked' : '' ?>> Poner el logo sobre una placa blanca (para logos oscuros con el fondo oscuro)</label>
        </div>
        <button class="btn" type="submit">Guardar apariencia</button>
    </form>

    <h3 class="sec">Logo de la pantalla</h3>
    <?php if ($apUrl !== null): ?>
        <div class="ap-logos">
            <div class="ap-fondo ap-fondo-oscuro"><img src="<?= e($apUrl) ?>" alt="Logo en fondo oscuro" class="<?= Apariencia::logoPlaca() ? 'brand-logo-placa' : '' ?>"></div>
            <div class="ap-fondo ap-fondo-claro"><img src="<?= e($apUrl) ?>" alt="Logo en fondo blanco" class="<?= Apariencia::logoPlaca() ? 'brand-logo-placa' : '' ?>"></div>
        </div>
    <?php endif; ?>
    <p class="small muted">Por omisión es el mismo logo de los comprobantes (abajo), con su fondo transparente. Si se ve mal con alguno de los dos fondos,
        se puede subir uno distinto solo para la pantalla (por ejemplo, la versión en blanco).
        <?= Apariencia::tieneLogoPantalla() ? 'Ahora se usa un logo propio para la pantalla.' : '' ?></p>
    <form method="post" action="<?= e(url('/configuracion/apariencia/logo')) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="field">
            <label for="ap-logo">Logo solo para la pantalla</label>
            <input id="ap-logo" type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp" required>
        </div>
        <button class="btn btn-ghost" type="submit">Subir logo de la pantalla</button>
    </form>
    <?php if (Apariencia::tieneLogoPantalla()): ?>
        <form method="post" action="<?= e(url('/configuracion/apariencia/logo/quitar')) ?>" style="margin-top:10px" onsubmit="return confirm('¿Quitar el logo de la pantalla? Vuelve a verse el de los comprobantes.')">
            <?= csrf_field() ?>
            <button class="btn btn-ghost" type="submit">Quitar logo de la pantalla</button>
        </form>
    <?php endif; ?>
</section>

<?php $logo = \App\Modules\Configuracion\Logo::obtener(); ?>
<section class="card" id="logo">
    <h2>Logo de los comprobantes</h2>
    <p class="small muted">Sale arriba a la izquierda en las facturas, notas de crédito y débito, recibos y en el PDF que se manda por mail, y también arriba en el sistema (ver Apariencia).
        PNG, JPG, GIF o WEBP de hasta 2 MB; conviene horizontal y con fondo blanco o transparente (lo transparente se imprime en blanco y en la pantalla queda transparente).</p>
    <?php if ($logo !== null): ?>
        <div class="logo-actual" style="border:1px solid var(--line-2); padding:8px; display:inline-block; background:#fff; margin-bottom:10px">
            <img src="<?= e((string) \App\Modules\Configuracion\Logo::dataUri()) ?>" alt="Logo actual" style="display:block; max-width:300px; max-height:80px">
        </div>
    <?php else: ?>
        <p class="small">Todavía no hay logo cargado.</p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/configuracion/logo')) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="field">
            <label for="logo-archivo"><?= $logo !== null ? 'Reemplazar por otra imagen' : 'Imagen del logo' ?></label>
            <input id="logo-archivo" type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp" required>
        </div>
        <button class="btn" type="submit">Subir logo</button>
    </form>
    <?php if ($logo !== null): ?>
        <form method="post" action="<?= e(url('/configuracion/logo/quitar')) ?>" style="margin-top:10px" onsubmit="return confirm('¿Quitar el logo de los comprobantes?')">
            <?= csrf_field() ?>
            <button class="btn btn-ghost" type="submit">Quitar logo</button>
        </form>
    <?php endif; ?>
</section>
