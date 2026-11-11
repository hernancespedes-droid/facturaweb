<?php
use App\Modules\Articulos\ArticulosController as C;
use App\Modules\Articulos\ArticulosService as S;

/** @var array<string,string> $f */
/** @var array<int,array<string,mixed>> $filas */
/** @var int $total */
/** @var int $pagina */
/** @var int $porPagina */
/** @var array<string,string> $rubros */
$link = static fn (int $p) => url('/articulos') . '?' . http_build_query(['pagina' => $p] + $f);
$paginas = (int) ceil($total / max(1, $porPagina));
?>
<div class="page-head">
    <h1>Artículos</h1>
    <?php if (puede('articulos.editar')): ?><div class="acciones"><a class="btn" href="<?= e(url('/articulos/nuevo')) ?>">+ Nuevo artículo</a></div><?php endif; ?>
</div>

<form method="get" action="<?= e(url('/articulos')) ?>" class="toolbar filtros">
    <label class="sr-only" for="q">Buscar</label>
    <input id="q" name="q" value="<?= e($f['q'] ?? '') ?>" placeholder="Código, descripción o código de barras" type="search">
    <select name="rubro" aria-label="Rubro">
        <option value="">Rubro</option>
        <?php foreach ($rubros as $k => $t): ?><option value="<?= e($k) ?>" <?= $k === ($f['rubro'] ?? '') ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
    </select>
    <select name="estado" aria-label="Estado">
        <?php foreach (['AC' => 'Activos', 'BA' => 'De baja', 'todos' => 'Todos'] as $k => $t): ?>
            <option value="<?= $k ?>" <?= $k === ($f['estado'] ?? 'AC') ? 'selected' : '' ?>><?= $t ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-ghost" type="submit">Buscar</button>
</form>

<?php if ($filas === []): ?>
    <p class="muted vacio">No hay artículos que coincidan con la búsqueda.</p>
<?php else: ?>
    <?= paginador($pagina, $paginas, $total, $porPagina, $link, true) ?>
    <div class="table-wrap">
        <table class="table table-cards">
            <thead><tr>
                <?= th_orden('Código', 'codigo', $f, url('/articulos')) ?>
                <?= th_orden('Descripción', 'descripcion', $f, url('/articulos')) ?>
                <?= th_orden('Rubro', 'rubro', $f, url('/articulos')) ?>
                <th>IVA</th>
                <?= th_orden('Costo', 'costo', $f, url('/articulos'), true) ?>
                <?= th_orden('Precio lista 1', 'precio', $f, url('/articulos'), true) ?>
            </tr></thead>
            <tbody>
            <?php foreach ($filas as $a): $mon = $a['moneda'] === 'u$s' ? 'u$s ' : '$ '; ?>
                <tr>
                    <td data-label="Código"><a href="<?= e(C::urlFicha((string) $a['codigo'])) ?>"><b><?= e($a['codigo']) ?></b></a></td>
                    <td data-label="Descripción"><?= e($a['descripcion']) ?><?= $a['estado'] !== 'AC' ? ' <span class="badge badge-off">' . e(S::ESTADOS[$a['estado']] ?? $a['estado']) . '</span>' : '' ?></td>
                    <td data-label="Rubro"><?= e($a['rubro_nombre'] ?? $a['rubro']) ?><?= $a['subrubro'] ? ' / ' . e($a['subrubro']) : '' ?></td>
                    <td data-label="IVA"><?= $a['iva_porcentaje'] !== null ? e(numero($a['iva_porcentaje'])) . ' %' : e($a['alicuota']) ?></td>
                    <td data-label="Costo" class="nw"><?= e($mon . numero($a['costo'], 2)) ?></td>
                    <td data-label="Precio lista 1" class="nw"><?= e($mon . numero($a['precio_de_venta_1'], 2)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= paginador($pagina, $paginas, $total, $porPagina, $link) ?>
    <?php if ($paginas <= 1): ?><p class="small muted"><?= number_format($total, 0, ',', '.') ?> artículo(s).</p><?php endif; ?>
<?php endif; ?>
