<?php
declare(strict_types=1);

namespace App\Modules\Listados;

use App\Core\Pdf;
use App\Modules\Clientes\ClientesService;

/**
 * Subdiario de IVA ventas impreso, para pasar al libro contable: A4 apaisada, con los datos de la empresa y el período en cada hoja,
 * hojas numeradas (desde el folio que se pida), «transporte» al pie y al principio de cada hoja, y al final los totales y el resumen por
 * alícuota (base imponible e IVA). Una fila por comprobante, en orden cronológico; las notas de crédito restan.
 */
final class SubdiarioPdf
{
    private const M = 10.0;          // margen
    private const FILA = 4.2;        // alto de cada renglón
    private const LETRA = 7.0;

    /**
     * @param array{filas:array<int,array<string,mixed>>,alicuotas:float[]} $libro
     * @param array<string,mixed> $emisor fila de parametros
     */
    public static function generar(array $libro, array $emisor, string $periodo, int $folio = 1): string
    {
        $pdf = new Pdf(true);
        $ancho = $pdf->anchoHoja - 2 * self::M;
        $cols = self::columnas($libro['alicuotas'], array_sum(array_map(static fn ($r) => abs((float) $r['otros']), $libro['filas'])) > 0.004, $ancho);
        $claves = array_keys(array_filter($cols, static fn ($c) => $c['suma']));
        $cero = array_fill_keys($claves, 0.0);
        $acum = $cero;
        $hoja = $folio;
        $y = self::cabecera($pdf, $emisor, $periodo, $hoja, $cols);
        $limite = $pdf->altoHoja - self::M - 12;
        $n = 0;
        foreach ($libro['filas'] as $r) {
            if ($y + self::FILA > $limite) { // la hoja se llenó: subtotal a transportar y hoja nueva con el transporte
                self::totales($pdf, $y, $cols, $acum, 'Subtotal a transportar', true);
                self::pie($pdf, $hoja);
                $pdf->pagina();
                $hoja++;
                $y = self::cabecera($pdf, $emisor, $periodo, $hoja, $cols);
                $y = self::totales($pdf, $y, $cols, $acum, 'Transporte', false);
            }
            $x = self::M;
            foreach ($cols as $k => $c) {
                $v = self::valor($k, $r);
                $txt = $c['num'] ? self::importe((float) $v) : $pdf->ajustar((string) $v, $c['w'] - 1.5, self::LETRA);
                $pdf->texto($x + ($c['num'] ? 0 : 0.8), $y + 3.1, $txt, self::LETRA, false, $c['num'] ? 'R' : 'L', $c['w'] - 0.8);
                $x += $c['w'];
                if ($c['suma']) {
                    $acum[$k] += (float) $v;
                }
            }
            if (++$n % 2 === 0) {
                $pdf->linea(self::M, $y + self::FILA, self::M + $ancho, $y + self::FILA, 0.05, 0.8);
            }
            $y += self::FILA;
        }
        // Totales del período y resumen por alícuota (si no entra, hoja nueva)
        $alto = 24 + 5 * count($libro['alicuotas']);
        if ($y + $alto > $limite) {
            self::pie($pdf, $hoja);
            $pdf->pagina();
            $hoja++;
            $y = self::cabecera($pdf, $emisor, $periodo, $hoja, $cols);
        }
        $y = self::totales($pdf, $y, $cols, $acum, 'Totales del período (' . count($libro['filas']) . ' comprobantes)', true);
        $y += 6;
        $pdf->texto(self::M, $y, 'RESUMEN POR ALÍCUOTA', 8, true);
        $y += 4.5;
        $pdf->texto(self::M, $y, 'Alícuota', 7.5, true);
        $pdf->texto(self::M + 25, $y, 'Base imponible', 7.5, true, 'R', 30);
        $pdf->texto(self::M + 57, $y, 'IVA', 7.5, true, 'R', 28);
        $pdf->linea(self::M, $y + 1, self::M + 85, $y + 1, 0.2);
        foreach ($libro['alicuotas'] as $k => $a) {
            $y += 4.5;
            $pdf->texto(self::M, $y, self::pct($a), 7.5);
            $pdf->texto(self::M + 25, $y, self::importe($acum["base_{$k}"] ?? 0), 7.5, false, 'R', 30);
            $pdf->texto(self::M + 57, $y, self::importe($acum["iva_{$k}"] ?? 0), 7.5, false, 'R', 28);
        }
        $y += 4.5;
        $pdf->texto(self::M, $y, 'Exento', 7.5);
        $pdf->texto(self::M + 25, $y, self::importe($acum['exento'] ?? 0), 7.5, false, 'R', 30);
        $y += 6;
        $pdf->texto(self::M, $y, 'Importes en pesos. Las notas de crédito restan. Número de comprobante: el autorizado por ARCA.', 6.5, false, 'L', null, 0.35);
        self::pie($pdf, $hoja);
        return $pdf->salida('Subdiario de IVA ventas ' . $periodo);
    }

