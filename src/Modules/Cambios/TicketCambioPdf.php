<?php
declare(strict_types=1);

namespace App\Modules\Cambios;

use App\Core\Pdf;
use App\Core\Qr;

/**
 * Ticket de cambio: lista lo comprado SIN importes (sirve para regalar: quien lo recibe no ve cuánto costó) con un código —también en QR— para que en el
 * mostrador se lea el comprobante y se haga el cambio. Sale en la parte de arriba de una hoja A4 para recortarlo.
 */
final class TicketCambioPdf
{
    private const X = 10.0;
    private const ANCHO = 100.0;
    private const MAX_RENGLONES = 40;

    /** @param array<string,mixed> $h comprobante de ComprobantesService::obtener() @param array<string,mixed> $emisor ComprobantesService::emisor() */
    public static function generar(array $h, array $emisor): string
    {
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
}
