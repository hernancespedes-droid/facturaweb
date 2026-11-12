<?php
declare(strict_types=1);

namespace App\Modules\Articulos;

use App\Core\Db;

/**
 * Buscador de artículos para cualquier pantalla que pida un artículo (renglón de factura, stock...).
 * Con $exacto resuelve un código tal cual (interno, EAN-13, DUN-14, SKU o código de barras anterior: lo que lee la pistola);
 * sin eso, sugiere coincidencias mientras se escribe (código, SKU o cada palabra de la descripción).
 *
 * DECISIÓN D-17 (Claude, a confirmar por Hernán): al leer el DUN-14 de un bulto, la cantidad se completa con las
 * "unidades por bulto" del artículo (el precio sigue siendo por unidad). Se saca en el campo 'cantidad' de abajo.
 */
final class BuscadorArticulos
{
    /** @return array<int,array<string,mixed>> */
    public static function buscar(string $q, bool $exacto): array
    {
        $pdo = Db::pdo();
        $porBulto = null;
        if ($exacto) {
            $st = $pdo->prepare("SELECT * FROM articulos WHERE estado = 'AC' AND (codigo = ? OR ean13 = ? OR dun14 = ? OR sku = ? OR codigo_de_barras = ?)
                ORDER BY codigo = ? DESC, ean13 = ? DESC, dun14 = ? DESC LIMIT 1");
            $st->execute([mb_strtoupper($q), $q, $q, $q, $q, mb_strtoupper($q), $q, $q]);
            $filas = $st->fetchAll();
            $porBulto = $filas !== [] && $q !== '' && (string) $filas[0]['dun14'] === $q && (int) $filas[0]['unidades_por_bulto'] > 0 ? (int) $filas[0]['unidades_por_bulto'] : null;
        } elseif ($q === '') {
            $filas = [];
        } else {
            $cond = ["a.estado = 'AC'"];
            $par = [];
            $prefijo = addcslashes($q, '%_\\') . '%';
            $palabras = [];
            foreach (preg_split('/\s+/u', $q) ?: [] as $p) {
                if ($p !== '') {
                    $palabras[] = '%' . addcslashes($p, '%_\\') . '%';
                }
            }
            $palabras = array_slice($palabras, 0, 6);
            $porDesc = implode(' AND ', array_fill(0, max(1, count($palabras)), 'a.descripcion LIKE ?'));
            $cond[] = "(a.codigo LIKE ? OR a.sku LIKE ? OR a.ean13 = ? OR a.dun14 = ? OR a.codigo_de_barras = ? OR ({$porDesc}))";
            array_push($par, $prefijo, $prefijo, $q, $q, $q, ...($palabras ?: ['%' . addcslashes($q, '%_\\') . '%']));
            // primero lo que coincide exacto o por el principio del código/SKU; después por descripción
            $sql = 'SELECT a.* FROM articulos a WHERE ' . implode(' AND ', $cond)
                . ' ORDER BY (a.codigo = ? OR a.sku = ? OR a.ean13 = ? OR a.dun14 = ?) DESC, (a.codigo LIKE ? OR a.sku LIKE ?) DESC, a.descripcion LIMIT 15';
            $st = $pdo->prepare($sql);
            $st->execute([...$par, mb_strtoupper($q), $q, $q, $q, $prefijo, $prefijo]);
            $filas = $st->fetchAll();
        }
        return self::formatear($filas, $porBulto);
    }

    /** Listado para la ventana de búsqueda: artículos activos por descripción (filtrados por palabras si se escribe algo), de a 20. @return array{items:array<int,array<string,mixed>>,hay_mas:bool} */
    public static function listar(string $q, int $pagina): array
    {
        $porPagina = 20;
        $cond = ["estado = 'AC'"];
        $par = [];
        foreach (array_slice(preg_split('/\s+/u', $q) ?: [], 0, 6) as $p) {
            if ($p !== '') {
                $like = '%' . addcslashes($p, '%_\\') . '%';
                $cond[] = '(descripcion LIKE ? OR codigo LIKE ? OR sku LIKE ? OR ean13 = ? OR dun14 = ? OR codigo_de_barras = ?)';
                array_push($par, $like, $like, $like, $p, $p, $p);
            }
        }
        $st = Db::pdo()->prepare('SELECT * FROM articulos WHERE ' . implode(' AND ', $cond) . ' ORDER BY descripcion, codigo LIMIT ' . ($porPagina + 1) . ' OFFSET ' . (max(1, $pagina) - 1) * $porPagina);
        $st->execute($par);
        $filas = $st->fetchAll();
        $hayMas = count($filas) > $porPagina;
        return ['items' => self::formatear(array_slice($filas, 0, $porPagina), null), 'hay_mas' => $hayMas];
    }

    /** @param array<int,array<string,mixed>> $filas @return array<int,array<string,mixed>> */
    private static function formatear(array $filas, ?int $porBulto): array
    {
        $cot = (new \App\Modules\Comprobantes\ComprobantesService())->cotizacionDolar(date('Y-m-d'));
        $out = [];
        foreach ($filas as $a) {
            $precio = (float) $a['precio_unitario'] > 0 ? (float) $a['precio_unitario'] : (float) $a['precio_de_venta_1'];
            $dolares = $a['moneda'] === 'u$s';
            $out[] = ['codigo' => $a['codigo'], 'descripcion' => $a['descripcion'], 'alicuota' => (string) $a['alicuota'],
                'sku' => (string) $a['sku'], 'ean13' => (string) $a['ean13'], 'dun14' => (string) $a['dun14'],
                'cantidad' => $porBulto, // D-17
                'precio' => numero($dolares ? $precio * $cot : $precio, 4), 'moneda' => $a['moneda'], 'cotizacion' => $dolares ? $cot : null];
        }
        return $out;
    }
}
