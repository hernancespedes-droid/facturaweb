<?php
declare(strict_types=1);

namespace App\Modules\Cambios;

use App\Core\Pdf;
use App\Core\Qr;

/**
 * Ticket de cambio: lista lo comprado SIN importes (sirve para regalar: quien lo recibe no ve cuánto costó) con un código —también en QR— para que en el
 * mostrador se lea el comprobante y se haga el cambio. Respeta la impresión de Parámetros como el resto de los comprobantes (D-141): en A4 sale arriba
 * de la hoja para recortarlo; con impresora de ticket (t80 / t50) sale en una hoja de 80 o 50 mm de ancho, tan larga como haga falta.
 */
final class TicketCambioPdf
{
    private const X = 10.0;
    private const ANCHO = 100.0;
    private const MAX_RENGLONES = 40;

    /** Papel del ticket: el pedido (?papel=a4|t80|t50) o el de Parámetros (`parametros.impresion`). @param array<string,mixed> $emisor */
    public static function papel(?string $pedido, array $emisor): string
    {
        $p = $pedido ?? (string) ($emisor['impresion'] ?? 'a4');
        return in_array($p, ['a4', 't80', 't50'], true) ? $p : 'a4';
    }

    /**
     * @param array<string,mixed> $h comprobante de ComprobantesService::obtener() @param array<string,mixed> $emisor ComprobantesService::emisor()
     * @param ?string $papel a4|t80|t50; sin él, el de Parámetros
     */
    public static function generar(array $h, array $emisor, ?string $papel = null): string
    {
        $papel = self::papel($papel, $emisor);
        if ($papel !== 'a4') {
            return self::angosto($h, $emisor, $papel === 't50' ? 50 : 80);
        }
        $pdf = new Pdf();
        $m = self::X;
        $w = self::ANCHO;
        $nombre = trim((string) ($emisor['empresa'] ?? '')) !== '' ? (string) $emisor['empresa'] : 'Empresa sin cargar';
        $datos = array_values(array_filter([(string) ($emisor['tk_direccion'] ?? ''), trim((string) ($emisor['tk_telefono'] ?? '')) !== '' ? 'Tel.: ' . $emisor['tk_telefono'] : '']));

        // renglones: «2 x Descripción», sin precios
        $renglones = [];
        foreach (array_slice($h['items'], 0, self::MAX_RENGLONES) as $it) {
            $cant = rtrim(rtrim(number_format((float) $it['unidades'], 3, ',', ''), '0'), ',');
            $renglones[] = $pdf->partir($cant . ' x ' . (string) $it['observaciones'], $w - 8, 9);
        }
        $mas = count($h['items']) - self::MAX_RENGLONES;
        $alto = 44 + count($datos) * 4;
        foreach ($renglones as $ls) {
            $alto += count($ls) * 4.2 + 1;
        }
        $alto += ($mas > 0 ? 5 : 0) + 52;

        $pdf->rect($m, $m, $w, $alto, 0.4);
        $y = $m + 8;
        $pdf->texto($m, $y, $pdf->ajustar($nombre, $w - 6, 12, true), 12, true, 'C', $w);
        foreach ($datos as $l) {
            $y += 4.5;
            $pdf->texto($m, $y, $pdf->ajustar($l, $w - 6, 8), 8, false, 'C', $w);
        }
        $y += 9;
        $pdf->texto($m, $y, 'TICKET DE CAMBIO', 15, true, 'C', $w);
        $y += 6;
        $pdf->texto($m, $y, 'Compra del ' . fecha($h['fecha']), 9, false, 'C', $w);
        $y += 4.5;
        $pdf->texto($m, $y, \App\Modules\Comprobantes\Tipos::titulo($h['tipo'], $h['letra']) . ' ' . numero_visible($h), 9, true, 'C', $w);
        $y += 4;
        $pdf->linea($m + 4, $y, $m + $w - 4, $y, 0.3);
        $y += 5;
        foreach ($renglones as $ls) {
            foreach ($ls as $k => $l) {
                $pdf->texto($m + 4 + ($k > 0 ? 4 : 0), $y, $l, 9);
                $y += 4.2;
            }
            $y += 1;
        }
        if ($mas > 0) {
            $pdf->texto($m + 4, $y, '… y ' . $mas . ' renglón(es) más', 8.5);
            $y += 5;
        }
        $pdf->linea($m + 4, $y, $m + $w - 4, $y, 0.3);
        $y += 5;
        foreach ($pdf->partir('Presentá este ticket para cambiar la mercadería por otra de igual o mayor valor.', $w - 8, 8.5) as $l) {
            $pdf->texto($m + 4, $y, $l, 8.5);
            $y += 4;
        }
        $y += 3;
        $codigo = CambiosService::codigo($h);
        $pdf->qr(Qr::matriz($codigo), $m + 4, $y, 28);
        $pdf->texto($m + 36, $y + 9, 'Código de cambio', 8.5, false, 'L', $w - 40);
        $pdf->texto($m + 36, $y + 15, $pdf->ajustar($codigo, $w - 40, 10, true), 10, true, 'L', $w - 40);
        $pdf->texto($m + 36, $y + 22, 'Se lee en «Cambio de mercadería»', 7.5, false, 'L', $w - 40, 0.35);
        return $pdf->salida('Ticket de cambio ' . $codigo);
    }

