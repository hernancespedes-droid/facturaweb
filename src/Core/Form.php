<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Campos de formulario a partir de una declaración ['label', 'tipo', 'largo', 'requerido', 'opciones'...]
 * (la misma que usan las validaciones de Tablas, Clientes y Artículos). Todo texto pasa por e().
 */
final class Form
{
    /**
     * @param array<string,mixed> $c declaración del campo
     * @param array<string,string> $opciones para 'ref' (código => descripción)
     */
    public static function campo(string $nombre, array $c, mixed $valor, ?string $error = null, array $opciones = [], bool $soloLectura = false): string
    {
        $id = 'f-' . $nombre;
        $req = !empty($c['requerido']) ? ' required' : '';
        $dis = $soloLectura ? ' disabled' : '';
        $v = $valor === null ? '' : (string) $valor;
        $tipo = (string) $c['tipo'];
        $ayuda = isset($c['ayuda']) ? '<small class="muted">' . e($c['ayuda']) . '</small>' : '';
        $err = $error !== null ? '<small class="field-error">' . e($error) . '</small>' : '';

        if ($tipo === 'si_no') {
            return '<div class="field"><input type="hidden" name="' . e($nombre) . '" value="0"' . $dis . '>'
                . '<label class="check"><input type="checkbox" id="' . e($id) . '" name="' . e($nombre) . '" value="1"' . ($v === '1' ? ' checked' : '') . $dis . '> '
                . e($c['label']) . '</label>' . $ayuda . $err . '</div>';
        }

        $label = '<label class="field' . ($error !== null ? ' has-error' : '') . '" for="' . e($id) . '"><span>' . e($c['label']) . (!empty($c['requerido']) ? ' *' : '') . '</span>';
        if ($tipo === 'opciones' || $tipo === 'ref') {
            $lista = $tipo === 'opciones' ? $c['opciones'] : $opciones;
            $html = '<select id="' . e($id) . '" name="' . e($nombre) . '"' . $req . $dis . '><option value="">' . ($tipo === 'ref' ? '—' : 'Elegí') . '</option>';
            $hay = false;
            foreach ($lista as $k => $t) {
                $sel = (string) $k === $v;
                $hay = $hay || $sel;
                $html .= '<option value="' . e((string) $k) . '"' . ($sel ? ' selected' : '') . '>'
                    . e($tipo === 'ref' ? $k . ' - ' . $t : (string) $t) . '</option>';
            }
            if (!$hay && $v !== '') {
                // Código que vino del VFP y no está en la tabla: se muestra para no perderlo al guardar.
                $html .= '<option value="' . e($v) . '" selected>' . e($v) . ' (no está en la tabla)</option>';
            }
            return $label . $html . '</select>' . $ayuda . $err . '</label>';
        }

        $attrs = '';
        $inputType = 'text';
        switch ($tipo) {
            case 'texto':
                $attrs = ' maxlength="' . (int) ($c['largo'] ?? 255) . '"';
                break;
            case 'entero':
                $attrs = ' inputmode="numeric" pattern="\d*"';
                break;
            case 'decimal':
                $attrs = ' inputmode="decimal"';
                $v = $v === '' ? '' : (is_numeric($v) ? numero($v, (int) ($c['dec'] ?? 2)) : $v);
                break;
            case 'fecha':
                $inputType = 'date';
                $v = $v !== '' && Valida::fecha($v) !== null ? (string) Valida::fecha($v) : $v;
                break;
            case 'cuit':
                $attrs = ' inputmode="numeric" maxlength="13" placeholder="30-12345678-9"';
                $v = $v === '' || $v === '0' ? '' : cuit_texto($v);
                break;
            case 'email':
                $inputType = 'email';
                $attrs = ' maxlength="' . (int) ($c['largo'] ?? 120) . '"';
                break;
            case 'mails':
                $attrs = ' maxlength="' . (int) ($c['largo'] ?? 255) . '" placeholder="uno@mail.com; otro@mail.com"';
                break;
        }
        return $label . '<input id="' . e($id) . '" name="' . e($nombre) . '" type="' . $inputType . '" value="' . e($v) . '"' . $attrs . $req . $dis . '>'
            . $ayuda . $err . '</label>';
    }

    /** Valor de un campo para mostrarlo en una grilla. @param array<string,string> $opciones */
    public static function mostrar(array $c, mixed $valor, array $opciones = []): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }
        return match ($c['tipo']) {
            'decimal' => numero($valor, (int) ($c['dec'] ?? 2)),
            'fecha' => fecha((string) $valor),
            'si_no' => (string) $valor === '1' ? 'Sí' : 'No',
            'opciones' => (string) ($c['opciones'][(string) $valor] ?? $valor),
            'ref' => isset($opciones[(string) $valor]) ? $valor . ' - ' . $opciones[(string) $valor] : (string) $valor,
            'cuit' => (string) $valor === '0' ? '' : cuit_texto($valor),
            default => (string) $valor,
        };
    }
}
