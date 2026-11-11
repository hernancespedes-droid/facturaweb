<?php
namespace OysAfip;

/**
 * Pantalla del ABM de equivalencias de IVA, sin depender de ninguna web en particular.
 * El sistema que la usa pone el encabezado, el menú, el control de acceso y el CSRF:
 *
 *   $abm = new EquivalenciasIva($pdo);                       // o con callback de grabación (cola)
 *   if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
 *       list($errores, $mensaje) = EquivalenciasIvaVista::procesar($abm, $_POST);
 *       if (!$errores) { redirigir con $mensaje }
 *   }
 *   echo EquivalenciasIvaVista::html($abm, array('csrf' => csrf_campo(), 'url' => '?p=equivalencias', ...));
 *
 * Campos del formulario: eq_accion (alta | modificacion | baja | sugeridas), codigooys, codigoafip.
 */
class EquivalenciasIvaVista
{
    /** Procesa el POST. Devuelve array(errores, mensaje). */
    public static function procesar(EquivalenciasIva $abm, array $post)
    {
        $acc = isset($post['eq_accion']) ? (string) $post['eq_accion'] : '';
        $oys = isset($post['codigooys']) ? trim((string) $post['codigooys']) : '';
        $arca = isset($post['codigoafip']) ? trim((string) $post['codigoafip']) : '';
        try {
            if ($acc === 'sugeridas') {
                $abm->asegurarTabla();
                $h = $abm->cargarSugeridas(isset($post['solo']) ? (array) $post['solo'] : array());
                return array(array(), $h ? 'Se cargaron las equivalencias de los códigos ' . implode(', ', $h) . '.' : 'No había equivalencias para agregar.');
            }
            if (!in_array($acc, array('alta', 'modificacion', 'baja'), true)) {
                return array(array('Acción desconocida.'), '');
            }
            $abm->asegurarTabla();
            $err = $abm->guardar($acc, $oys, $arca);
            if ($err) {
                return array($err, '');
            }
            $txt = $acc === 'baja' ? 'Se quitó la equivalencia del código ' . $oys . '.'
                : 'Código ' . $oys . ' = ' . EquivalenciasIva::textoArca($arca) . ' (' . (int) $arca . ').';
            return array(array(), $txt);
        } catch (\RuntimeException $e) {
            return array(array($e->getMessage()), '');
        }
    }

