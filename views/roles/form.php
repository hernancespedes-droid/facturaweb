<?php
use App\Modules\Roles\RolesService as R;

/** @var array<string,mixed>|null $rol */
/** @var array<string,mixed> $datos nombre, es_admin, es_master, permisos[codigo => true] */
/** @var array<string,string> $errores */
/** @var array<string,array<string,mixed>> $permisos */
/** @var string[] $otros */
/** @var R $svc */
$err = static fn (string $k): string => isset($errores[$k]) ? '<small class="field-error">' . e($errores[$k]) . '</small>' : '';
$tiene = static fn (string $c): bool => isset($datos['permisos'][$c]);
$todo = (int) $datos['es_admin'] === 1 || (int) $datos['es_master'] === 1;
$check = static function (string $codigo, string $texto, bool $principal = false) use ($permisos, $tiene, $todo): string {
    if (!isset($permisos[$codigo])) {
        return '';
    }
    return '<label class="' . ($principal ? 'permiso-menu' : 'permiso-accion') . '"><input type="checkbox" name="permisos[]" value="' . e($codigo) . '" '
        . ($tiene($codigo) || $todo ? 'checked' : '') . ' data-permiso' . ($principal ? ' data-principal' : '') . '> ' . $texto . '</label>';
};
?>
<p><a href="<?= e(url('/roles')) ?>">← Roles</a></p>
<div class="page-head"><h1><?= $rol === null ? 'Nuevo rol' : e($rol['nombre']) ?></h1></div>
<?php if (isset($errores['general'])): ?><div class="alerta alerta-error"><?= e($errores['general']) ?></div><?php endif; ?>

<form method="post" action="<?= e(url($rol === null ? '/roles' : '/roles/' . (int) $rol['id'])) ?>" class="card form roles-form">
    <?= csrf_field() ?>
    <label class="field"><span>Nombre del rol</span>
        <input name="nombre" value="<?= e($datos['nombre']) ?>" required maxlength="60"><?= $err('nombre') ?></label>

    <div class="checks">
        <label><input type="checkbox" name="es_admin" value="1" data-todo <?= (int) $datos['es_admin'] === 1 ? 'checked' : '' ?>>
            <b>Es administrador</b> <span class="small muted">— tiene todo lo del menú y todas las acciones (salvo aplicar cambios de base en Sistema, que es del Master).</span></label>
        <?php if ($svc->actorEsMaster()): ?>
            <label><input type="checkbox" name="es_master" value="1" data-todo <?= (int) $datos['es_master'] === 1 ? 'checked' : '' ?>>
                <b>Es master</b> <span class="small muted">— todo, incluso aplicar cambios de base. Solo un Master ve y asigna estos roles.</span></label>
        <?php endif; ?>
    </div>
    <?= $err('es_master') ?>

    <h2>Opciones del menú y acciones</h2>
    <p class="small muted">Tildá cada opción del menú que puede usar y, debajo, lo que puede hacer en ella. Se controla en el servidor, no solo en pantalla.</p>
    <?= $err('permisos') ?>
    <div class="permisos-grid" data-permisos <?= $todo ? 'data-bloqueado' : '' ?>>
        <?php foreach (R::MENU as $clave => [$principal, $acciones]): ?>
            <fieldset class="permiso-grupo">
                <legend><?= $check($principal, '<b>' . e(\App\Core\Menu::texto($clave)) . '</b>' . (isset(R::INCLUYE[$clave]) ? ' <span class="small muted">(y ' . e(R::INCLUYE[$clave]) . ')</span>' : ''), true) ?></legend>
                <?php foreach ($acciones as $a): ?><?= $check($a, e($permisos[$a]['descripcion'] ?? $a)) ?><?php endforeach; ?>
            </fieldset>
        <?php endforeach; ?>
        <?php if ($otros !== []): ?>
            <fieldset class="permiso-grupo">
                <legend><b>Otros</b></legend>
                <?php foreach ($otros as $o): ?><?= $check($o, e($permisos[$o]['descripcion'])) ?><?php endforeach; ?>
            </fieldset>
        <?php endif; ?>
    </div>
    <div class="actions"><button class="btn" type="submit"><?= $rol === null ? 'Crear rol' : 'Guardar cambios' ?></button></div>
</form>
