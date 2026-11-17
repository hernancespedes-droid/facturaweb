<?php
declare(strict_types=1);

namespace App\Core;

/**
 * "Enviar a Excel" de cualquier grilla del sistema: la misma página se pide con ?excel=grilla (y &tabla=N si hay varias); el
 * controlador la arma igual que siempre (con los mismos permisos y filtros, pero sin paginar) y acá se toma la tabla del HTML y se
 * pasa a .xlsx. Así toda grilla nueva tiene Excel sin código extra. Las columnas sin título (botones) no se exportan.
 */
final class GrillaExcel
{
    /** Pedido de Excel de una grilla (lo marca App antes de despachar). */
    public static bool $pedido = false;

    /** .xlsx con la tabla número $indice del HTML, o null si no hay tabla. */
    public static function desdeHtml(string $html, int $indice): ?string
    {
        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $tablas = [];
        foreach ($dom->getElementsByTagName('table') as $t) {
            if (self::esGrilla($t)) {
                $tablas[] = $t;
            }
        }
        $t = $tablas[$indice] ?? null;
        if ($t === null) {
            return null;
        }
        $xp = new \DOMXPath($dom);
        $titulos = [];
        foreach ($xp->query('./thead/tr[1]/th|./thead/tr[1]/td', $t) ?: [] as $th) {
            $titulos[] = self::texto($th);
        }
        $usar = array_keys(array_filter($titulos, static fn ($x) => $x !== ''));
        $filas = [];
        foreach ($xp->query('./tbody/tr|./tfoot/tr', $t) ?: [] as $tr) {
            $celdas = [];
            $col = 0;
            foreach ($xp->query('./td|./th', $tr) ?: [] as $td) {
                $span = max(1, (int) $td->getAttribute('colspan'));
                $celdas[$col] = self::valor(self::texto($td));
                $col += $span;
            }
            if ($celdas === [] || (count($celdas) === 1 && $usar !== [] && ($xp->query('./td[@colspan]', $tr)->length ?? 0) > 0 && $tr->parentNode->nodeName === 'tbody')) {
                continue; // "no hay datos" y similares
            }
            $filas[] = array_map(static fn ($i) => $celdas[$i] ?? '', $usar);
        }
        // Columnas de campos para completar (cobrar, aplicar…): en el Excel quedan vacías, se sacan
        foreach (array_keys($usar) as $k) {
            if ($filas !== [] && array_filter($filas, static fn ($f) => $f[$k] !== '') === []) {
                unset($usar[$k]);
                foreach ($filas as &$f) {
                    unset($f[$k]);
                }
                unset($f);
            }
        }
        $usar = array_values($usar);
        $filas = array_map('array_values', $filas);
        $anchos = [];
        foreach ($usar as $k => $i) {
            $max = mb_strlen($titulos[$i]);
            foreach ($filas as $f) {
                $max = max($max, mb_strlen((string) $f[$k]));
            }
            $anchos[] = min(60, max(8, $max + 2));
        }
        $x = new XlsxWriter('Datos', array_map(static fn ($i) => $titulos[$i], $usar), $anchos);
        foreach ($filas as $f) {
            $x->fila($f);
        }
        return $x->contenido();
    }

    /** Las grillas: toda tabla .table salvo las marcadas data-grilla="no" (las que tienen campos se exportan sin las columnas que quedan vacías). */
    public static function esGrilla(\DOMElement $t): bool
    {
        return str_contains(' ' . $t->getAttribute('class') . ' ', ' table ') && $t->getAttribute('data-grilla') !== 'no';
    }

    private static function texto(\DOMNode $n): string
    {
        // Sin el contenido de botones, formularios y scripts (acciones de la fila)
        foreach (['button', 'form', 'script', 'svg'] as $tag) {
            if ($n instanceof \DOMElement) {
                foreach (iterator_to_array($n->getElementsByTagName($tag)) as $x) {
                    $x->parentNode?->removeChild($x);
                }
            }
        }
        return trim(preg_replace('/\s+/u', ' ', str_replace(["\u{a0}", '▲', '▼'], ' ', $n->textContent)) ?? '');
    }

    /** Importe o número argentino (1.234,56 / $ 1.234,56 / −12) a número; lo demás queda texto (códigos con ceros adelante, fechas, CUIT). */
    public static function valor(string $s): string|float
    {
        $t = str_replace(['$', ' ', '−', '%'], ['', '', '-', ''], $s);
        if ($t === '' || preg_match('/^-?(\d{1,3}(\.\d{3})+|\d+)(,\d+)?$/', $t) !== 1) {
            return $s;
        }
        if (preg_match('/^-?0\d/', $t) === 1 && !str_contains($t, ',')) {
            return $s; // 0004, 00012345: es un código, no un número
        }
        if (preg_match('/^-?\d{8,}$/', $t) === 1) {
            return $s; // códigos de barras, CUIT sin guiones: texto
        }
        return (float) str_replace(['.', ','], ['', '.'], $t);
    }

    /** Nombre del archivo a partir del título de la página. */
    public static function nombre(string $html): string
    {
        $t = preg_match('/<title>([^<]*)<\/title>/u', $html, $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : 'grilla';
        $t = trim(explode(' - ', $t)[0]);
        $t = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $t) ?: 'grilla') ?? 'grilla', '-'));
        return ($t !== '' ? $t : 'grilla') . '-' . date('Ymd-Hi') . '.xlsx';
    }
}
