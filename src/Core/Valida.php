<?php
declare(strict_types=1);

namespace App\Core;

/** Validaciones y conversiones de lo que se escribe en los formularios (números con coma, CUIT, fechas). */
final class Valida
{
    /** CUIT/CUIL de 11 dígitos con dígito verificador correcto. Acepta guiones y espacios. */
    public static function cuit(string $cuit): bool
    {
        $c = preg_replace('/\D/', '', $cuit) ?? '';
        if (strlen($c) !== 11) {
            return false;
        }
        $pesos = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $suma = 0;
        for ($i = 0; $i < 10; $i++) {
            $suma += (int) $c[$i] * $pesos[$i];
        }
        $dv = 11 - ($suma % 11);
        $dv = $dv === 11 ? 0 : ($dv === 10 ? 9 : $dv);
        return $dv === (int) $c[10];
    }

    /**
     * Número escrito a mano: "1.234,56", "1234,56" o "1234.56". Null si no es un número.
     * Con coma, la coma es el decimal y los puntos son de miles; sin coma, un solo punto es el decimal.
     */
    public static function decimal(string $v): ?float
    {
        $v = str_replace([' ', '$'], '', trim($v));
        if ($v === '') {
            return null;
        }
        if (str_contains($v, ',')) {
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        } elseif (substr_count($v, '.') > 1) {
            $v = str_replace('.', '', $v);
        }
        return preg_match('/^-?\d+(\.\d+)?$/', $v) === 1 ? (float) $v : null;
    }

    /** Fecha "aaaa-mm-dd" (input date) o "dd/mm/aaaa": devuelve "aaaa-mm-dd" o null si no es válida. */
    public static function fecha(string $v): ?string
    {
        $v = trim($v);
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $v, $m) === 1) {
            [$a, $me, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m) === 1) {
            [$d, $me, $a] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        return checkdate($me, $d, $a) && $a >= 1900 && $a <= 2100 ? sprintf('%04d-%02d-%02d', $a, $me, $d) : null;
    }

    /** Lista de mails separados por ; o , (vacío vale). Devuelve la lista normalizada "a@x.com; b@y.com" o null si alguno no es válido. */
    public static function mails(string $v): ?string
    {
        $partes = array_values(array_filter(array_map('trim', preg_split('/[;,]/', $v) ?: []), static fn ($x) => $x !== ''));
        foreach ($partes as $p) {
            if (filter_var($p, FILTER_VALIDATE_EMAIL) === false) {
                return null;
            }
        }
        return implode('; ', array_map('mb_strtolower', $partes));
    }
}
