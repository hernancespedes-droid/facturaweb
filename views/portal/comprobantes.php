<?php /** @var string $mail @var array $clientes @var array $cliente @var int[] $anios @var ?int $anio @var array $filas @var float $saldo */
use App\Modules\Comprobantes\Tipos;
?>
<div class="page-head">
    <div><p class="eyebrow"><?= e($mail) ?></p><h1><?= e($cliente['razon_social']) ?></h1>
        <p class="small muted">CUIT <?= e(cuit_texto($cliente['cuit']) ?: '—') ?></p></div>
    <form method="post" action="<?= e(url('/portal/salir')) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Salir</button></form>
</div>

<section class="portal-resumen">
    <div class="card"><span class="muted small">Saldo de tu cuenta</span><b class="portal-saldo"><?= e(plata_dec($saldo)) ?></b>
        <span class="small muted"><?= $saldo > 0.009 ? 'a pagar' : ($saldo < -0.009 ? 'a tu favor' : 'al día') ?></span></div>
</section>

<form method="get" action="<?= e(url('/portal/comprobantes')) ?>" class="toolbar filtros">
    <?php if (count($clientes) > 1): ?>
        <select name="cliente" aria-label="Cliente" onchange="this.form.submit()"><?php foreach ($clientes as $c): ?>
            <option value="<?= (int) $c['codigo'] ?>" <?= (int) $c['codigo'] === (int) $cliente['codigo'] ? 'selected' : '' ?>><?= e($c['razon_social']) ?></option><?php endforeach; ?></select>
    <?php else: ?><input type="hidden" name="cliente" value="<?= (int) $cliente['codigo'] ?>"><?php endif; ?>
    <?php if ($anios !== []): ?>
        <select name="anio" aria-label="Año" onchange="this.form.submit()"><?php foreach ($anios as $a): ?><option value="<?= $a ?>" <?= $a === $anio ? 'selected' : '' ?>><?= $a ?></option><?php endforeach; ?></select>
    <?php endif; ?>
    <noscript><button class="btn btn-ghost" type="submit">Ver</button></noscript>
</form>

<?php if ($filas === []): ?>
    <p class="muted vacio">No hay comprobantes para mostrar.</p>
<?php else: ?>
    <ul class="portal-lista">
    <?php foreach ($filas as $f): $pdf = url('/portal/comprobantes/' . (int) $f['fe_clave'] . '/pdf'); ?>
        <li class="card">
            <div><b><?= e(Tipos::titulo($f['tipo'], $f['letra'])) ?> <?= e(sprintf('%04d-%08d', $f['prefijo'], $f['numero'])) ?></b>
                <span class="small muted"><?= e(fecha($f['fecha'])) ?></span></div>
            <div class="portal-importe <?= $f['tipo'] === 'NC' ? 'texto-ok' : '' ?>"><?= $f['tipo'] === 'NC' ? '−' : '' ?><?= e(plata_dec($f['neto'])) ?></div>
            <div class="acciones"><a class="btn btn-ghost btn-sm" href="<?= e($pdf . '?ver=1') ?>" target="_blank" rel="noopener">Ver</a>
                <a class="btn btn-sm" href="<?= e($pdf) ?>" download>Descargar PDF</a></div>
        </li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>
