<?php
/** @var string $host */
/** @var array<int,array<string,mixed>> $usuarios */
/** @var ?string $error */
/** @var ?array<string,mixed> $hecho contraseña recién generada (se muestra una sola vez) */
/** @var array<int,array<string,mixed>> $historial */
/** @var array<int,array{id:int,asunto:string}> $tickets */
/** @var int $ticket */
/** @var array<string,mixed> $info */
$fecha = static fn (?string $f): string => $f ? date('d/m/Y H:i', strtotime($f)) : '';
?>
<p><a href="<?= e(url('/instancias/editar?host=' . rawurlencode($host))) ?>">← Administrar <?= e($host) ?></a></p>
<div class="page-head">
    <div>
        <p class="eyebrow"><?= e((string) ($info['nombre'] ?? '')) ?></p>
        <h1>Usuarios de <?= e($host) ?></h1>
    </div>
</div>

<?php if ($hecho !== null): ?>
    <section class="card clave-temporal" role="status">
        <h2>Contraseña temporal de <?= e($hecho['usuario']) ?></h2>
        <p class="clave-temporal-valor"><code data-clave><?= e($hecho['clave']) ?></code>
            <button type="button" class="btn btn-ghost" data-copiar-clave>Copiar</button></p>
        <p class="small">Pasásela a <?= e($hecho['nombre']) ?> por un medio seguro (teléfono o WhatsApp, no en el ticket). Al entrar en
            <b><?= e($host) ?></b> con su usuario <b><?= e($hecho['usuario']) ?></b> se le pide que elija una nueva.
            <?= $hecho['reactivado'] ? 'El usuario estaba inactivo y quedó activo. ' : '' ?>
            <?= $hecho['ticket'] ? 'Quedó anotado como nota interna del <a href="' . e(url('/soporte/bandeja/' . (int) $hecho['ticket'])) . '">ticket #' . (int) $hecho['ticket'] . '</a>. ' : '' ?>
            <b>No se vuelve a mostrar.</b></p>
    </section>
<?php endif; ?>

<?php if ($error !== null): ?>
    <div class="alerta alerta-error" role="alert"><?= e($error) ?></div>
<?php else: ?>
<form method="post" action="<?= e(url('/instancias/usuarios/resetear')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="host" value="<?= e($host) ?>">
    <section class="card">
        <p class="small">«Resetear» le genera una contraseña temporal, lo desbloquea si se había trabado por intentos y cierra el «recordarme» de sus equipos.
            La contraseña se muestra una sola vez y no se guarda; queda registrado quién la reseteó.</p>
        <div class="grid-2">
            <label class="field"><span>Ticket de soporte (opcional)</span>
                <select name="ticket">
                    <option value="">Sin ticket</option>
                    <?php foreach ($tickets as $t): ?>
                        <option value="<?= (int) $t['id'] ?>" <?= (int) $t['id'] === $ticket ? 'selected' : '' ?>>#<?= (int) $t['id'] ?> · <?= e($t['asunto']) ?></option>
                    <?php endforeach; ?>
                    <?php if ($ticket > 0 && !in_array($ticket, array_map('intval', array_column($tickets, 'id')), true)): ?>
                        <option value="<?= $ticket ?>" selected>#<?= $ticket ?></option>
                    <?php endif; ?>
                </select>
                <small class="muted">Se anota como nota interna del ticket (sin la contraseña).</small></label>
            <label class="field"><span>Motivo (opcional)</span><input name="motivo" maxlength="200" placeholder="Perdió la contraseña, lo llamé al…"></label>
        </div>
    </section>

    <div class="table-wrap">
        <table class="table table-cards">
            <thead>
            <tr><th>Usuario</th><th>Nombre</th><th>Rol</th><th>Estado</th><th>Último ingreso</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($usuarios as $u): $id = (int) $u['id']; ?>
                <tr>
                    <td data-label="Usuario"><b><?= e($u['usuario']) ?></b></td>
                    <td data-label="Nombre"><?= e($u['nombre']) ?><?php if ($u['email']): ?><br><small class="muted"><?= e($u['email']) ?></small><?php endif; ?></td>
                    <td data-label="Rol"><?= e($u['rol_nombre']) ?></td>
                    <td data-label="Estado">
                        <?php if ((int) $u['activo'] !== 1): ?><span class="badge badge-off">Inactivo</span>
                        <?php elseif ((int) $u['bloqueado'] === 1): ?><span class="badge badge-warn">Bloqueado</span>
                        <?php else: ?><span class="badge badge-ok">Activo</span><?php endif; ?>
                        <?php if ((int) $u['debe_cambiar_clave'] === 1): ?><span class="badge">Contraseña temporal</span><?php endif; ?>
                    </td>
                    <td data-label="Último ingreso"><?= $u['ultimo_login'] ? e($fecha((string) $u['ultimo_login'])) : '<span class="muted">Nunca</span>' ?></td>
                    <td data-label="">
                        <?php if ((int) $u['activo'] !== 1): ?>
                            <label class="check small"><input type="checkbox" name="reactivar[<?= $id ?>]" value="1"> Reactivarlo</label>
                        <?php endif; ?>
                        <button class="btn btn-ghost" type="submit" name="usuario_id" value="<?= $id ?>"
                                data-confirmar-boton="¿Resetear la contraseña de <?= e($u['usuario']) ?>? La actual deja de funcionar.">Resetear contraseña</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</form>
<?php endif; ?>

<section class="card">
    <h2>Registro de contraseñas reseteadas</h2>
    <?php if ($historial === []): ?>
        <p class="muted small">Todavía no se reseteó ninguna desde acá.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table-cards">
                <thead><tr><th>Fecha</th><th>Usuario</th><th>Lo hizo</th><th>Ticket</th><th>Motivo</th><th>IP</th></tr></thead>
                <tbody>
                <?php foreach ($historial as $h): ?>
                    <tr>
                        <td data-label="Fecha"><?= e($fecha((string) $h['creado_en'])) ?></td>
                        <td data-label="Usuario"><b><?= e($h['usuario']) ?></b> <?= e($h['usuario_nombre']) ?><?= (int) $h['reactivado'] === 1 ? ' <span class="badge">reactivado</span>' : '' ?></td>
                        <td data-label="Lo hizo"><?= e($h['por']) ?></td>
                        <td data-label="Ticket"><?= $h['ticket_id'] ? '<a href="' . e(url('/soporte/bandeja/' . (int) $h['ticket_id'])) . '">#' . (int) $h['ticket_id'] . '</a>' : '' ?></td>
                        <td data-label="Motivo"><?= e($h['motivo']) ?></td>
                        <td data-label="IP"><small class="muted"><?= e($h['ip']) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<script>
document.addEventListener('click', function (ev) {
  var b = ev.target.closest('[data-copiar-clave]');
  if (!b) return;
  var c = document.querySelector('[data-clave]');
  if (c && navigator.clipboard) navigator.clipboard.writeText(c.textContent).then(function () { b.textContent = 'Copiada'; });
});
</script>
