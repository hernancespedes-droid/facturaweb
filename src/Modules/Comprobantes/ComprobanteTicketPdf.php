<?php
declare(strict_types=1);

namespace App\Modules\Comprobantes;

use App\Core\Pdf;
use App\Core\Qr;

/**
 * Comprobante en PDF de ancho ticket (80 o 50 mm, una sola página tan larga como haga falta). Dice lo mismo que la página de ticket: renglones,
 * totales, cómo se pagó, vuelto, QR y CAE si es de ARCA, o «documento no válido como factura» si es interno. $d viene de ComprobanteImpreso::datos().
 */
final class ComprobanteTicketPdf
{
    /** @param array<string,mixed> $d @param int $mm 80 o 50 */
    public static function generar(array $d, int $mm = 80): string
    {
        $mm = $mm === 50 ? 50 : 80;
        $m = 2.0;
        $w = $mm - 2 * $m;
        $chico = $mm === 50 ? 6.5 : 7.5;
        $normal = $mm === 50 ? 7.5 : 8.5;
        // dos pasadas: la primera mide la altura, la segunda dibuja en una hoja de ese alto
        $alto = self::dibujar(null, $d, $mm, $m, $w, $normal, $chico);
        $pdf = new Pdf(false, (float) $mm, $alto + 6);
        self::dibujar($pdf, $d, $mm, $m, $w, $normal, $chico);
        return $pdf->salida($d['titulo'] . ' ' . $d['letra'] . ' ' . $d['numero']);
    }

