<?php
/** @var string $content */
/** @var string|null $title */
use App\Core\Menu;
use App\Core\Session;
use App\Modules\Auth\Auth;

$usuarioActual = Auth::usuario();
$flashes = Session::takeFlash();
$navActual = $nav ?? '';
$nombreSistema = (string) \App\Core\Config::get('app.name', 'FacturaWeb');
// Tema que eligió este usuario en este navegador (botón Dark/Light): cookie "tema", claro si no eligió.
$tema = ($_COOKIE['tema'] ?? '') === 'oscuro' ? 'oscuro' : 'claro';
$alDia = $usuarioActual !== null && (int) $usuarioActual['debe_cambiar_clave'] === 0;
?><!doctype html>
<html lang="es-AR" data-tema="<?= $tema ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($title ?? '') !== '' ? $title . ' - ' : '') ?><?= e($nombreSistema) ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/app.css')) ?>">
    <script src="<?= e(asset('assets/app.js')) ?>" defer></script>
</head>
<body class="menu-horizontal">
    <header class="topbar">
        <div class="wrap topbar-in">
            <a class="brand" href="<?= e(url('/')) ?>"><span>Factura<b>Web</b></span></a>
            <?php if ($usuarioActual !== null): ?>
                <input type="checkbox" id="menu-toggle" class="menu-toggle" aria-label="Abrir menú">
                <label for="menu-toggle" class="menu-btn" aria-hidden="true"><span></span><span></span><span></span></label>
                <nav class="menu" aria-label="Menú principal">
                    <?php if ($alDia): ?>
                        <a href="<?= e(url('/')) ?>" class="<?= $navActual === 'inicio' ? 'activo' : '' ?>">Inicio</a>
                        <?php foreach (Menu::grupos() as $grupo => $items): $activo = isset($items[$navActual]) || ($grupo === 'Tablas' && $navActual === 'tablas'); ?>
                            <div class="menu-grupo <?= $activo ? 'activo' : '' ?>" data-grupo="<?= e($grupo) ?>">
                                <button type="button" class="menu-grupo-btn" aria-expanded="false"><?= e($grupo) ?></button>
                                <div class="menu-sub">
                                    <?php foreach ($items as $clave => $o): ?>
                                        <a href="<?= e(url($o['ruta'])) ?>" class="<?= $navActual === $clave ? 'activo' : '' ?>"><?= e($o['texto']) ?></a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <div class="menu-sep"></div>
                    <span class="chip"><?= e(Auth::rol()['nombre'] ?? '') ?></span>
                    <a href="<?= e(url('/mi-clave')) ?>" class="menu-user" title="Cambiar mi contraseña"><?= e($usuarioActual['nombre']) ?></a>
                    <form method="post" action="<?= e(url('/salir')) ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn-ghost btn-sm" type="submit">Salir</button>
                    </form>
                </nav>
            <?php endif; ?>
            <button type="button" class="btn btn-ghost tema-btn" data-tema-cambiar aria-label="<?= $tema === 'claro' ? 'Pasar a fondo oscuro' : 'Pasar a fondo blanco' ?>"
                title="Fondo oscuro o blanco"><span aria-hidden="true" data-tema-icono><?= $tema === 'claro' ? '☾' : '☀' ?></span><span class="tema-txt" data-tema-texto><?= $tema === 'claro' ? 'Dark' : 'Light' ?></span></button>
        </div>
    </header>
    <main class="wrap">
        <?php foreach ($flashes as $f): ?>
            <div class="alerta alerta-<?= e($f['tipo']) ?>" role="status"><?= e($f['texto']) ?></div>
        <?php endforeach; ?>
        <?= $content ?>
    </main>
</body>
</html>
