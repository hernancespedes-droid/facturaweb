<?php
use App\Modules\Caja\CajaService;

/** @var ?array<string,mixed> $turno */
/** @var ?array<string,mixed> $resumen */
/** @var float $fondoSugerido */
/** @var array<int,array<string,mixed>> $filas */
/** @var bool $puedeMover */
/** @var float $tope */
/** @var ?string $error */
/** @var array<string,string> $f */
$u = url('/caja');
$p = static fn ($v) => '$ ' . number_format((float) $v, 2, ',', '.');
$m = static fn ($v) => number_format((float) $v, 2, ',', '.');
$hora = static fn (?string $t) => $t === null ? '—' : date('d/m/Y H:i', strtotime($t));
?>
<div class="page-head"><div><p class="eyebrow">Caja</p><h1>Mi caja</h1></div></div>
<?php if ($error): ?><div class="alerta alerta-error" role="alert"><?= e($error) ?></div><?php endif; ?>

<?php if ($turno === null): ?>
    <section class="card">
        <h2>Abrir mi turno</h2>
        <p class="muted small">Sin turno abierto, lo que cobres no queda en ningún arqueo y no podés mover dinero. Contá el efectivo que hay en el cajón y anotalo como fondo inicial.</p>
        <form method="post" action="<?= e(url('/caja/abrir')) ?>" class="toolbar"><?= csrf_field() ?>
            <label class="field"><span>Fondo inicial en efectivo ($)</span><input name="fondo" inputmode="decimal" data-decimales="2" value="<?= e($m($fondoSugerido)) ?>" required></label>
            <button class="btn" type="submit">Abrir turno</button>
        </form>
        <?php if ($fondoSugerido > 0): ?><p class="muted small">Se sugiere lo que dejó contado el cajero anterior (<?= e($p($fondoSugerido)) ?>).</p><?php endif; ?>
    </section>
<?php else: ?>
    <section class="card resumen-fila" aria-label="Mi turno">
        <div><span class="muted small">Turno abierto desde</span><b><?= e($hora($turno['abierto_en'])) ?></b></div>
        <div><span class="muted small">Fondo inicial</span><b><?= e($p($resumen['fondo'])) ?></b></div>
        <div><span class="muted small">Efectivo cobrado (ventas y cobranzas)</span><b><?= e($p($resumen['efectivo'])) ?></b></div>
        <div><span class="muted small">Ingresos de dinero (no son ventas)</span><b><?= e($p($resumen['ingresos'])) ?></b></div>
        <div><span class="muted small">Egresos de dinero (no son ventas)</span><b>− <?= e($p($resumen['egresos'])) ?></b></div>
        <div><span class="muted small">Efectivo que debería haber</span><b data-esperado><?= e($p($resumen['esperado'])) ?></b></div>
    </section>
    <?php if ($resumen['otras'] !== []): ?>
        <p class="small muted">Cobrado en el turno por otras formas (no entran al arqueo de efectivo):
            <?= e(implode(' · ', array_map(static fn ($o) => $o['descripcion'] . ' ' . '$ ' . number_format((float) $o['total'], 2, ',', '.'), $resumen['otras']))) ?></p>
    <?php endif; ?>

    <?php if ($puedeMover): ?>
        <section class="card" id="mover">
            <h2>Ingresar o sacar dinero de la caja</h2>
            <form method="post" action="<?= e(url('/caja/mover')) ?>" class="toolbar" data-mover><?= csrf_field() ?>
                <label class="field"><span>Qué</span><select name="tipo"><option value="I">Ingreso</option><option value="E">Egreso</option></select></label>
                <label class="field"><span>Importe ($)</span><input name="importe" inputmode="decimal" data-decimales="2" required></label>
                <label class="field" style="flex:1 1 18rem"><span>Motivo</span><input name="motivo" maxlength="200" required placeholder="Cambio para el cajón, pago a proveedor, retiro…"></label>
                <button class="btn" type="submit">Registrar</button>
            </form>
            <p class="muted small">Queda registrado con tu usuario y la hora. No se puede editar ni borrar: si te equivocás, se anula con un contra-asiento.<?= $tope > 0 ? ' Tope por movimiento: ' . e($p($tope)) . '.' : '' ?></p>
        </section>
    <?php endif; ?>

    <section class="card" id="cerrar">
        <h2>Cerrar mi turno (arqueo)</h2>
        <p class="muted small">Contá el efectivo del cajón y escribilo: el sistema lo compara con lo que debería haber (<b><?= e($p($resumen['esperado'])) ?></b>). Si hay diferencia, dejá una observación. El cajero que sigue abre su turno con lo que contaste.</p>
        <form method="post" action="<?= e(url('/caja/cerrar')) ?>" class="toolbar" data-cerrar><?= csrf_field() ?>
            <input type="hidden" name="turno" value="<?= (int) $turno['id'] ?>">
            <label class="field"><span>Efectivo contado ($)</span><input name="contado" inputmode="decimal" data-decimales="2" required></label>
            <label class="field" style="flex:1 1 18rem"><span>Observaciones</span><input name="observaciones" maxlength="255" placeholder="Obligatoria si hay diferencia"></label>
            <button class="btn" type="submit">Cerrar turno</button>
        </form>
    </section>

    <h2>Movimientos de mi turno</h2>
    <?php if ($filas === []): ?>
        <p class="muted vacio">Todavía no hay movimientos en este turno.</p>
    <?php else: ?>
        <?= \App\Core\View::render('caja/_movimientos', ['filas' => $filas, 'f' => $f, 'u' => $u, 'anular' => $puedeMover, 'segundos' => false], null) ?>
    <?php endif; ?>
<?php endif; ?>
<script>
document.querySelectorAll('form[data-anular]').forEach(function (fm) {
  fm.addEventListener('submit', function (ev) {
    var motivo = window.prompt('Motivo de la anulación (queda registrado):');
    if (!motivo || !motivo.trim()) { ev.preventDefault(); return; }
    fm.querySelector('[name=motivo]').value = motivo.trim();
  });
});
</script>
