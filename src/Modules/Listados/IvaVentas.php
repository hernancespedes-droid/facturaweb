<?php
declare(strict_types=1);

namespace App\Modules\Listados;

use App\Core\Db;
use App\Core\ExcelListado;
use App\Core\Valida;
use App\Modules\Comprobantes\Tipos;

/**
 * Subdiario de IVA ventas: una fila por comprobante con neto gravado, exento, IVA por alícuota y total. Las notas de crédito restan.
 * Sale de subdiario (cabecera) + iva (una fila por alícuota, ligada con subdiario_id), que es lo que el VFP presentaba a ARCA.
 *
 * DECISIÓN D-18 (Claude, a confirmar por Hernán): por omisión se listan solo los comprobantes vigentes y NO los de prueba
 * (internos, simulados o de homologación); un tilde permite incluirlos.
 */
final class IvaVentas
{
    /** Alícuotas con columna propia; lo que no entre acá va a "Otras". */
    public const ALICUOTAS = [27.0, 21.0, 10.5, 5.0, 2.5];

    /** @param array<string,string> $f desde, hasta, pv, tipo, pruebas */
    private static function filtro(array $f): array
    {
        $w = ["s.estado = ''"];
        $p = [];
        if (($d = Valida::fecha((string) ($f['desde'] ?? ''))) !== null) {
            $w[] = 's.fecha >= ?';
            $p[] = $d;
        }
        if (($d = Valida::fecha((string) ($f['hasta'] ?? ''))) !== null) {
            $w[] = 's.fecha <= ?';
            $p[] = $d;
        }
        if (ctype_digit((string) ($f['pv'] ?? '')) && (int) $f['pv'] > 0) {
            $w[] = 's.prefijo = ?';
            $p[] = (int) $f['pv'];
        }
        if (Tipos::valido((string) ($f['tipo'] ?? ''))) {
            $w[] = 's.tipo = ?';
            $p[] = $f['tipo'];
        }
        if (($f['pruebas'] ?? '') !== '1') {
            $w[] = 's.es_prueba = 0';
        }
        return [' WHERE ' . implode(' AND ', $w), $p];
    }

    /** @return array<int,float> puntos de venta que hay en los datos (para el filtro) */
    public static function puntosVenta(): array
    {
        return array_map('intval', Db::pdo()->query('SELECT DISTINCT prefijo FROM subdiario WHERE prefijo > 0 ORDER BY prefijo')->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param array<string,string> $f
     * @return array{filas:array<int,array<string,mixed>>,total:int,totales:array<string,float>}
     */
    public static function listar(array $f, int $pagina, int $porPagina, bool $todo = false): array
    {
        [$where, $p] = self::filtro($f);
        $pdo = Db::pdo();
        $sg = "IF(s.tipo = 'NC', -1, 1)";
        $cols = '';
        foreach (self::ALICUOTAS as $i => $a) {
            $cols .= ", COALESCE(SUM(IF(i.alicuota = {$a}, i.importe, 0)), 0) * {$sg} AS iva_{$i}";
        }
        $nro = Tipos::sqlNumeroVisible('s');
        $sql = "SELECT s.fe_clave, s.fecha, s.tipo, s.letra, s.prefijo, {$nro} AS numero, s.fe_ccae, s.es_prueba,
                COALESCE(s.cliente_razon_social, c.razon_social) AS razon_social, s.cuit_cliente,
                s.neto_grabado * {$sg} AS gravado, s.neto_exento * {$sg} AS exento, s.neto * {$sg} AS total,
                COALESCE(SUM(i.importe), 0) * {$sg} AS iva_total {$cols}
            FROM subdiario s LEFT JOIN clientes c ON c.codigo = s.cliente LEFT JOIN iva i ON i.subdiario_id = s.fe_clave
            {$where} GROUP BY s.fe_clave ORDER BY s.fecha, s.prefijo, s.tipo, numero";
        if ($todo) {
            $sql .= ' LIMIT ' . ExcelListado::MAX;
        } else {
            $sql .= ' LIMIT ' . $porPagina . ' OFFSET ' . (max(1, $pagina) - 1) * $porPagina;
        }
        $st = $pdo->prepare($sql);
        $st->execute($p);
        $filas = $st->fetchAll();
        foreach ($filas as &$r) {
            $otras = (float) $r['iva_total'];
            foreach (self::ALICUOTAS as $i => $a) {
                $otras -= (float) $r["iva_{$i}"];
            }
            $r['iva_otras'] = round($otras, 2);
        }
        unset($r);

        // Totales de TODO el filtro (no solo de la página): comprobantes y suma por columna.
        $cn = $pdo->prepare("SELECT COUNT(*) FROM subdiario s {$where}");
        $cn->execute($p);
        $tot = $pdo->prepare("SELECT COALESCE(SUM(s.neto_grabado * {$sg}), 0) AS gravado, COALESCE(SUM(s.neto_exento * {$sg}), 0) AS exento, COALESCE(SUM(s.neto * {$sg}), 0) AS total
            FROM subdiario s {$where}");
        $tot->execute($p);
        $totales = array_map('floatval', $tot->fetch() ?: []);
        $iv = $pdo->prepare("SELECT i.alicuota, COALESCE(SUM(i.importe * {$sg}), 0) FROM iva i JOIN subdiario s ON s.fe_clave = i.subdiario_id {$where} GROUP BY i.alicuota");
        $iv->execute($p);
        $porAlic = [];
        foreach ($iv->fetchAll(\PDO::FETCH_NUM) as [$a, $imp]) {
            $porAlic[(string) (float) $a] = (float) $imp;
        }
        $totales['iva_total'] = round(array_sum($porAlic), 2);
        $otras = $totales['iva_total'];
        foreach (self::ALICUOTAS as $i => $a) {
            $totales["iva_{$i}"] = round($porAlic[(string) $a] ?? 0.0, 2);
            $otras -= $totales["iva_{$i}"];
        }
        $totales['iva_otras'] = round($otras, 2);
        return ['filas' => $filas, 'total' => (int) $cn->fetchColumn(), 'totales' => $totales];
    }
}
