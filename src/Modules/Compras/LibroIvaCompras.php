<?php
declare(strict_types=1);

namespace App\Modules\Compras;

use App\Core\Db;
use App\Modules\Listados\LibroIvaDigital as L;

/**
 * Libro IVA Digital (RG 4597), registro de COMPRAS: los dos archivos que se importan en el Portal IVA de ARCA.
 *   LIBRO_IVA_DIGITAL_COMPRAS_CBTE        un renglón de 325 caracteres por comprobante
 *   LIBRO_IVA_DIGITAL_COMPRAS_ALICUOTAS   un renglón de 84 caracteres por alícuota de los comprobantes A y M, en el mismo orden
 * Mismo formato que el de ventas (`Listados\LibroIvaDigital`): importes de 15 posiciones sin coma, textos ANSI, renglones con CRLF.
 *
 * DECISIÓN D-127 (a confirmar con la primera importación real en el Portal IVA): entran las compras vigentes del período de IVA elegido al cargarlas
 *   (no la fecha), con letra A, B, C o M (facturas, notas de débito y de crédito y tiques factura); no entran los gastos sin comprobante. Los A y M
 *   informan sus alícuotas (sin alícuotas: una de 0 % con código de operación E exento o N no gravado); los B y C van sin alícuotas, con el total
 *   como no gravado. El crédito fiscal computable es el IVA cuando la compra lo computa. Percepciones: IVA, Ingresos Brutos, municipales;
 *   Ganancias como «otros impuestos nacionales» y «otro impuesto» como otros tributos. Todo en pesos.
 */
final class LibroIvaCompras
{
    public const ARCHIVO_CBTE = 'LIBRO_IVA_DIGITAL_COMPRAS_CBTE';
    public const ARCHIVO_ALICUOTAS = 'LIBRO_IVA_DIGITAL_COMPRAS_ALICUOTAS';
    public const LARGO_CBTE = 325;
    public const LARGO_ALICUOTA = 84;

    private static function num(int|string $v, int $largo): string
    {
        return str_pad((string) $v, $largo, '0', STR_PAD_LEFT);
    }

