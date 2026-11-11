<?php
declare(strict_types=1);

namespace App\Modules\Comprobantes;

/**
 * Datos de un comprobante listos para imprimir (los usan la página imprimible y el PDF, así los dos dicen lo mismo).
 * Es solo presentación: los importes salen de lo que quedó grabado en el subdiario.
 */
final class ComprobanteImpreso
{
    private const TITULOS = ['FC' => 'FACTURA', 'NC' => 'NOTA DE CREDITO', 'ND' => 'NOTA DE DEBITO'];
    private const CONDICION_EMISOR = [1 => 'Responsable Inscripto', 7 => 'Responsable Monotributo', 4 => 'IVA Exento'];

    /** Suma de los renglones como se ven (en la A, netos; en la B y C, con IVA). */
    public static function subtotal(array $h): float
    {
        $s = 0.0;
        foreach ($h['items'] as $it) {
            $s += (float) $it['total_item'];
        }
        return Calculadora::r2($s);
    }

    /** Descuento general como se ve (en la A, neto; en la B y C, con IVA). */
    public static function descuento(array $h): float
    {
        if ($h['items'] === []) {
            return 0.0;
        }
        $despues = $h['letra'] === 'A' ? (float) $h['neto_grabado'] + (float) $h['neto_exento'] : (float) $h['neto'];
        return max(0.0, Calculadora::r2(self::subtotal($h) - $despues));
    }

    /**
     * @param array<string,mixed> $h comprobante de ComprobantesService::obtener()
     * @param array<string,mixed> $emisor ComprobantesService::emisor()
     * @return array<string,mixed>
     */
    public static function datos(array $h, array $emisor, string $copia = 'ORIGINAL'): array
    {
        $cli = $h['cliente_actual'] ?? [];
        $letra = (string) $h['letra'];
        $disc = $letra === 'A';
        $conCae = $h['fe_ccae'] !== '' && $h['fe_ccae'] !== '.';
        $nroCbte = $conCae && (int) $h['fe_nrocbte'] > 0 ? (int) $h['fe_nrocbte'] : (int) $h['comprobante'];

        $items = [];
        foreach ($h['items'] as $it) {
            $items[] = ['descripcion' => (string) $it['observaciones'], 'cantidad' => (float) $it['unidades'], 'precio' => (float) $it['precio'], 'importe' => (float) $it['total_item'],
                'exento' => $letra !== 'C' && (float) $it['por_iva'] <= 0];
        }

        $totales = [];
        $sub = self::subtotal($h);
        $desc = self::descuento($h);
        if ($items !== []) {
            $totales[] = [$disc ? 'Subtotal neto' : 'Subtotal', $sub, false];
        }
        if ($desc > 0) {
            $totales[] = ['Descuento ' . numero($h['porcentaje_descuento']) . ' %', -$desc, false];
        }
        if ($disc) {
            foreach ($h['iva'] as $g) {
                $totales[] = ['IVA ' . numero($g['alicuota']) . ' %', (float) $g['importe'], false];
            }
            if ((float) $h['neto_exento'] > 0) {
                $totales[] = ['Importe exento', (float) $h['neto_exento'], false];
            }
        }
        $totales[] = ['TOTAL', (float) $h['neto'], true];

        $leyendas = [];
        if ($letra === 'B') {
            $leyendas[] = 'Régimen de Transparencia Fiscal al Consumidor (Ley 27.743). IVA contenido: ' . plata_dec($h['iva_1']);
        }

        $pago = (int) $h['forma_pago'] === 2 ? 'Cuenta corriente'
            : 'Contado' . ($h['pagos'] !== [] ? ' - ' . ($h['pagos'][0]['fpago_descripcion'] ?? $h['pagos'][0]['p_fpago']) : '');

        return [
            'emisor' => [
                'nombre' => (string) ($emisor['empresa'] ?? ''), 'domicilio' => (string) ($emisor['tk_direccion'] ?? ''), 'telefono' => (string) ($emisor['tk_telefono'] ?? ''),
                'mail' => (string) ($emisor['mail'] ?? ''), 'cuit' => cuit_texto($emisor['cuit'] ?? ''), 'iibb' => (string) ($emisor['tk_i_brutos'] ?? ''),
                'inicio' => !empty($emisor['inicio_actividades']) ? fecha($emisor['inicio_actividades']) : '',
                'condicion' => trim((string) ($emisor['tk_iva'] ?? '')) !== '' ? (string) $emisor['tk_iva'] : (self::CONDICION_EMISOR[(int) ($emisor['iva'] ?? 0)] ?? ''),
            ],
            'titulo' => self::TITULOS[$h['tipo']] ?? $h['tipo'], 'letra' => $letra, 'codigo' => (int) $h['tipoafipoys'] ?: Tipos::tipoAfip($h['tipo'], $letra),
            'numero' => numero_cbte($h['prefijo'], $nroCbte), 'fecha' => fecha($h['fecha']),
            'cliente' => [
                'razon_social' => (string) ($h['cliente_razon_social'] ?? $cli['razon_social'] ?? ''), 'codigo' => (string) $h['cliente'],
                'domicilio' => (string) ($h['cliente_domicilio'] ?? $cli['domicilio'] ?? ''), 'localidad' => (string) ($h['cliente_localidad'] ?? $cli['localidad'] ?? ''),
                'cuit' => cuit_texto($h['cuit_cliente']), 'condicion' => \App\Modules\Clientes\ClientesService::CONDICIONES_IVA[(int) $h['iva_cliente']] ?? '',
            ],
            'condicion_pago' => $pago, 'orden_compra' => (string) $h['orden_compra'],
            'asociado' => $h['fe_nrocbte_asoc'] > 0 ? Tipos::titulo($h['fe_tipocbte_asoc'], $letra) . ' ' . numero_cbte($h['prefijo'], $h['fe_nrocbte_asoc']) . ($h['fe_fecha_asoc'] ? ' del ' . fecha($h['fe_fecha_asoc']) : '') : '',
            'items' => $items, 'totales' => $totales, 'observaciones' => (string) $h['observaciones'], 'leyendas' => $leyendas, 'copia' => $copia,
            'cae' => $conCae ? ['cae' => (string) $h['fe_ccae'], 'vto' => fecha($h['fe_dvtocae'])] : null,
            'nombre_archivo' => preg_replace('/[^A-Za-z0-9._-]+/', '_', Tipos::titulo($h['tipo'], $h['letra']) . '_' . numero_cbte($h['prefijo'], $nroCbte)) . '.pdf',
        ];
    }
}
