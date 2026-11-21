<?php
declare(strict_types=1);

namespace App\Modules\Compras;

use App\Core\Db;
use App\Core\Pdf;

/** La orden de pago en PDF (A4, sin librerías): para imprimir, guardar o mandar por mail al proveedor. */
final class OrdenPagoPdf
{
    /** Nombre, CUIT y dirección de la empresa (parametros, fila 1). @return array{nombre:string,cuit:string,domicilio:string} */
    public static function empresa(): array
    {
        $e = Db::pdo()->query('SELECT empresa, cuit, tk_direccion FROM parametros WHERE id = 1')->fetch() ?: [];
        return ['nombre' => trim((string) ($e['empresa'] ?? '')) ?: 'Empresa', 'cuit' => (string) ($e['cuit'] ?? ''), 'domicilio' => trim((string) ($e['tk_direccion'] ?? ''))];
    }

    /** @param array<string,mixed> $o lo que devuelve OrdenesPagoService::obtener() */
    public static function generar(array $o): string
    {
        $emp = self::empresa();
        $pdf = new Pdf();
        $m = 15.0;
        $w = Pdf::ANCHO - 2 * $m;
        $y = $m;
        $pdf->texto($m, $y + 5, $pdf->ajustar($emp['nombre'], 110, 13, true), 13, true);
        $pdf->texto($m, $y + 11, 'C.U.I.T.: ' . cuit_texto($emp['cuit']) . ($emp['domicilio'] !== '' ? ' · ' . $emp['domicilio'] : ''), 9);
        $pdf->texto($m, $y + 5, 'ORDEN DE PAGO', 13, true, 'R', $w);
        $pdf->texto($m, $y + 11, sprintf('N° %08d · %s', (int) $o['numero'], fecha($o['fecha'])), 10, false, 'R', $w);
        if ($o['estado'] === 'A') {
            $pdf->texto($m, $y + 17, 'ANULADA', 11, true, 'R', $w, 0.4);
        }
        $y += 22;
        $pdf->linea($m, $y, $m + $w, $y, 0.4);
        $y += 7;
        $pdf->texto($m, $y, 'Proveedor: ' . $pdf->ajustar((string) $o['razon_social'], 120, 10, true), 10, true);
        $y += 5;
        $pdf->texto($m, $y, 'C.U.I.T.: ' . cuit_texto((string) $o['cuit']) . ' · Código ' . (int) $o['proveedor_codigo'], 9);
        $y += 9;

        $tabla = static function (string $titulo, array $cols, array $filas) use ($pdf, $m, $w, &$y): void {
            $pdf->texto($m, $y, $titulo, 10, true);
            $y += 3;
            $pdf->rect($m, $y, $w, 6, 0.1, 0.92);
            $x = $m;
            foreach ($cols as [$t, $ancho, $al]) {
                $pdf->texto($x + 1.5, $y + 4.2, $t, 8, true, $al, $ancho - 3);
                $x += $ancho;
            }
            $y += 6;
            foreach ($filas as $f) {
                if ($y > Pdf::ALTO - 30) {
                    $pdf->pagina();
                    $y = 20;
                }
                $x = $m;
                foreach ($cols as $i => [$t, $ancho, $al]) {
                    $pdf->texto($x + 1.5, $y + 4.2, $pdf->ajustar((string) $f[$i], $ancho - 3, 8.5), 8.5, false, $al, $ancho - 3);
                    $x += $ancho;
                }
                $y += 5.5;
                $pdf->linea($m, $y, $m + $w, $y, 0.1, 0.8);
            }
            $y += 6;
        };
        $imp = array_map(static fn ($i) => [$i['titulo'], $i['fecha'] ? fecha($i['fecha']) : '', $i['vence'] ? fecha($i['vence']) : '',
            ($i['credito'] ? '− ' : '') . plata_dec((float) $i['importe'])], $o['imputaciones']);
        if ($imp !== []) {
            $tabla('Comprobantes que se cancelan', [['Comprobante', 80, 'L'], ['Fecha', 30, 'L'], ['Vence', 30, 'L'], ['Importe', $w - 140, 'R']], $imp);
        }
        $med = array_map(static fn ($x) => [OrdenesPagoService::MEDIOS[$x['medio']] ?? $x['medio'], OrdenesPagoService::detalleMedio($x), plata_dec((float) $x['importe'])], $o['medios']);
        if ($med !== []) {
            $tabla('Medios de pago', [['Medio', 55, 'L'], ['Detalle', $w - 90, 'L'], ['Importe', 35, 'R']], $med);
        }
        $lin = [['Cancela', (float) $o['debitos']], ['Descuenta (notas de crédito y anticipos)', -(float) $o['creditos']], ['Retenciones', (float) $o['retenciones']],
            ['Total pagado', (float) $o['pagado']], ['Queda a favor (anticipo)', (float) $o['anticipo']]];
        foreach ($lin as [$t, $v]) {
            if (abs($v) < 0.005 && $t !== 'Total pagado') {
                continue;
            }
            $b = $t === 'Total pagado';
            $pdf->texto($m, $y, $t, $b ? 10.5 : 9, $b, 'R', $w - 40);
            $pdf->texto($m + $w - 38, $y, plata_dec($v), $b ? 10.5 : 9, $b, 'R', 38);
            $y += 6;
        }
        if (trim((string) $o['observaciones']) !== '') {
            $y += 2;
            foreach ($pdf->partir('Observaciones: ' . $o['observaciones'], $w, 9) as $l) {
                $pdf->texto($m, $y, $l, 9);
                $y += 4.5;
            }
        }
        $y = max($y + 20, Pdf::ALTO - 45);
        $pdf->linea($m + 10, $y, $m + 75, $y, 0.2);
        $pdf->linea($m + $w - 75, $y, $m + $w - 10, $y, 0.2);
        $pdf->texto($m + 10, $y + 4, 'Firma y aclaración de quien paga', 8, false, 'C', 65);
        $pdf->texto($m + $w - 75, $y + 4, 'Recibí conforme (proveedor)', 8, false, 'C', 65);
        return $pdf->salida(OrdenesPagoService::numeroVisible((int) $o['numero']));
    }
}
