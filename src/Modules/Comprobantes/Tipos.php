<?php
declare(strict_types=1);

namespace App\Modules\Comprobantes;

/** Tipos de comprobante, letras y sus códigos (los de sucursales, de cta. cte. y de ARCA). */
final class Tipos
{
    public const NOMBRES = ['FC' => 'Factura', 'NC' => 'Nota de crédito', 'ND' => 'Nota de débito'];
    /** tico de ctact: 1 factura, 2 nota de débito, 8 nota de crédito. */
    private const TICO = ['FC' => 1, 'ND' => 2, 'NC' => 8];
    /** Código ARCA de la letra: factura A=1, B=6, C=11; la nota de débito suma 1 y la de crédito 2. */
    private const BASE_AFIP = ['A' => 1, 'B' => 6, 'C' => 11];
    private const DESPLAZAMIENTO = ['FC' => 0, 'ND' => 1, 'NC' => 2];
    /** Condiciones del emisor (parametros.iva del VFP: 1 Inscripto, 7 Monotributo, 4 Exento) => RI, MT, EX. */
    private const EMISOR = [1 => 'RI', 7 => 'MT', 4 => 'EX'];
    /** Ids de ARCA de la condición del receptor a los que un emisor Responsable Inscripto les hace factura A. */
    private const RECEPTOR_CON_A = [1, 6, 13, 16];

    /** Expresión SQL del número con el que se conoce un comprobante: el de ARCA si tiene CAE, si no el interno. $a = alias de la tabla. */
    public static function sqlNumeroVisible(string $a = ''): string
    {
        $p = $a === '' ? '' : $a . '.';
        return "IF({$p}fe_ccae NOT IN ('', '.') AND {$p}fe_nrocbte > 0, {$p}fe_nrocbte, {$p}comprobante)";
    }

    /** Lo mismo en PHP, a partir de una fila con comprobante y, si los tiene, fe_nrocbte y fe_ccae. */
    public static function numeroVisible(array $c): int
    {
        $cae = (string) ($c['fe_ccae'] ?? '');
        return $cae !== '' && $cae !== '.' && (int) ($c['fe_nrocbte'] ?? 0) > 0 ? (int) $c['fe_nrocbte'] : (int) $c['comprobante'];
    }

    public static function valido(string $tipo): bool
    {
        return isset(self::NOMBRES[$tipo]);
    }

    public static function tico(string $tipo): int
    {
        return self::TICO[$tipo];
    }

    public static function titulo(string $tipo, string $letra): string
    {
        return self::NOMBRES[$tipo] . ' ' . $letra;
    }

    public static function tipoAfip(string $tipo, string $letra): int
    {
        return isset(self::BASE_AFIP[$letra], self::DESPLAZAMIENTO[$tipo]) ? self::BASE_AFIP[$letra] + self::DESPLAZAMIENTO[$tipo] : 0;
    }

    /** RI, MT o EX según la condición de IVA de la empresa; null si no es una de las tres. */
    public static function condicionEmisor(?int $ivaEmpresa): ?string
    {
        return self::EMISOR[(int) $ivaEmpresa] ?? null;
    }

    /**
     * Letra del comprobante (regla actual de ARCA): el emisor Responsable Inscripto hace A a responsables inscriptos y
     * monotributistas y B al resto (exentos y consumidores finales); monotributista o exento hace siempre C.
     *
     * @param string $condicionEmisor RI, MT o EX
     * @param int $condicionReceptorArca Id de ARCA de la condición de IVA del receptor
     */
    public static function letra(string $condicionEmisor, int $condicionReceptorArca): string
    {
        if ($condicionEmisor !== 'RI') {
            return 'C';
        }
        return in_array($condicionReceptorArca, self::RECEPTOR_CON_A, true) ? 'A' : 'B';
    }

    /** Columna de sucursales con el último número usado de ese tipo y letra, o null si no existe (ej. nota de débito E). */
    public static function columnaNumerador(string $tipo, string $letra): ?string
    {
        $base = ['FC' => 'ultima_factura_', 'NC' => 'ultima_nc_', 'ND' => 'ultima_nd_'][$tipo] ?? null;
        return $base !== null && isset(self::BASE_AFIP[$letra]) ? $base . strtolower($letra) : null;
    }
}
