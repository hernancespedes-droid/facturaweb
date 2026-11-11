<?php
declare(strict_types=1);

namespace App\Modules\Comprobantes;

use App\Core\Pdf;

/** Comprobante en PDF A4 (el mismo diseño que la página imprimible). $d viene de ComprobanteImpreso::datos(). */
final class ComprobantePdf
{
    private const M = 10.0;          // margen
    private const ANCHO = 190.0;     // ancho útil
    private const PIE = 255.0;       // desde acá empieza el bloque de totales y pie

    /** @param array<string,mixed> $d */
    public static function generar(array $d): string
    {
        $pdf = new Pdf();
        $y = self::cabecera($pdf, $d);
        $y = self::tablaTitulos($pdf, $y);
        $gris = 0.92;
        foreach ($d['items'] as $i => $it) {
            $lineas = $pdf->partir($it['descripcion'], 100, 8.5);
            $alto = max(1, count($lineas)) * 4.0 + 1.5;
            if ($y + $alto > self::PIE - 45) { // no entra: sigue en otra página
                self::pie($pdf, $d, false);
                $pdf->pagina();
                $y = self::tablaTitulos($pdf, self::cabecera($pdf, $d));
            }
            if ($i % 2 === 1) {
                $pdf->rect(self::M, $y, self::ANCHO, $alto, 0, $gris);
            }
            foreach ($lineas as $k => $l) {
                $pdf->texto(self::M + 1.5, $y + 3.6 + $k * 4.0, $l, 8.5);
            }
            $pdf->texto(self::M + 105, $y + 3.6, self::num($it['cantidad'], 3), 8.5, false, 'R', 22);
            $pdf->texto(self::M + 128, $y + 3.6, self::num($it['precio'], 2), 8.5, false, 'R', 30);
            $pdf->texto(self::M + 158, $y + 3.6, self::num($it['importe'], 2), 8.5, false, 'R', 30);
            $y += $alto;
        }
        $pdf->linea(self::M, $y, self::M + self::ANCHO, $y, 0.2);
        if ($d['observaciones'] !== '') {
            $y += 4;
            foreach ($pdf->partir('Observaciones: ' . $d['observaciones'], self::ANCHO - 3, 8.5) as $l) {
                $pdf->texto(self::M + 1.5, $y, $l, 8.5);
                $y += 4;
            }
        }
        self::pie($pdf, $d, true);
        return $pdf->salida($d['titulo'] . ' ' . $d['letra'] . ' ' . $d['numero']);
    }

    private static function num(float $v, int $dec): string
    {
        return number_format($v, $dec, ',', '.');
    }

    private static function cabecera(Pdf $pdf, array $d): float
    {
        $m = self::M;
        $e = $d['emisor'];
        $pdf->rect($m, $m, self::ANCHO, 38, 0.3);
        // izquierda: emisor
        $pdf->texto($m + 3, $m + 8, $pdf->ajustar($e['nombre'] ?: 'Empresa sin cargar', 80, 13, true), 13, true);
        $y = $m + 14;
        foreach (array_filter([$e['domicilio'], $e['telefono'] !== '' ? 'Tel.: ' . $e['telefono'] : '', $e['mail'], $e['condicion'] !== '' ? $e['condicion'] : '']) as $i => $l) {
            $pdf->texto($m + 3, $y, $pdf->ajustar($l, 82, 8.5), 8.5, $l === $e['condicion']);
            $y += 4.5;
        }
        // centro: letra
        $pdf->rect($m + 86, $m, 18, 20, 0.3);
        $pdf->texto($m + 86, $m + 13, $d['letra'], 26, true, 'C', 18);
        $pdf->texto($m + 86, $m + 18, 'Cód. ' . str_pad((string) $d['codigo'], 2, '0', STR_PAD_LEFT), 6.5, false, 'C', 18);
        $pdf->linea($m + 95, $m + 20, $m + 95, $m + 38, 0.3);
        // derecha: comprobante
        $pdf->texto($m + 110, $m + 8, $d['titulo'], 13, true);
        $pdf->texto($m + 110, $m + 14, 'Nº ' . $d['numero'], 11, true);
        $pdf->texto($m + 110, $m + 19.5, 'Fecha: ' . $d['fecha'], 9.5);
        $y = $m + 25;
        foreach (array_filter(['C.U.I.T.: ' . $e['cuit'], $e['iibb'] !== '' ? 'Ingresos Brutos: ' . $e['iibb'] : '', $e['inicio'] !== '' ? 'Inicio de actividades: ' . $e['inicio'] : '']) as $l) {
            $pdf->texto($m + 110, $y, $l, 8.5);
            $y += 4.2;
        }
        // cliente
        $y0 = $m + 41;
        $c = $d['cliente'];
        $pdf->rect($m, $y0, self::ANCHO, 21, 0.3);
        $pdf->texto($m + 3, $y0 + 5, 'Razón social:', 8, true);
        $pdf->texto($m + 25, $y0 + 5, $pdf->ajustar($c['razon_social'] . ' (' . $c['codigo'] . ')', 100, 9, true), 9, true);
        $pdf->texto($m + 130, $y0 + 5, 'IVA: ' . $c['condicion'], 8.5);
        $pdf->texto($m + 3, $y0 + 10, 'Domicilio:', 8, true);
        $pdf->texto($m + 25, $y0 + 10, $pdf->ajustar(trim($c['domicilio'] . ' ' . $c['localidad']), 100, 8.5), 8.5);
        $pdf->texto($m + 130, $y0 + 10, 'CUIT: ' . ($c['cuit'] !== '' ? $c['cuit'] : 'sin CUIT'), 8.5);
        $pdf->texto($m + 3, $y0 + 15, 'Cond. de pago:', 8, true);
        $pdf->texto($m + 25, $y0 + 15, $d['condicion_pago'], 8.5);
        if ($d['orden_compra'] !== '') {
            $pdf->texto($m + 130, $y0 + 15, 'Orden de compra: ' . $d['orden_compra'], 8.5);
        }
        if ($d['asociado'] !== '') {
            $pdf->texto($m + 3, $y0 + 19.5, 'Corrige: ' . $d['asociado'], 8.5, true);
        }
        return $y0 + 24;
    }

