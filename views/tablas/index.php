<?php
use App\Core\Form;

/** @var array<string,mixed> $def */
/** @var array<int,array<string,mixed>> $filas */
/** @var string $q */
/** @var array<string,array<string,string>> $refs */
$ruta = '/tablas/' . $def['tabla'];
?>
<p><a href="<?= e(url('/tablas')) ?>">← Tablas</a></p>
<div class="page-head">
    <div>
        <h1><?= e($def['titulo']) ?></h1>
        <?php if ($def['ayuda'] !== ''): ?><p class="muted small"><?= e($def['ayuda']) ?></p><?php endif; ?>
    </div>
    <div class="acciones"><a class="btn" href="<?= e(url($ruta . '/nuevo')) ?>">+ Agregar</a></div>
</div>

<form method="get" action="<?= e(url($ruta)) ?>" class="toolbar">
    <label class="sr-only" for="q">Buscar</label>
    <input id="q" name="q" value="<?= e($q) ?>" placeholder="Buscar" type="search">
    <button class="btn btn-ghost" type="submit">Buscar</button>
</form>

<?php if ($filas === []): ?>
    <p class="muted vacio">No hay nada cargado<?= $q !== '' ? ' que coincida con la búsqueda' : '' ?>.</p>
<?php else: ?>
    <div class="table-wrap">
        <table class="table table-cards">
            <thead><tr>
                <?php foreach ($def['lista'] as $col): ?><th><?= e($def['campos'][$col]['label']) ?></th><?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($filas as $f): ?>
                <tr>
                    <?php foreach ($def['lista'] as $i => $col): $txt = Form::mostrar($def['campos'][$col], $f[$col], $refs[$col] ?? []); ?>
                        <td data-label="<?= e($def['campos'][$col]['label']) ?>"><?php if ($i === 0): ?><a href="<?= e(url($ruta . '/' . (int) $f['id'])) ?>"><b><?= e($txt) ?></b></a><?php else: ?><?= e($txt) ?><?php endif; ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="small muted"><?= count($filas) ?> registro(s).</p>
<?php endif; ?>