    /**
     * @return array{periodo:string,cbte:string,alicuotas:string,cantidad:int,totales:array<string,float>,problemas:string[],sin_comprobante:int}|null
     */
    public static function generar(string $periodo): ?array
    {
        if (L::rango($periodo) === null) {
            return null;
        }
        $pdo = Db::pdo();
        $st = $pdo->prepare("SELECT c.*, p.razon_social, p.cuit FROM compras c JOIN proveedores p ON p.id = c.proveedor_id
            WHERE c.estado = 'V' AND c.periodo_iva = ? AND c.tipo <> 'GS' AND c.letra IN ('A', 'B', 'C', 'M') ORDER BY c.fecha, c.id");
        $st->execute([$periodo]);
        $alic = $pdo->prepare('SELECT alicuota, neto, iva FROM compras_iva WHERE compra_id = ? ORDER BY alicuota DESC');
        $trib = $pdo->prepare('SELECT tipo, SUM(importe) AS importe FROM compras_tributos WHERE compra_id = ? GROUP BY tipo');
        $cbte = $ali = '';
        $problemas = [];
        $t = ['gravado' => 0.0, 'iva' => 0.0, 'credito' => 0.0, 'percepciones' => 0.0, 'total' => 0.0];
        $n = 0;
        foreach ($st->fetchAll() as $c) {
            $titulo = ComprasService::titulo($c) . ' de ' . $c['razon_social'];
            $codigo = ComprobanteArca::codigo((string) $c['tipo'], (string) $c['letra']);
            if ($codigo === null) {
                $problemas[] = $titulo . ': tipo de comprobante que no va al libro.';
                continue;
            }
            $cuit = preg_replace('/\D/', '', (string) $c['cuit']) ?? '';
            if (strlen($cuit) !== 11) {
                $problemas[] = $titulo . ': el proveedor no tiene CUIT (cargalo en Proveedores).';
            }
            if ((int) $c['punto_venta'] < 1 || (int) $c['numero'] < 1) {
                $problemas[] = $titulo . ': falta el punto de venta o el número.';
            }
            $tr = ['IVA' => 0.0, 'IIBB' => 0.0, 'GAN' => 0.0, 'MUN' => 0.0, 'OTR' => 0.0];
            $trib->execute([(int) $c['id']]);
            foreach ($trib->fetchAll() as $x) {
                $tr[isset($tr[$x['tipo']]) ? $x['tipo'] : 'OTR'] += (float) $x['importe'];
            }
            $discrimina = in_array($c['letra'], ['A', 'M'], true);
            $reng = [];
            $noGravado = (float) $c['no_gravado'];
            $exento = (float) $c['exento'];
            $operacion = ' ';
            if ($discrimina) {
                $alic->execute([(int) $c['id']]);
                foreach ($alic->fetchAll() as $a) {
                    $k = number_format((float) $a['alicuota'], 2, '.', '');
                    $reng[] = ['codigo' => L::CODIGO_ALICUOTA[$k] ?? 0, 'neto' => (float) $a['neto'], 'iva' => (float) $a['iva']];
                    if (!isset(L::CODIGO_ALICUOTA[$k])) {
                        $problemas[] = $titulo . ': alícuota ' . $k . ' % desconocida.';
                    }
                }
                if ($reng === []) {
                    $reng[] = ['codigo' => 3, 'neto' => 0.0, 'iva' => 0.0];
                    $operacion = $exento > 0 ? 'E' : 'N';
                }
            } else {
                $noGravado = (float) $c['total'] - (float) $c['percepciones'] - $exento;
            }
            $percTot = array_sum($tr);
            $credito = (int) $c['computa_iva'] === 1 ? (float) $c['iva'] : 0.0;
            $tipo = self::num($codigo, 3);
            $pv = self::num((int) $c['punto_venta'], 5);
            $numero = self::num((int) $c['numero'], 20);
            $doc = '80' . self::num($cuit !== '' ? $cuit : '0', 20);
            $cbte .= str_replace('-', '', (string) $c['fecha']) . $tipo . $pv . $numero . str_repeat(' ', 16) . $doc . L::texto((string) $c['razon_social'], 30)
                . L::importe((float) $c['total']) . L::importe($noGravado) . L::importe($exento) . L::importe($tr['IVA']) . L::importe($tr['GAN'])
                . L::importe($tr['IIBB']) . L::importe($tr['MUN']) . L::importe(0) . 'PES' . '0001000000' . (string) count($reng) . $operacion
                . L::importe($credito) . L::importe($tr['OTR']) . self::num(0, 11) . str_repeat(' ', 30) . L::importe(0) . "\r\n";
            foreach ($reng as $r) {
                $ali .= $tipo . $pv . $numero . $doc . L::importe($r['neto']) . self::num($r['codigo'], 4) . L::importe($r['iva']) . "\r\n";
            }
            $sg = $c['tipo'] === 'NC' ? -1 : 1;
            $t['gravado'] += $sg * (float) $c['gravado'];
            $t['iva'] += $sg * (float) $c['iva'];
            $t['credito'] += $sg * $credito;
            $t['percepciones'] += $sg * $percTot;
            $t['total'] += $sg * (float) $c['total'];
            $n++;
        }
        $gs = $pdo->prepare("SELECT COUNT(*) FROM compras WHERE estado = 'V' AND periodo_iva = ? AND (tipo = 'GS' OR letra NOT IN ('A', 'B', 'C', 'M'))");
        $gs->execute([$periodo]);
        return ['periodo' => $periodo, 'cbte' => $cbte, 'alicuotas' => $ali, 'cantidad' => $n, 'totales' => array_map(static fn ($v) => round($v, 2), $t),
            'problemas' => $problemas, 'sin_comprobante' => (int) $gs->fetchColumn()];
    }

    /** ZIP con los dos archivos, o null sin la extensión zip. */
    public static function zip(array $libro): ?string
    {
        if (!class_exists(\ZipArchive::class)) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'livc');
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
