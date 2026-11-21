<?php
declare(strict_types=1);

namespace App\Modules\Listados;

use App\Core\Db;
use App\Core\Orden;

/**
 * Lista de costos y márgenes de los artículos (permiso listados.costos; reemplaza `l_art_costo` del VFP).
 * Margen real = (precio de lista 1 neto − costo en pesos) / costo, igual que el «margen» de la ficha (recargo sobre el costo).
 * DECISIÓN D-64 (Claude): el margen se calcula sobre el precio neto de IVA. La ficha no dice si su precio incluye IVA, así que la pantalla lo pregunta
 * («Los precios de la ficha son netos | con IVA»): por omisión netos si la empresa es responsable inscripta y con IVA si no (precio final).
 */
final class ListaCostos
{
    private const ORDEN = ['codigo' => 'a.codigo', 'descripcion' => 'a.descripcion', 'rubro' => 'a.rubro IS NULL, a.rubro, a.subrubro', 'proveedor' => 'a.proveedor',
        'costo' => 'a.costo', 'costo_pesos' => 'costo_pesos', 'precio' => 'precio', 'margen' => 'margen_real', 'ficha' => 'margen', 'actualizacion' => 'a.actualizacion'];

    /** @param array<string,string> $f @return array{0:string,1:array<int,mixed>} */
    private static function filtro(array $f, string $costoPesos, string $precio): array
    {
        $w = [];
        $p = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $w[] = '(a.codigo LIKE ? OR a.descripcion LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($p, $like, $like);
        }
        foreach (['rubro' => 'a.rubro', 'subrubro' => 'a.subrubro'] as $k => $col) {
            if (trim((string) ($f[$k] ?? '')) !== '') {
                $w[] = "{$col} = ?";
                $p[] = trim((string) $f[$k]);
            }
        }
        if (ctype_digit((string) ($f['proveedor'] ?? ''))) {
            $w[] = 'a.proveedor = ?';
            $p[] = (int) $f['proveedor'];
        }
        if (in_array($f['moneda'] ?? '', ['$', 'u$s'], true)) {
            $w[] = 'a.moneda = ?';
            $p[] = $f['moneda'];
        }
        $w[] = ($f['bajas'] ?? '') === '1' ? '1 = 1' : "a.estado <> 'BA'";
        switch ($f['ver'] ?? '') {
            case 'sin_costo':
                $w[] = 'a.costo = 0';
                break;
            case 'bajo_costo':
                $w[] = "a.costo > 0 AND {$precio} < {$costoPesos}";
                break;
            case 'margen_menor':
                $w[] = "a.costo > 0 AND ({$precio} - {$costoPesos}) / {$costoPesos} * 100 < ?";
                $p[] = (float) str_replace(',', '.', (string) ($f['margen'] ?? '0'));
                break;
        }
        return [' WHERE ' . implode(' AND ', $w), $p];
    }

    /**
     * @param array<string,string> $f q, rubro, subrubro, proveedor, moneda, bajas, ver (sin_costo|bajo_costo|margen_menor), margen, orden
     * @return array{filas:array<int,array<string,mixed>>,total:int,cotizacion:float,fecha_cotizacion:?string,con_iva:bool}
     */
    public static function listar(array $f, int $pagina, int $porPagina): array
    {
        $pdo = Db::pdo();
        $conIva = self::preciosConIva($f);
        [$cot, $fechaCot] = Costos::cotizacionConFecha($pdo, date('Y-m-d'));
        $costo = Costos::sqlEnPesos('a', $cot);
        $lista = "(IF(a.precio_unitario > 0, a.precio_unitario, a.precio_de_venta_1) * IF(a.moneda = 'u\$s', " . number_format($cot, 4, '.', '') . ', 1))';
        $precio = $conIva ? "IF(a.grabado = 1 AND COALESCE(t.porcentaje, 0) > 0, {$lista} / (1 + t.porcentaje / 100), {$lista})" : $lista;
        $from = ' FROM articulos a LEFT JOIN tasas_iva t ON t.codigo = a.alicuota LEFT JOIN rubros ru ON ru.codigo = a.rubro';
        [$where, $args] = self::filtro($f, $costo, $precio);
        $st = $pdo->prepare('SELECT COUNT(*)' . $from . $where);
        $st->execute($args);
        $total = (int) $st->fetchColumn();
        $desde = max(0, ($pagina - 1) * $porPagina);
        $st = $pdo->prepare("SELECT a.codigo, a.descripcion, a.rubro, ru.descripcion AS rubro_desc, a.subrubro, a.proveedor, a.moneda, a.costo, {$costo} AS costo_pesos,
                {$precio} AS precio, IF(a.costo > 0, ({$precio} - {$costo}) / {$costo} * 100, NULL) AS margen_real,
                COALESCE(a.margen_l1, ru.margen_l1, (SELECT p.margen_l1 FROM parametros p WHERE p.id = 1), 0) AS margen, a.estado, a.actualizacion"
            . $from . $where . ' ORDER BY ' . Orden::sql(self::ORDEN, (string) ($f['orden'] ?? ''), 'a.codigo') . " LIMIT {$porPagina} OFFSET {$desde}");
        $st->execute($args);
        return ['filas' => $st->fetchAll(\PDO::FETCH_ASSOC), 'total' => $total, 'cotizacion' => $cot, 'fecha_cotizacion' => $fechaCot, 'con_iva' => $conIva];
    }

    /** ¿Los precios de la ficha incluyen IVA? Lo que elija el usuario; por omisión, lo de Parámetros → Precios (D-131; el monotributo y el exento siempre finales). */
    public static function preciosConIva(array $f): bool
    {
        if (in_array($f['precios'] ?? '', ['neto', 'iva'], true)) {
            return $f['precios'] === 'iva';
        }
        return (new \App\Modules\Comprobantes\ComprobantesService())->emisor()['condicion'] !== 'RI'
            || (int) Db::pdo()->query('SELECT precios_con_iva FROM parametros WHERE id = 1')->fetchColumn() === 1;
    }
}
