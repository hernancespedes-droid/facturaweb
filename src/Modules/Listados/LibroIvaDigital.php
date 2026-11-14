<?php
declare(strict_types=1);

namespace App\Modules\Listados;

use App\Core\Db;
use App\Modules\Comprobantes\Tipos;

/**
 * Libro IVA Digital (RG 4597), registro de VENTAS: arma los dos archivos que se importan en el Portal IVA de ARCA.
 *   LIBRO_IVA_DIGITAL_VENTAS_CBTE        un renglón de 266 caracteres por comprobante
 *   LIBRO_IVA_DIGITAL_VENTAS_ALICUOTAS   un renglón de 62 caracteres por alícuota de cada comprobante, en el MISMO orden que el anterior
 * Diseño de registros y especificaciones: ARCA, «Libro de IVA Digital R.G. N° 4597 — Especificaciones» (revisión 30/07/2025).
 * Importes: 15 posiciones, 13 enteros y 2 decimales sin punto; textos a la izquierda con espacios; archivos ANSI (ISO 8859-1), renglones con CRLF.
 *
 * DECISIÓN D-33 (Claude, a confirmar por Hernán): entran solo los comprobantes fiscales del mes (con CAE, vigentes y que no sean de prueba); los internos y
 *   los de prueba se cuentan aparte y no se informan. Importes no gravados, percepciones, impuestos internos y otros tributos van en cero (la web no los maneja).
 * DECISIÓN D-34: el comprador sin CUIT se informa con documento 99 «CONSUMIDOR FINAL» (no se pide DNI aunque supere el umbral de identificación).
 *   Todo va en pesos (moneda PES, cambio 1). Las notas de crédito van con importes positivos y su tipo de comprobante (3, 8 o 13).
 * DECISIÓN D-35: un comprobante sin IVA (exento o no gravado) se informa con una alícuota 0 (código 3) y código de operación E (exento) o N (no gravado).
 */
final class LibroIvaDigital
{
    /** Alícuota de IVA => código de la tabla «Alícuotas» de ARCA. */
    public const CODIGO_ALICUOTA = ['0.00' => 3, '10.50' => 4, '21.00' => 5, '27.00' => 6, '5.00' => 8, '2.50' => 9];
    public const ARCHIVO_CBTE = 'LIBRO_IVA_DIGITAL_VENTAS_CBTE';
    public const ARCHIVO_ALICUOTAS = 'LIBRO_IVA_DIGITAL_VENTAS_ALICUOTAS';
    public const LARGO_CBTE = 266;
    public const LARGO_ALICUOTA = 62;

