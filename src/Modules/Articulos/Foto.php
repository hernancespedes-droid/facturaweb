<?php
declare(strict_types=1);

namespace App\Modules\Articulos;

use App\Core\Db;

/**
 * Foto de un artículo (pedido de Hernán, 18/11): se guarda en la base (tabla articulos_fotos) para que la vean todos los puestos, también en la nube.
 * Al subirla se valida que sea una imagen de verdad (JPG, PNG, GIF o WEBP), se corrige la orientación del celular (EXIF) y se normaliza a JPEG en dos tamaños:
 * una «grande» (hasta 1600 px, para el zoom) y una «chica» (hasta 360 px, para las tarjetas y las listas).
 */
final class Foto
{
    public const MAX_BYTES = 8 * 1024 * 1024;
    public const GRANDE = 1600;
    public const CHICA = 360;

    /** Guarda la foto desde un archivo subido. Devuelve el error para mostrar, o null si quedó guardada. */
    public static function guardar(string $codigo, string $archivo): ?string
    {
        if (!is_file($archivo) || filesize($archivo) === 0) {
            return 'Elegí la foto del producto.';
        }
        if (filesize($archivo) > self::MAX_BYTES) {
            return 'La foto pesa más de 8 MB. Achicala o sacala con menos calidad y probá de nuevo.';
        }
        if (!function_exists('imagecreatefromstring')) {
            return 'El hosting no tiene la extensión gd de PHP: no se pueden procesar imágenes.';
        }
        $info = @getimagesize($archivo);
        $tipos = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF];
        if (defined('IMAGETYPE_WEBP')) {
            $tipos[] = IMAGETYPE_WEBP;
        }
        if ($info === false || !in_array($info[2], $tipos, true)) {
            return 'El archivo no es una foto JPG, PNG, GIF o WEBP.';
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 60_000_000) {
            return 'La foto es demasiado grande (más de 60 megapíxeles).';
        }
        $orig = @imagecreatefromstring((string) file_get_contents($archivo));
        if ($orig === false) {
            return 'No se pudo leer la foto. Probá guardarla como JPG.';
        }
        $orig = self::orientar($orig, $archivo, (int) $info[2]);
        [$grande, $ancho, $alto] = self::reducir($orig, self::GRANDE, 84);
        [$chica] = self::reducir($orig, self::CHICA, 80);
        imagedestroy($orig);
        Db::pdo()->prepare(
            'INSERT INTO articulos_fotos (articulo, grande, chica, ancho, alto, actualizacion) VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE grande = VALUES(grande), chica = VALUES(chica), ancho = VALUES(ancho), alto = VALUES(alto), actualizacion = VALUES(actualizacion)'
        )->execute([$codigo, $grande, $chica, $ancho, $alto]);
        return null;
    }

    public static function quitar(string $codigo): void
    {
        Db::pdo()->prepare('DELETE FROM articulos_fotos WHERE articulo = ?')->execute([$codigo]);
    }

    /** El JPEG ('g' grande, 'c' chica) y cuándo se cargó, o null si el artículo no tiene foto. @return array{datos:string,version:int}|null */
    public static function obtener(string $codigo, string $tam): ?array
    {
        $col = $tam === 'g' ? 'grande' : 'chica';
        $st = Db::pdo()->prepare("SELECT {$col} AS datos, UNIX_TIMESTAMP(actualizacion) AS v FROM articulos_fotos WHERE articulo = ?");
        $st->execute([$codigo]);
        $f = $st->fetch();
        return $f === false ? null : ['datos' => (string) $f['datos'], 'version' => (int) $f['v']];
    }

    /** Dirección de la foto de un artículo; $version = la fecha de carga (así el navegador no muestra una vieja). */
    public static function url(string $codigo, string $tam, int $version): string
    {
        return url('/articulos/foto') . '?' . http_build_query(['codigo' => $codigo, 't' => $tam, 'v' => $version]);
    }

    /** Las direcciones de las fotos de varios artículos, de una sola vez. @param string[] $codigos @return array<string,array{chica:string,grande:string}> */
    public static function urls(array $codigos): array
    {
        if ($codigos === []) {
            return [];
        }
        try {
            $st = Db::pdo()->prepare('SELECT articulo, UNIX_TIMESTAMP(actualizacion) AS v FROM articulos_fotos WHERE articulo IN (' . implode(', ', array_fill(0, count($codigos), '?')) . ')');
            $st->execute(array_values($codigos));
        } catch (\PDOException) {
            return []; // antes de aplicar la migración
        }
        $out = [];
        foreach ($st->fetchAll() as $f) {
            $out[(string) $f['articulo']] = ['chica' => self::url((string) $f['articulo'], 'c', (int) $f['v']), 'grande' => self::url((string) $f['articulo'], 'g', (int) $f['v'])];
        }
        return $out;
    }

    /** Corrige la orientación de las fotos del celular (EXIF). @param \GdImage $img */
    private static function orientar(\GdImage $img, string $archivo, int $tipo): \GdImage
    {
        if ($tipo !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($archivo);
        $o = (int) ($exif['Orientation'] ?? 1);
        $angulo = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($angulo !== 0) {
            $r = imagerotate($img, $angulo, 0);
            if ($r !== false) {
                imagedestroy($img);
                return $r;
            }
        }
        return $img;
    }

    /** JPEG de hasta $max px del lado mayor sobre fondo blanco. @param \GdImage $orig @return array{0:string,1:int,2:int} */
    private static function reducir(\GdImage $orig, int $max, int $calidad): array
    {
        [$w, $h] = [imagesx($orig), imagesy($orig)];
        $escala = min(1.0, $max / max($w, $h));
        $nw = max(1, (int) round($w * $escala));
        $nh = max(1, (int) round($h * $escala));
        $lienzo = imagecreatetruecolor($nw, $nh);
        imagefill($lienzo, 0, 0, (int) imagecolorallocate($lienzo, 255, 255, 255)); // lo transparente queda blanco
        imagecopyresampled($lienzo, $orig, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagejpeg($lienzo, null, $calidad);
        $jpeg = (string) ob_get_clean();
        imagedestroy($lienzo);
        return [$jpeg, $nw, $nh];
    }
}