    /** @param array<string,mixed> $d @return float la altura usada */
    private static function dibujar(?Pdf $pdf, array $d, int $mm, float $m, float $w, float $normal, float $chico): float
    {
        $medir = $pdf ?? new Pdf(false, (float) $mm, 5000.0);
        $y = $m + 3;
        $paso = static fn (float $tam): float => $tam * 0.42;
        $linea = static function (string $s, float $tam, bool $negrita = false, string $al = 'L') use (&$y, $medir, $pdf, $m, $w, $paso): void {
            foreach ($medir->partir($s, $w, $tam, $negrita) ?: [''] as $l) {
                $pdf?->texto($m, $y, $l, $tam, $negrita, $al, $w);
                $y += $paso($tam);
            }
        };
        $dos = static function (string $izq, string $der, float $tam, bool $negrita = false) use (&$y, $medir, $pdf, $m, $w, $paso): void {
            $ad = $medir->ancho($der, $tam, $negrita);
            $partes = $medir->partir($izq, max(10.0, $w - $ad - 2), $tam, $negrita) ?: [''];
            foreach ($partes as $i => $l) {
                $pdf?->texto($m, $y, $l, $tam, $negrita);
                if ($i === 0) {
                    $pdf?->texto($m, $y, $der, $tam, $negrita, 'R', $w);
                }
                $y += $paso($tam);
            }
        };
        $sep = static function () use (&$y, $pdf, $m, $w): void {
            $y -= 1.0;
            $pdf?->linea($m, $y, $m + $w, $y, 0.2);
            $y += 2.2;
        };

        $e = $d['emisor'];
        $linea($e['nombre'] !== '' ? $e['nombre'] : 'Empresa sin cargar', $normal + 2.5, true, 'C');
        foreach (array_filter([$e['domicilio'], $e['telefono'] !== '' ? 'Tel.: ' . $e['telefono'] : '', 'C.U.I.T.: ' . $e['cuit'], $e['iibb'] !== '' ? 'IIBB: ' . $e['iibb'] : '', $e['inicio'] !== '' ? 'Inicio act.: ' . $e['inicio'] : '', $e['condicion']]) as $l) {
            $linea($l, $chico, false, 'C');
        }
        $sep();
        $linea(mb_strtoupper($d['titulo']) . ' ' . $d['letra'], $normal + 1, true, 'C');
        $linea('Nº ' . $d['numero'], $normal, false, 'C');
        $linea(($d['interno'] ? 'No fiscal' : 'Cód. ' . str_pad((string) $d['codigo'], 2, '0', STR_PAD_LEFT)) . ' · ' . $d['fecha'], $chico, false, 'C');
        $sep();
        $c = $d['cliente'];
        $linea($c['razon_social'], $normal, true);
        $linea('CUIT: ' . ($c['cuit'] !== '' ? $c['cuit'] : 'sin CUIT') . ' · IVA: ' . $c['condicion'], $chico);
        if ($d['asociado'] !== '') {
            $linea('Corrige: ' . $d['asociado'], $chico);
        }
        $sep();
        foreach ($d['items'] as $it) {
            $linea($it['descripcion'], $normal);
            if (($it['texto'] ?? '') !== '') {
                $linea($it['texto'], $chico);
            }
            $dos(number_format($it['cantidad'], 3, ',', '.') . ' x ' . number_format($it['precio'], 2, ',', '.'), number_format($it['importe'], 2, ',', '.'), $normal);
            $y += 0.8;
        }
        if ($d['items'] === []) {
            $linea('(sin detalle)', $normal);
        }
        $sep();
        foreach ($d['totales'] as [$rotulo, $valor, $fuerte]) {
            $dos($rotulo, ($valor < 0 ? '- ' : '') . '$ ' . number_format(abs((float) $valor), 2, ',', '.'), $fuerte ? $normal + 3 : $normal, $fuerte);
        }
        $sep();
        $vuelto = 0.0;
        if ($d['pagos'] === []) {
            $linea($d['condicion_pago'], $normal);
        }
        foreach ($d['pagos'] as $p) {
            $dos($p['forma'], '$ ' . number_format($p['importe'], 2, ',', '.'), $normal);
            if ($p['recibido'] !== null && $p['vuelto'] > 0) {
                $dos('Recibido', '$ ' . number_format($p['recibido'], 2, ',', '.'), $chico);
            }
            $vuelto += $p['vuelto'];
        }
        if ($vuelto > 0) {
            $y += 1.0;
            $alto = $paso($normal + 3) + 2.5;
            $pdf?->rect($m, $y - $paso($normal + 3) + 0.5, $w, $alto, 0.5);
            $pdf?->texto($m, $y + 0.4, 'VUELTO: $ ' . number_format($vuelto, 2, ',', '.'), $normal + 3, true, 'C', $w);
            $y += $alto;
        }
        if ($d['observaciones'] !== '') {
            $linea('Obs.: ' . $d['observaciones'], $chico);
        }
        foreach ($d['leyendas'] as $l) {
            $linea($l, $chico);
        }
        $y += 1.5;
        if ($d['cae'] !== null) {
            $sep();
            if ($d['qr'] !== null) {
                $lado = min($w, $mm === 50 ? 32.0 : 38.0);
                $pdf?->qr(Qr::matriz($d['qr']), $m + ($w - $lado) / 2, $y, $lado);
                $y += $lado + 1;
            }
            $linea('CAE: ' . $d['cae']['cae'], $normal, true, 'C');
            if ($d['cae']['vto'] !== '') {
                $linea('Vto. CAE: ' . $d['cae']['vto'], $chico, false, 'C');
            }
            $linea('Comprobante Autorizado ARCA', $chico, false, 'C');
            if ($d['aviso_prueba'] !== '') {
                $linea($d['aviso_prueba'], $chico, true, 'C');
            }
        } else {
            $y += 1.0;
            $pdf?->rect($m, $y - 2.5, $w, $d['interno'] ? 6.5 : 9.5, 0.5);
            $linea($d['interno'] ? 'DOCUMENTO NO VÁLIDO COMO FACTURA' : 'COMPROBANTE INTERNO', $chico + 0.5, true, 'C');
            if (!$d['interno']) {
                $linea('Sin CAE: no tiene validez fiscal', $chico, false, 'C');
            }
        }
        $y += 2;
        $linea($d['copia'], $chico, false, 'C');
        $linea('¡Gracias por su compra!', $chico, false, 'C');
        return $y;
    }
}