    /** @return array{0:string,1:string}|null primer y último día del mes «AAAA-MM» */
    public static function rango(string $periodo): ?array
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $periodo, $m) !== 1 || (int) $m[1] < 2000) {
            return null;
        }
        $ini = "{$m[1]}-{$m[2]}-01";
        return [$ini, date('Y-m-t', (int) strtotime($ini))];
    }

    /** Importe en centavos relleno con ceros: 15 posiciones. */
    public static function importe(float $v, int $largo = 15): string
    {
        $c = (int) round(abs($v) * 100);
        return str_pad((string) $c, $largo, '0', STR_PAD_LEFT);
    }

    /** Texto a la izquierda, relleno con espacios, en ISO 8859-1 (ANSI) y sin nada que rompa el renglón. */
    public static function texto(string $t, int $largo): string
    {
        $t = mb_strtoupper(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $t)), 'UTF-8');
        $t = mb_substr($t, 0, $largo, 'UTF-8');
        $ansi = (string) mb_convert_encoding($t, 'ISO-8859-1', 'UTF-8');
        return str_pad($ansi, $largo, ' ');
    }

    private static function num(int|string $v, int $largo): string
    {
        return str_pad((string) $v, $largo, '0', STR_PAD_LEFT);
    }

    /**
     * Genera el libro de un mes.
     * @return array{periodo:string,cbte:string,alicuotas:string,cantidad:int,omitidos:array{internos:int,pruebas:int,anulados:int},totales:array<string,float>,problemas:string[],filas:array<int,array<string,mixed>>}|null
     *         null si el período no es válido
     */
    public static function generar(string $periodo): ?array
    {
        $rango = self::rango($periodo);
        if ($rango === null) {
            return null;
        }
        [$desde, $hasta] = $rango;
        $pdo = Db::pdo();
        $nro = Tipos::sqlNumeroVisible('s');
        $cae = "s.fe_ccae NOT IN ('', '.')";

        $st = $pdo->prepare("SELECT s.fe_clave, s.fecha, s.tipo, s.letra, s.prefijo, {$nro} AS numero, s.fe_ntipoafip, s.cliente, s.cuit_cliente, s.neto, s.neto_grabado, s.neto_exento,
                COALESCE(NULLIF(s.cliente_razon_social, ''), c.razon_social, '') AS razon_social
            FROM subdiario s LEFT JOIN clientes c ON c.codigo = s.cliente
            WHERE s.fecha BETWEEN ? AND ? AND s.estado = '' AND s.es_prueba = 0 AND {$cae}
            ORDER BY s.fecha, s.prefijo, s.tipo, s.letra, numero, s.fe_clave");
        $st->execute([$desde, $hasta]);
        $cabeceras = $st->fetchAll();

        // IVA por comprobante, de una sola vez
        $ivaPor = [];
        $iv = $pdo->prepare("SELECT i.subdiario_id, i.alicuota, i.monto, i.importe FROM iva i JOIN subdiario s ON s.fe_clave = i.subdiario_id
            WHERE s.fecha BETWEEN ? AND ? AND s.estado = '' AND s.es_prueba = 0 AND {$cae} ORDER BY i.subdiario_id, i.alicuota");
        $iv->execute([$desde, $hasta]);
        foreach ($iv->fetchAll() as $r) {
            $ivaPor[(int) $r['subdiario_id']][] = $r;
        }

        $cbte = $alic = '';
        $problemas = [];
        $filas = [];
        $totales = ['gravado' => 0.0, 'exento' => 0.0, 'iva' => 0.0, 'total' => 0.0];
        foreach ($cabeceras as $h) {
            $id = (int) $h['fe_clave'];
            $titulo = Tipos::titulo((string) $h['tipo'], (string) $h['letra']) . ' ' . sprintf('%04d-%08d', $h['prefijo'], $h['numero']);
            $tipoAfip = (int) $h['fe_ntipoafip'] > 0 ? (int) $h['fe_ntipoafip'] : Tipos::tipoAfip((string) $h['tipo'], (string) $h['letra']);
            if ($tipoAfip <= 0) {
                $problemas[] = "{$titulo}: no se pudo determinar el tipo de comprobante de ARCA.";
                continue;
            }
            if ((int) $h['prefijo'] < 1 || (int) $h['prefijo'] > 9997) {
                $problemas[] = "{$titulo}: el punto de venta tiene que estar entre 1 y 9997.";
            }
            $total = (float) $h['neto'];
            $exento = (float) $h['neto_exento'];
            $reglones = [];
            $sumaIva = $sumaNeto = 0.0;
            foreach ($ivaPor[$id] ?? [] as $r) {
                $clave = number_format((float) $r['alicuota'], 2, '.', '');
                if (!isset(self::CODIGO_ALICUOTA[$clave])) {
                    $problemas[] = "{$titulo}: la alícuota {$clave} % no existe en la tabla de ARCA.";
                    continue;
                }
                $reglones[] = ['codigo' => self::CODIGO_ALICUOTA[$clave], 'neto' => (float) $r['monto'], 'iva' => (float) $r['importe'], 'alicuota' => (float) $r['alicuota']];
                $sumaNeto += (float) $r['monto'];
                $sumaIva += (float) $r['importe'];
            }
            $conIva = false;
            foreach ($reglones as $r) {
                if ($r['iva'] > 0 || $r['neto'] > 0) {
                    $conIva = true;
                }
            }
            $operacion = ' ';
            if (!$conIva) { // exento o no gravado: una alícuota 0 y el código de operación
                $reglones = [['codigo' => 3, 'neto' => 0.0, 'iva' => 0.0, 'alicuota' => 0.0]];
                $operacion = $exento > 0 ? 'E' : 'N';
                $sumaNeto = $sumaIva = 0.0;
            }
            // Lo que no es neto gravado, IVA ni exento (campo 10): tiene que cerrar con el total
            $noGravado = round($total - $sumaNeto - $sumaIva - $exento, 2);
            if ($noGravado < 0) {
                if ($noGravado < -0.02) {
                    $problemas[] = "{$titulo}: el total (" . number_format($total, 2, ',', '.') . ') es menor que la suma de gravado, IVA y exento: ARCA lo va a rechazar.';
                }
                $noGravado = 0.0;
            } elseif ($noGravado <= 0.02) {
                $noGravado = 0.0;
            } elseif (!$conIva && $exento <= 0) {
                $noGravado = $total; // todo el comprobante es no gravado
            }
            $cuit = (string) preg_replace('/\D/', '', (string) ($h['cuit_cliente'] ?? ''));
            $conCuit = strlen($cuit) === 11 && (int) $cuit > 0;
            $nombre = $conCuit ? (string) $h['razon_social'] : 'CONSUMIDOR FINAL';
            if ($conCuit && trim($nombre) === '') {
                $problemas[] = "{$titulo}: el cliente " . (int) $h['cliente'] . ' no tiene razón social (cargala en Clientes).';
            }
            $numero = self::num((int) $h['numero'], 20);
            $pv = self::num((int) $h['prefijo'], 5);
            $tipo = self::num($tipoAfip, 3);

            $cbte .= str_replace('-', '', (string) $h['fecha']) . $tipo . $pv . $numero . $numero
                . ($conCuit ? '80' : '99') . self::num($conCuit ? $cuit : '0', 20) . self::texto($nombre, 30)
                . self::importe($total) . self::importe($noGravado) . self::importe(0) . self::importe($exento)
                . self::importe(0) . self::importe(0) . self::importe(0) . self::importe(0)
                . 'PES' . '0001000000' . (string) count($reglones) . $operacion . self::importe(0) . '00000000' . "\r\n";
            foreach ($reglones as $r) {
                $alic .= $tipo . $pv . $numero . self::importe($r['neto']) . self::num($r['codigo'], 4) . self::importe($r['iva']) . "\r\n";
            }
            $filas[] = ['fecha' => $h['fecha'], 'titulo' => $titulo, 'razon_social' => $nombre, 'total' => $total, 'gravado' => $sumaNeto, 'iva' => $sumaIva, 'exento' => $exento, 'alicuotas' => count($reglones)];
            $sg = $h['tipo'] === 'NC' ? -1 : 1; // en el archivo las notas de crédito van positivas; en los totales de la pantalla restan
            $totales['gravado'] += $sg * $sumaNeto;
            $totales['exento'] += $sg * $exento;
            $totales['iva'] += $sg * $sumaIva;
            $totales['total'] += $sg * $total;
        }
        // Lo que quedó afuera, para que no sorprenda
        $om = $pdo->prepare("SELECT COALESCE(SUM(s.fe_ccae IN ('', '.') AND s.estado = ''), 0), COALESCE(SUM(s.fe_ccae NOT IN ('', '.') AND s.es_prueba = 1 AND s.estado = ''), 0), COALESCE(SUM(s.estado <> ''), 0)
            FROM subdiario s WHERE s.fecha BETWEEN ? AND ?");
        $om->execute([$desde, $hasta]);
        [$internos, $pruebas, $anulados] = array_map('intval', $om->fetch(\PDO::FETCH_NUM));

        return ['periodo' => $periodo, 'cbte' => $cbte, 'alicuotas' => $alic, 'cantidad' => count($filas), 'omitidos' => ['internos' => $internos, 'pruebas' => $pruebas, 'anulados' => $anulados],
            'totales' => array_map(static fn ($v) => round($v, 2), $totales), 'problemas' => $problemas, 'filas' => $filas];
    }

    /** ZIP con los dos archivos, o null si el servidor no tiene la extensión zip. */
    public static function zip(array $libro): ?string
    {
        if (!class_exists(\ZipArchive::class)) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'liva');
        $z = new \ZipArchive();
        if ($tmp === false || $z->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            return null;
        }
        $z->addFromString(self::ARCHIVO_CBTE . '.txt', $libro['cbte']);
        $z->addFromString(self::ARCHIVO_ALICUOTAS . '.txt', $libro['alicuotas']);
        $z->close();
        $bin = (string) file_get_contents($tmp);
        unlink($tmp);
        return $bin;
    }
}
