<?php
use App\Core\Db;
use App\Core\Menu;
use App\Modules\Auth\Auth;

$pdo = Db::pdo();
$emp = $pdo->query('SELECT empresa, cuit FROM parametros WHERE id = 1')->fetch() ?: ['empresa' => '', 'cuit' => null];
$n = static fn ($v) => number_format((int) $v, 0, ',', '.');
$kpis = [];
if (puede('clientes.ver')) {
    $kpis[] = ['n' => $pdo->query('SELECT COUNT(*) FROM clientes WHERE activo = 1')->fetchColumn(), 'l' => 'clientes activos', 'url' => '/clientes'];
}
if (puede('articulos.ver')) {
    $kpis[] = ['n' => $pdo->query("SELECT COUNT(*) FROM articulos WHERE estado = 'AC'")->fetchColumn(), 'l' => 'artículos activos', 'url' => '/articulos'];
}
?>
<section class="hero">
    <p class="eyebrow"><?= e($emp['empresa'] !== '' ? $emp['empresa'] : 'Facturador web') ?><?= $emp['cuit'] ? ' · CUIT ' . e(cuit_texto($emp['cuit'])) : '' ?></p>
    <h1>Facturación</h1>
    <p class="muted">Hola, <?= e(Auth::usuario()['nombre'] ?? '') ?>. Estás trabajando como <b><?= e(Auth::rol()['nombre'] ?? '') ?></b>.</p>
</section>

<?php if ($kpis !== []): ?>
    <div class="kpis">
        <?php foreach ($kpis as $k): ?>
            <a class="kpi kpi-link" href="<?= e(url($k['url'])) ?>"><span class="kpi-n"><?= $n($k['n']) ?></span><span class="kpi-l"><?= e($k['l']) ?></span></a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php foreach (Menu::grupos() as $grupo => $items): ?>
    <h2><?= e($grupo) ?></h2>
    <div class="tiles">
        <?php foreach ($items as $o): ?>
            <a class="tile" href="<?= e(url($o['ruta'])) ?>"><span class="tile-title"><?= e($o['texto']) ?></span></a>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>
