<?php /** @var string $content @var string $empresa @var string|null $title */
$tema = ($_COOKIE['tema'] ?? '') === 'oscuro' ? 'oscuro' : 'claro';
?><!doctype html>
<html lang="es-AR" data-tema="<?= $tema ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e(($title ?? 'Mis comprobantes') . ($empresa !== '' ? ' - ' . $empresa : '')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/app.css')) ?>">
</head>
<body class="portal">
    <header class="topbar"><div class="wrap topbar-in"><span class="brand"><span><?= e($empresa !== '' ? $empresa : 'Mis comprobantes') ?></span></span></div></header>
    <main class="wrap portal-main"><?= $content ?></main>
</body>
</html>
