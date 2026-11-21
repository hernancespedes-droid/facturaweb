<?php /** @var string $content @var string $empresa @var string|null $title */
use App\Modules\Configuracion\Apariencia;
$logoUrl = Apariencia::urlLogo();
$iconoUrl = Apariencia::urlIcono();
$estiloMarca = Apariencia::estilo();
if (Apariencia::nombre() !== '') {
    $empresa = Apariencia::nombre();
}
$tema = ($_COOKIE['tema'] ?? '') === 'oscuro' ? 'oscuro' : 'claro';
?><!doctype html>
<html lang="es-AR" data-tema="<?= $tema ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e(($title ?? 'Mis comprobantes') . ($empresa !== '' ? ' - ' . $empresa : '')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/app.css')) ?>">
    <?php if ($estiloMarca !== ''): ?><style><?= $estiloMarca /* solo #rrggbb validados */ ?></style><?php endif; ?>
    <?php if ($iconoUrl !== null): ?><link rel="icon" type="image/png" href="<?= e($iconoUrl) ?>"><?php endif; ?>
</head>
<body class="portal">
    <header class="topbar"><div class="wrap topbar-in"><span class="brand<?= $logoUrl !== null ? ' brand-con-logo' : '' ?>"><?php if ($logoUrl !== null): ?><img class="brand-logo<?= Apariencia::logoPlaca() ? ' brand-logo-placa' : '' ?>" src="<?= e($logoUrl) ?>" alt=""><?php endif; ?><span class="brand-nombre"><?= e($empresa !== '' ? $empresa : 'Mis comprobantes') ?></span></span></div></header>
    <main class="wrap portal-main"><?= $content ?></main>
    <footer class="pie wrap">Factura<b>Web</b> · Organización &amp; Sistemas</footer>
</body>
</html>
