<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Valida y convierte lo que vino de un formulario según la declaración de los campos (ver Form y
 * Tablas\Definiciones). Devuelve los valores listos para grabar y los errores por campo.
 */
final class Validador
{
    /**
     * @param array<string,array<string,mixed>> $campos declaración de los campos
     * @param string[] $omitir campos que no se toman del formulario (la clave al modificar)
     * @return array{0:array<string,mixed>,1:array<string,string>}
     */
    public static function validar(array $campos, array $data, array $omitir = []): array
    {
        $valores = [];
        $errores = [];
        foreach ($campos as $campo => $c) {
            if (in_array($campo, $omitir, true)) {
                continue;
            }
            $v = $data[$campo] ?? '';
            $v = is_string($v) ? trim($v) : '';
            $requerido = !empty($c['requerido']);
            if ($v === '' && $c['tipo'] !== 'si_no') {
                if ($requerido) {
                    $errores[$campo] = 'Completá ' . mb_strtolower($c['label']) . '.';
                    continue;
                }
                $valores[$campo] = in_array($c['tipo'], ['entero', 'decimal'], true) ? 0 : null;
                continue;
            }
            switch ($c['tipo']) {
                case 'texto':
                    if (mb_strlen($v) > ($c['largo'] ?? 255)) {
                        $errores[$campo] = 'Hasta ' . ($c['largo'] ?? 255) . ' caracteres.';
                        break;
                    }
                    if (!empty($c['digitos'])) { // código de barras: solo números, del largo exacto y con el dígito verificador GS1 bien
                        if (preg_match('/^\d{' . (int) $c['digitos'] . '}$/', $v) !== 1) {
                            $errores[$campo] = 'Tienen que ser ' . (int) $c['digitos'] . ' dígitos, solo números.';
                            break;
                        }
                        if (!empty($c['gs1']) && !self::gs1Valido($v)) {
                            $errores[$campo] = 'El dígito verificador no coincide: revisá el código.';
                            break;
                        }
                    }
                    $valores[$campo] = !empty($c['clave']) ? mb_strtoupper($v) : $v;
                    break;
                case 'entero':
                    if (preg_match('/^\d+$/', $v) !== 1 || (int) $v < ($c['min'] ?? 0) || (int) $v > ($c['max'] ?? 2147483647)) {
                        $errores[$campo] = 'Tiene que ser un número entero entre ' . ($c['min'] ?? 0) . ' y ' . ($c['max'] ?? 2147483647) . '.';
                        break;
                    }
                    $valores[$campo] = (int) $v;
                    break;
                case 'decimal':
                    $n = Valida::decimal($v);
                    if ($n === null || $n < ($c['min'] ?? 0) || $n > ($c['max'] ?? 1e12)) {
                        $errores[$campo] = 'Tiene que ser un número entre ' . numero($c['min'] ?? 0, 4) . ' y ' . numero($c['max'] ?? 1e12, 2) . '.';
                        break;
                    }
                    $valores[$campo] = round($n, $c['dec'] ?? 2);
                    break;
                case 'fecha':
                    $f = Valida::fecha($v);
                    if ($f === null) {
                        $errores[$campo] = 'La fecha no es válida.';
                        break;
                    }
                    $valores[$campo] = $f;
                    break;
                case 'si_no':
                    $valores[$campo] = $v === '1' ? 1 : 0;
                    break;
                case 'opciones':
                    if (!array_key_exists($v, $c['opciones'])) {
                        $errores[$campo] = 'Elegí una opción de la lista.';
                        break;
                    }
                    $valores[$campo] = preg_match('/^\d+$/', $v) === 1 ? (int) $v : $v;
                    break;
                case 'ref':
                    if (!isset(self::opciones((string) $c['ref'])[$v])) {
                        $errores[$campo] = 'Elegí una opción de la lista.';
                        break;
                    }
                    $valores[$campo] = $v;
                    break;
                case 'cuit':
                    if (!Valida::cuit($v)) {
                        $errores[$campo] = 'El CUIT no es válido (revisá los 11 números).';
                        break;
                    }
                    $valores[$campo] = (int) preg_replace('/\D/', '', $v);
                    break;
                case 'mails':
                    $m = Valida::mails($v);
                    if ($m === null || mb_strlen($m) > ($c['largo'] ?? 255)) {
                        $errores[$campo] = 'Algún mail no es válido (separalos con punto y coma).';
                        break;
                    }
                    $valores[$campo] = $m === '' ? null : $m;
                    break;
                case 'email':
                    if (mb_strlen($v) > ($c['largo'] ?? 120) || filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
                        $errores[$campo] = 'El mail no es válido.';
                        break;
                    }
                    $valores[$campo] = mb_strtolower($v);
                    break;
            }
        }
        return [$valores, $errores];
    }

    /** @var array<string,array<string,string>> opciones de los campos "ref" ya leídas en este pedido */
    private static array $cache = [];

    /** Códigos válidos de un campo "ref" (tabla con codigo y descripcion). @return array<string,string> */
    public static function opciones(string $tabla): array
    {
        return self::$cache[$tabla] ??= \App\Modules\Tablas\TablasService::opcionesRef($tabla);
    }

    public static function olvidar(): void
    {
        self::$cache = [];
    }

    /** Dígito verificador GS1 (EAN-13, DUN-14, GTIN en general): el último dígito completa la suma a múltiplo de 10. */
    public static function gs1Valido(string $codigo): bool
    {
        if (preg_match('/^\d{8,18}$/', $codigo) !== 1) {
            return false;
        }
        $suma = 0;
        $n = strlen($codigo);
        for ($i = $n - 2, $peso = 3; $i >= 0; $i--, $peso = $peso === 3 ? 1 : 3) {
            $suma += (int) $codigo[$i] * $peso;
        }
        return (10 - $suma % 10) % 10 === (int) $codigo[$n - 1];
    }
}
