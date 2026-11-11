<?php
use App\Modules\Clientes\ClientesService as S;

/** @var array<string,string> $f */
/** @var array<int,array<string,mixed>> $filas */
/** @var int $total */
/** @var int $pagina */
/** @var int $porPagina */
/** @var array<string,string> $provincias */
$link = static fn (int $p) => url('/clientes') . '?' . http_build_query(['pagina' => $p] + $f);
$paginas = (int) ceil($total / max(1, $porPagina));
?>
<div class="page-head">
    <h1>Clientes</h1>
    <?php if (puede('clientes.editar')): ?><div class="acciones"><a class="btn" href="<?= e(url('/clientes/nuevo')) ?>">+ Nuevo cliente</a></div><?php endif; ?>
</div>

<form method="get" action="<?= e(url('/clientes')) ?>" class="toolbar filtros">
    <label class="sr-only" for="q">Buscar</label>
    <input id="q" name="q" value="<?= e($f['q'] ?? '') ?>" placeholder="Razón social, fantasía, código, CUIT o localidad" type="search">
    <select name="iva" aria-label="Condición de IVA">
        <option value="">Condición de IVA</option>
        <?php foreach (S::CONDICIONES_IVA as $k => $t): ?><option value="<?= $k ?>" <?= (string) $k === ($f['iva'] ?? '') ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
    </select>
    <select name="provincia" aria-label="Provincia">
        <option value="">Provincia</option>
        <?php foreach ($provincias as $k => $t): ?><option value="<?= e($k) ?>" <?= $k === ($f['provincia'] ?? '') ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
    </select>
    <select name="estado" aria-label="Estado">
        <?php foreach (['activos' => 'Activos', 'inactivos' => 'Inactivos', 'todos' => 'Todos'] as $k => $t): ?>
            <option value="<?= $k ?>" <?= $k === ($f['estado'] ?? 'activos') ? 'selected' : '' ?>><?= $t ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-ghost" type="submit">Buscar</button>
</form>

<?php if ($filas === []): ?>
    <p class="muted vacio">No hay clientes que coincidan con la búsqueda.</p>
<?php else: ?>
    <?= paginador($pagina, $paginas, $total, $porPagina, $link, true) ?>
    <div class="table-wrap">
        <table class="table table-cards">
            <thead><tr>
                <?= th_orden('Código', 'codigo', $f, url('/clientes')) ?>
                <?= th_orden('Razón social', 'razon_social', $f, url('/clientes')) ?>
                <?= th_orden('Condición IVA', 'iva', $f, url('/clientes')) ?>
                <?= th_orden('CUIT', 'cuit', $f, url('/clientes')) ?>
                <?= th_orden('Localidad', 'localidad', $f, url('/clientes')) ?>
                <th>Teléfono</th>
            </tr></thead>
            <tbody>
            <?php foreach ($filas as $c): ?>
                <tr>
                    <td data-label="Código"><a href="<?= e(url('/clientes/' . $c['codigo'])) ?>"><b><?= e($c['codigo']) ?></b></a></td>
                    <td data-label="Razón social"><a href="<?= e(url('/clientes/' . $c['codigo'])) ?>"><?= e($c['razon_social']) ?></a>
                        <?php if ($c['nombre_fantasia']): ?><br><small class="muted"><?= e($c['nombre_fantasia']) ?></small><?php endif; ?>
                        <?php if ((int) $c['activo'] !== 1): ?> <span class="badge badge-off">Inactivo</span><?php endif; ?></td>
                    <td data-label="Condición IVA"><?= e(S::CONDICIONES_IVA[(int) $c['iva']] ?? $c['iva']) ?></td>
                    <td data-label="CUIT" class="nw"><?= e(cuit_texto($c['cuit'])) ?></td>
                    <td data-label="Localidad"><?= e($c['localidad']) ?><?= $c['provincia_nombre'] ? '<br><small class="muted">' . e($c['provincia_nombre']) . '</small>' : '' ?></td>
                    <td data-label="Teléfono"><?= e($c['telefono']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= paginador($pagina, $paginas, $total, $porPagina, $link) ?>
    <?php if ($paginas <= 1): ?><p class="small muted"><?= number_format($total, 0, ',', '.') ?> cliente(s).</p><?php endif; ?>
<?php endif; ?>
