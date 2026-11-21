<?php /** @var array<string,string> $destinos @var array $historial @var string $maximo */
use App\Modules\Importacion\Destinos;
?>
<div class="page-head"><div><p class="eyebrow">Sistema</p><h1>Importar datos</h1></div></div>
<p class="small muted">Para cargar de una vez los datos de un sistema anterior o de una planilla: clientes, artículos y precios, stock y comprobantes ya emitidos.
    Subís el archivo, elegís qué columna va a cada dato (se sugiere solo por los títulos) y primero lo <b>probás</b>: se revisa todo sin grabar y se muestran los errores por fila.
    Se puede guardar la elección de columnas como <b>modelo</b> para la próxima vez.</p>

<?php if ($destinos === []): ?>
<div class="alerta alerta-error" role="alert">Tu rol no tiene permiso para importar nada (hace falta además editar clientes, artículos o mover stock).</div>
<?php else: ?>
<form method="post" action="<?= e(url('/importar/subir')) ?>" enctype="multipart/form-data" class="card form-grid" id="form-importar"><?= csrf_field() ?>
    <fieldset class="field field-ancho"><legend>¿Qué querés importar? *</legend>
        <?php $primero = true; foreach ($destinos as $d => $t): ?>
        <div class="field"><label class="check"><input type="radio" name="destino" value="<?= e($d) ?>" <?= $primero ? 'checked' : '' ?> required> <b><?= e($t) ?></b>
            <span class="small muted">— <?= e(Destinos::AYUDA[$d]) ?> <a href="<?= e(url('/importar/plantilla?destino=' . $d)) ?>">Bajar planilla modelo</a></span></label></div>
        <?php $primero = false; endforeach; ?>
    </fieldset>
    <label class="field"><span>Archivo * (.xlsx, .csv o .txt; hasta <?= e($maximo) ?>)</span>
        <input type="file" name="archivo" accept=".xlsx,.csv,.txt,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></label>
    <div class="field"><label class="check"><input type="checkbox" name="titulos" value="1" checked> La primera fila tiene los títulos de las columnas</label></div>
    <p class="small muted field-ancho">Del Excel se lee la primera hoja. El Excel viejo (.xls) hay que guardarlo antes como .xlsx o CSV. En los CSV/TXT el separador
        (punto y coma, coma, tabulador o barra) y los acentos se reconocen solos. Los importes pueden venir como 1.234,56 o 1234.56.</p>
    <div class="acciones"><button class="btn" type="submit">Subir y elegir columnas</button></div>
</form>
<?php endif; ?>

<section class="card">
    <h2>Importaciones grabadas</h2>
    <?php if ($historial === []): ?><p class="muted">Todavía no se importó nada.</p><?php else: ?>
    <div class="table-wrap"><table class="table table-cards">
        <thead><tr><th>Fecha</th><th>Qué</th><th>Archivo</th><th>Usuario</th><th class="num">Filas</th><th class="num">Nuevas</th><th class="num">Actualizadas</th><th class="num">Salteadas</th><th class="num">Con error</th></tr></thead>
        <tbody><?php foreach ($historial as $h): ?>
            <tr><td data-label="Fecha"><?= e(date('d/m/Y H:i', (int) strtotime((string) $h['creado_en']))) ?></td>
                <td data-label="Qué"><?= e(Destinos::TITULOS[$h['destino']] ?? $h['destino']) ?></td>
                <td data-label="Archivo"><?= e($h['archivo']) ?></td><td data-label="Usuario"><?= e((string) $h['usuario']) ?></td>
                <td data-label="Filas" class="num"><?= (int) $h['filas'] ?></td><td data-label="Nuevas" class="num"><?= (int) $h['nuevas'] ?></td>
                <td data-label="Actualizadas" class="num"><?= (int) $h['actualizadas'] ?></td><td data-label="Salteadas" class="num"><?= (int) $h['salteadas'] ?></td>
                <td data-label="Con error" class="num"><?= (int) $h['con_error'] ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>