    private static function tablaTitulos(Pdf $pdf, float $y): float
    {
        $m = self::M;
        $pdf->rect($m, $y, self::ANCHO, 6, 0.3, 0.85);
        $pdf->rect($m, $y, self::ANCHO, 6, 0.3);
        $pdf->texto($m + 1.5, $y + 4.2, 'Descripción', 8.5, true);
        $pdf->texto($m + 105, $y + 4.2, 'Cantidad', 8.5, true, 'R', 22);
        $pdf->texto($m + 128, $y + 4.2, 'Precio', 8.5, true, 'R', 30);
        $pdf->texto($m + 158, $y + 4.2, 'Importe', 8.5, true, 'R', 30);
        return $y + 7;
    }

    /** Totales y pie en la última página; en las intermedias solo "continúa". */
    private static function pie(Pdf $pdf, array $d, bool $final): void
    {
        $m = self::M;
        if (!$final) {
            $pdf->texto($m, 280, 'Continúa en la página siguiente…', 8.5, false, 'R', self::ANCHO);
            return;
        }
        $y = self::PIE - 30;
        $pdf->rect($m + 110, $y, 80, count($d['totales']) * 5.5 + 3, 0.3);
        $y += 5.5;
        foreach ($d['totales'] as [$rotulo, $valor, $fuerte]) {
            $pdf->texto($m + 113, $y, $rotulo, $fuerte ? 10.5 : 8.5, $fuerte);
            $pdf->texto($m + 113, $y, ($valor < 0 ? '- ' : '') . '$ ' . self::num(abs((float) $valor), 2), $fuerte ? 10.5 : 8.5, $fuerte, 'R', 74);
            $y += 5.5;
        }
        foreach ($d['leyendas'] as $i => $l) {
            foreach ($pdf->partir($l, 105, 7.5) as $k => $ln) {
                $pdf->texto($m, self::PIE - 26 + $i * 8 + $k * 3.6, $ln, 7.5);
            }
        }
        // pie: CAE (etapa 3) o aviso de comprobante interno
        $yp = self::PIE + 4;
        if ($d['cae'] !== null) {
            $pdf->rect($m, $yp, self::ANCHO, 18, 0.3);
            $pdf->texto($m + 3, $yp + 7, 'CAE: ' . $d['cae']['cae'] . '    Vto. CAE: ' . $d['cae']['vto'], 10, true);
            $pdf->texto($m + 3, $yp + 13, 'Comprobante Autorizado ARCA', 8.5);
        } else {
            $pdf->rect($m, $yp, self::ANCHO, 18, 0.5);
            $pdf->texto($m, $yp + 7.5, 'COMPROBANTE INTERNO', 12, true, 'C', self::ANCHO);
            $pdf->texto($m, $yp + 13.5, 'Sin CAE de ARCA: no tiene validez fiscal', 9, false, 'C', self::ANCHO);
        }
        $pdf->texto($m, $yp + 24, implode(' ', mb_str_split($d['copia'])), 8, true, 'R', self::ANCHO);
    }
}