    private static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param array $o
     *   url             adonde se envía el formulario y base de los enlaces (p. ej. '?p=equivalencias')
     *   csrf            HTML del campo oculto de CSRF
     *   puede_editar    bool (default true)
     *   editar          código del sistema que se está modificando ('' = ninguno)
     *   nuevo           bool: mostrar el formulario de alta
     *   codigos_sistema [código => descripción] de los códigos de IVA del sistema (default: los de O&S)
     *   en_uso          códigos que aparecen en los datos (clientes, comprobantes), para avisar los que faltan
     *   pendientes      [código => texto] cambios cargados que todavía no se aplicaron (cola)
     *   nota            texto debajo del formulario
     *   clases          clases CSS: tabla, btn, btn2, chico, panel, grilla, aviso, error, nota
     */
    public static function html(EquivalenciasIva $abm, array $o = array())
    {
        $c = (isset($o['clases']) ? $o['clases'] : array()) + array('tabla' => 'tabla', 'btn' => 'btn', 'btn2' => 'btn secundario',
            'chico' => 'btn chico', 'panel' => 'panel', 'grilla' => 'grilla-form', 'aviso' => 'flash', 'error' => 'error', 'nota' => 'nota');
        $url = isset($o['url']) ? $o['url'] : '?';
        $sep = strpos($url, '?') === false ? '?' : '&amp;';
        $csrf = isset($o['csrf']) ? $o['csrf'] : '';
        $puede = !isset($o['puede_editar']) || $o['puede_editar'];
        $sistema = isset($o['codigos_sistema']) ? $o['codigos_sistema'] : EquivalenciasIva::$codigosOys;
        $pend = isset($o['pendientes']) ? $o['pendientes'] : array();
        $editar = isset($o['editar']) ? trim((string) $o['editar']) : '';
        $filas = $abm->listar();
        $h = function ($s) { return EquivalenciasIvaVista::h($s); };
        $txtSis = function ($cod) use ($sistema) { return isset($sistema[(int) $cod]) ? $sistema[(int) $cod] : '—'; };
        $out = '';

        // Aviso: códigos usados en los datos que no tienen equivalencia
        $faltan = $abm->faltantes(isset($o['en_uso']) ? $o['en_uso'] : array());
        $faltan = array_values(array_filter($faltan, function ($x) use ($pend) { return !isset($pend[$x]); }));
        if ($faltan) {
            $sug = array_values(array_filter($faltan, function ($x) { return isset(EquivalenciasIva::$sugeridas[(int) $x]); }));
            $out .= '<div class="' . $c['error'] . '">Hay códigos de IVA en uso sin equivalencia: <b>' . $h(implode(', ', array_map(function ($x) use ($txtSis) {
                    return $x . ' (' . $txtSis($x) . ')'; }, $faltan))) . '</b>. Las facturas de esos clientes no se van a poder autorizar.';
            if ($puede && $sug) {
                $out .= ' <form method="post" action="' . $h($url) . '" style="display:inline">' . $csrf . '<input type="hidden" name="eq_accion" value="sugeridas">';
                foreach ($sug as $x) {
                    $out .= '<input type="hidden" name="solo[]" value="' . $h($x) . '">';
                }
                $out .= '<button class="' . $c['chico'] . '">Cargar las sugeridas (' . $h(implode(', ', array_map(function ($x) {
                        return $x . ' → ' . EquivalenciasIva::$sugeridas[(int) $x]; }, $sug))) . ')</button></form>';
            }
            $out .= '</div>';
        }
        foreach ($pend as $cod => $txt) {
            $out .= '<p class="' . $c['aviso'] . '">Código ' . $h($cod) . ': ' . $h($txt) . '</p>';
        }

        // Formulario de alta o modificación
        $actual = $editar !== '' ? $abm->buscar($editar) : null;
        if ($puede && (!empty($o['nuevo']) || $actual)) {
            $alta = !$actual;
            $usados = array();
            foreach ($filas as $r) {
                $usados[$r['codigooys']] = true;
            }
            $out .= '<form method="post" action="' . $h($url) . '" class="' . $c['panel'] . '">' . $csrf
                . '<input type="hidden" name="eq_accion" value="' . ($alta ? 'alta' : 'modificacion') . '">'
                . '<h2>' . ($alta ? 'Nueva equivalencia' : 'Modificar la equivalencia del código ' . $h($editar)) . '</h2><div class="' . $c['grilla'] . '">';
            if ($alta) {
                $out .= '<label>Código de IVA del sistema<select name="codigooys" required><option value="">— elegir —</option>';
                foreach ($sistema as $k => $t) {
                    if (!isset($usados[(string) $k])) {
                        $out .= '<option value="' . $h($k) . '">' . $h($k . ' · ' . $t) . '</option>';
                    }
                }
                $out .= '</select></label>';
            } else {
                $out .= '<label>Código de IVA del sistema<input value="' . $h($editar . ' · ' . $txtSis($editar)) . '" disabled>'
                    . '<input type="hidden" name="codigooys" value="' . $h($editar) . '"></label>';
            }
            $sel = $actual ? $actual['codigoafip'] : '';
            if ($alta && isset($o['codigooys_sugerido']) && isset(EquivalenciasIva::$sugeridas[(int) $o['codigooys_sugerido']])) {
                $sel = EquivalenciasIva::$sugeridas[(int) $o['codigooys_sugerido']];
            }
            $out .= '<label>Condición de IVA en ARCA<select name="codigoafip" required><option value="">— elegir —</option>';
            foreach (EquivalenciasIva::$condicionesArca as $k => $t) {
                $out .= '<option value="' . (int) $k . '"' . ((string) $sel === (string) $k ? ' selected' : '') . '>' . $h($k . ' · ' . $t) . '</option>';
            }
            $out .= '</select></label></div><div class="botones"><button class="' . $c['btn'] . '">Grabar</button> <a class="' . $c['btn2'] . '" href="' . $h($url) . '">Cancelar</a></div>';
            if (!empty($o['nota'])) {
                $out .= '<p class="' . $c['nota'] . '" style="text-align:left">' . $h($o['nota']) . '</p>';
            }
            $out .= '</form>';
        } elseif ($puede) {
            $out .= '<p><a class="' . $c['btn'] . '" href="' . $h($url) . $sep . 'nuevo=1">+ Nueva equivalencia</a></p>';
        }

        // Listado
        $out .= '<div class="' . $c['tabla'] . '"><table><thead><tr><th>Código del sistema</th><th>En el sistema</th><th>Condición en ARCA</th>'
            . ($puede ? '<th></th>' : '') . '</tr></thead><tbody>';
        foreach ($filas as $r) {
            $out .= '<tr><td>' . $h($r['codigooys']) . '</td><td>' . $h($txtSis($r['codigooys'])) . '</td><td>' . $h($r['codigoafip'] . ' · ' . EquivalenciasIva::textoArca($r['codigoafip'])) . '</td>';
            if ($puede) {
                $out .= '<td style="white-space:nowrap">';
                if (!isset($pend[$r['codigooys']])) {
                    $out .= '<a class="' . $c['chico'] . '" href="' . $h($url) . $sep . 'editar=' . $h(urlencode($r['codigooys'])) . '">Modificar</a> '
                        . '<form method="post" action="' . $h($url) . '" style="display:inline" onsubmit="return confirm(\'¿Quitar la equivalencia del código '
                        . $h($r['codigooys']) . '? Las facturas de esos clientes no se van a poder autorizar.\')">' . $csrf
                        . '<input type="hidden" name="eq_accion" value="baja"><input type="hidden" name="codigooys" value="' . $h($r['codigooys']) . '">'
                        . '<button class="' . $c['chico'] . '">Quitar</button></form>';
                } else {
                    $out .= '<span>pendiente</span>';
                }
                $out .= '</td>';
            }
            $out .= '</tr>';
        }
        if (!$filas) {
            $out .= '<tr><td colspan="' . ($puede ? 4 : 3) . '">Todavía no hay equivalencias cargadas.</td></tr>';
        }
        return $out . '</tbody></table></div>';
    }
}