    /** @return array<string,array{t:string,w:float,num:bool,suma:bool}> */
    private static function columnas(array $alicuotas, bool $otros, float $ancho): array
    {
        $c = [
            'fecha' => ['t' => 'Fecha', 'w' => 15.0, 'num' => false, 'suma' => false],
            'cbte' => ['t' => 'Comprobante', 'w' => 31.0, 'num' => false, 'suma' => false],
            'cliente' => ['t' => 'Razón social', 'w' => 0.0, 'num' => false, 'suma' => false],
            'cuit' => ['t' => 'CUIT', 'w' => 20.0, 'num' => false, 'suma' => false],
            'cond' => ['t' => 'IVA', 'w' => 13.0, 'num' => false, 'suma' => false],
        ];
        foreach ($alicuotas as $k => $a) {
            $c["base_{$k}"] = ['t' => 'Neto ' . self::pct($a), 'w' => 21.0, 'num' => true, 'suma' => true];
            $c["iva_{$k}"] = ['t' => 'IVA ' . self::pct($a), 'w' => 18.0, 'num' => true, 'suma' => true];
        }
        $c['exento'] = ['t' => 'Exento', 'w' => 18.0, 'num' => true, 'suma' => true];
        if ($otros) {
            $c['otros'] = ['t' => 'Otros', 'w' => 17.0, 'num' => true, 'suma' => true];
        }
        $c['total'] = ['t' => 'Total', 'w' => 22.0, 'num' => true, 'suma' => true];
        $usado = array_sum(array_column($c, 'w'));
        $c['cliente']['w'] = max(28.0, $ancho - $usado);
        if ($usado + $c['cliente']['w'] > $ancho) { // muchas alícuotas: se achican las columnas de importes
            $f = ($ancho - 28.0 - 99.0) / max(1.0, $usado - 99.0);
            foreach ($c as $k => &$x) {
                if ($x['num']) {
                    $x['w'] *= $f;
                }
            }
            unset($x);
            $c['cliente']['w'] = 28.0;
        }
        return $c;
    }

    private static function valor(string $k, array $r): mixed
    {
        return match ($k) {
            'fecha' => date('d/m/Y', (int) strtotime((string) $r['fecha'])),
            'cbte' => $r['tipo'] . ' ' . $r['letra'] . ' ' . sprintf('%04d-%08d', $r['prefijo'], $r['numero']) . ((int) $r['es_prueba'] === 1 ? ' *' : ''),
            'cliente' => (string) $r['razon_social'],
            'cuit' => cuit_texto($r['cuit_cliente']),
            'cond' => self::condicion((int) $r['iva_cliente']),
            default => (float) ($r[$k] ?? 0),
        };
    }

    private static function condicion(int $c): string
    {
        return [1 => 'RI', 2 => 'NI', 3 => 'CF', 4 => 'EX', 5 => 'EX', 6 => 'LIB', 7 => 'MT', 8 => 'EX', 9 => 'EX'][$c] ?? (ClientesService::CONDICIONES_IVA[$c] ?? '');
    }

    private static function cabecera(Pdf $pdf, array $e, string $periodo, int $hoja, array $cols): float
    {
        $m = self::M;
        $der = $pdf->anchoHoja - $m;
        $pdf->texto($m, $m + 4, (string) ($e['empresa'] ?? ''), 11, true);
        $pdf->texto($m, $m + 8.5, 'CUIT ' . cuit_texto($e['cuit'] ?? '') . '   ·   ' . (string) ($e['tk_iva'] ?? 'Responsable Inscripto')
            . (($e['tk_i_brutos'] ?? '') !== '' ? '   ·   IIBB ' . $e['tk_i_brutos'] : ''), 7.5);
        $pdf->texto($m, $m + 12, (string) ($e['tk_direccion'] ?? ''), 7.5);
        $pdf->texto($der - 120, $m + 4, 'SUBDIARIO DE IVA VENTAS', 11, true, 'R', 120);
        $pdf->texto($der - 120, $m + 8.5, 'Período: ' . $periodo, 8.5, false, 'R', 120);
        $pdf->texto($der - 120, $m + 12, 'Hoja N.º ' . $hoja, 8.5, true, 'R', 120);
        $y = $m + 16;
        $pdf->rect($m, $y, $der - $m, 6, 0.2, 0.9);
        $x = $m;
        foreach ($cols as $c) {
            $pdf->texto($x + ($c['num'] ? 0 : 0.8), $y + 4, $pdf->ajustar($c['t'], $c['w'] - 1, 7, true), 7, true, $c['num'] ? 'R' : 'L', $c['w'] - 0.8);
            $x += $c['w'];
        }
        return $y + 7;
    }

    /** Renglón de totales (transporte, subtotal o totales del período). Devuelve el y siguiente. */
    private static function totales(Pdf $pdf, float $y, array $cols, array $acum, string $texto, bool $lineaArriba): float
    {
        if ($lineaArriba) {
            $pdf->linea(self::M, $y + 0.5, $pdf->anchoHoja - self::M, $y + 0.5, 0.3);
        }
        $pdf->rect(self::M, $y + 0.8, $pdf->anchoHoja - 2 * self::M, self::FILA, 0, 0.95);
        $x = self::M;
        $antes = 0.0;
        $primero = true;
        foreach ($cols as $k => $c) {
            if ($c['suma']) {
                if ($primero) {
                    $pdf->texto(self::M + 0.8, $y + 3.9, $texto, self::LETRA, true, 'L', $antes);
                    $primero = false;
                }
                $pdf->texto($x, $y + 3.9, self::importe($acum[$k]), self::LETRA, true, 'R', $c['w'] - 0.8);
            } else {
                $antes += $c['w'];
            }
            $x += $c['w'];
        }
        return $y + self::FILA + 1.5;
    }

    private static function pie(Pdf $pdf, int $hoja): void
    {
        $y = $pdf->altoHoja - self::M;
        $pdf->texto(self::M, $y, 'Emitido el ' . date('d/m/Y H:i'), 6.5, false, 'L', null, 0.4);
        $pdf->texto($pdf->anchoHoja - self::M - 40, $y, 'Hoja N.º ' . $hoja, 6.5, false, 'R', 40, 0.4);
    }

    private static function importe(float $v): string
    {
        return (round($v, 2) < 0 ? '-' : '') . number_format(abs($v), 2, ',', '.');
    }

    private static function pct(float $a): string
    {
        return rtrim(rtrim(number_format($a, 2, ',', ''), '0'), ',') . ' %';
    }
}