    /** Ticket de 80 o 50 mm: dos pasadas, la primera mide el alto y la segunda dibuja en una hoja de ese largo. @param array<string,mixed> $h @param array<string,mixed> $emisor */
    private static function angosto(array $h, array $emisor, int $mm): string
    {
        $alto = self::dibujarAngosto(null, $h, $emisor, $mm);
        $pdf = new Pdf(false, (float) $mm, $alto + 6);
        self::dibujarAngosto($pdf, $h, $emisor, $mm);
        return $pdf->salida('Ticket de cambio ' . CambiosService::codigo($h));
    }

    /** @param array<string,mixed> $h @param array<string,mixed> $emisor @return float el alto usado */
    private static function dibujarAngosto(?Pdf $pdf, array $h, array $emisor, int $mm): float
    {
        $medir = $pdf ?? new Pdf(false, (float) $mm, 5000.0);
        $m = 2.0;
        $w = $mm - 2 * $m;
        $chico = $mm === 50 ? 6.5 : 7.5;
        $normal = $mm === 50 ? 7.5 : 8.5;
        $y = $m + 1; // $y = arriba del renglón que sigue (el texto se dibuja sobre su línea de base)
        $linea = static function (string $s, float $tam, bool $negrita = false, string $al = 'L', float $sangria = 0.0) use (&$y, $medir, $pdf, $m, $w): void {
            foreach ($medir->partir($s, $w - $sangria, $tam, $negrita) ?: [''] as $k => $l) {
                $extra = $k > 0 && $al === 'L' ? 3.0 : 0.0;
                $pdf?->texto($m + $sangria + $extra, $y + $tam * 0.36, $l, $tam, $negrita, $al, $w - $sangria - $extra);
                $y += $tam * 0.46;
            }
        };
        $sep = static function () use (&$y, $pdf, $m, $w): void {
            $y += 1.2;
            $pdf?->linea($m, $y, $m + $w, $y, 0.2);
            $y += 1.8;
        };

        $nombre = trim((string) ($emisor['empresa'] ?? '')) !== '' ? (string) $emisor['empresa'] : 'Empresa sin cargar';
        $linea($nombre, $normal + 2.5, true, 'C');
        foreach (array_filter([(string) ($emisor['tk_direccion'] ?? ''), trim((string) ($emisor['tk_telefono'] ?? '')) !== '' ? 'Tel.: ' . $emisor['tk_telefono'] : '']) as $l) {
            $linea($l, $chico, false, 'C');
        }
        $sep();
        $linea('TICKET DE CAMBIO', $normal + 3, true, 'C');
        $linea('Compra del ' . fecha($h['fecha']), $chico, false, 'C');
        $linea(\App\Modules\Comprobantes\Tipos::titulo($h['tipo'], $h['letra']) . ' ' . numero_visible($h), $normal, true, 'C');
        $sep();
        foreach (array_slice($h['items'], 0, self::MAX_RENGLONES) as $it) {
            $cant = rtrim(rtrim(number_format((float) $it['unidades'], 3, ',', ''), '0'), ',');
            $linea($cant . ' x ' . (string) $it['observaciones'], $normal);
            $y += 0.6;
        }
        $mas = count($h['items']) - self::MAX_RENGLONES;
        if ($mas > 0) {
            $linea('… y ' . $mas . ' renglón(es) más', $chico);
        }
        $sep();
        $linea('Presentá este ticket para cambiar la mercadería por otra de igual o mayor valor.', $chico, false, 'C');
        $y += 2;
        $codigo = CambiosService::codigo($h);
        $lado = $mm === 50 ? 26.0 : 32.0;
        $pdf?->qr(Qr::matriz($codigo), $m + ($w - $lado) / 2, $y, $lado);
        $y += $lado + 3;
        $linea('Código de cambio', $chico, false, 'C');
        $linea($codigo, $normal + 1, true, 'C');
        $linea('Se lee en «Cambio de mercadería»', $chico - 0.5, false, 'C');
        return $y;
    }
}
