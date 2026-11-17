<?php
declare(strict_types=1);

namespace App\Modules\Configuracion;

use App\Core\Db;

/**
 * Logo de la empresa para los comprobantes impresos. Se guarda en la tabla imagenes (clave 'logo', en base64).
 * Al subirlo se valida que sea una imagen de verdad (PNG, JPEG, GIF o WEBP) y se normaliza con gd a JPEG sobre fondo
 * blanco, de 800 x 400 píxeles como máximo: así el PDF lo incrusta sin decodificar PNG y la página pesa poco.
 */
final class Logo
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_ANCHO = 800;
    public const MAX_ALTO = 400;

    /** @var array{datos:string,ancho:int,alto:int}|false|null null = sin leer; false = no hay logo */
    private static array|false|null $cache = null;

    public static function reset(): void
    {
        self::$cache = null;
    }

    /** @return array{datos:string,ancho:int,alto:int}|null el JPEG y su tamaño en píxeles */
    public static function obtener(): ?array
    {
        if (self::$cache === null) {
            try {
                $f = Db::pdo()->query("SELECT datos, ancho, alto FROM imagenes WHERE clave = 'logo'")->fetch();
                self::$cache = $f ? ['datos' => (string) base64_decode((string) $f['datos']), 'ancho' => (int) $f['ancho'], 'alto' => (int) $f['alto']] : false;
            } catch (\PDOException) {
                self::$cache = false; // antes de aplicar la migración
            }
        }
        return self::$cache === false ? null : self::$cache;
    }

    /** Para un <img src="…"> (la página imprimible no depende de otra ruta ni de la sesión). */
    public static function dataUri(): ?string
    {
        $l = self::obtener();
        return $l === null ? null : 'data:image/jpeg;base64,' . base64_encode($l['datos']);
    }

    /**
     * Guarda el logo desde un archivo subido. Devuelve el error para mostrar, o null si quedó guardado.
     */
    public static function guardar(string $archivo, ?int $usuarioId): ?string
    {
        if (!is_file($archivo) || filesize($archivo) === 0) {
            return 'Elegí la imagen del logo.';
        }
        if (filesize($archivo) > self::MAX_BYTES) {
            return 'La imagen pesa más de 2 MB. Achicala o guardala como JPG y probá de nuevo.';
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
            return 'El archivo no es una imagen PNG, JPG, GIF o WEBP.';
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 40_000_000) {
            return 'La imagen es demasiado grande (más de 40 megapíxeles).';
        }
        $orig = @imagecreatefromstring((string) file_get_contents($archivo));
        if ($orig === false) {
            return 'No se pudo leer la imagen. Probá guardarla como PNG o JPG.';
        }
        [$w, $h] = [imagesx($orig), imagesy($orig)];
        $escala = min(1.0, self::MAX_ANCHO / $w, self::MAX_ALTO / $h);
        $nw = max(1, (int) round($w * $escala));
        $nh = max(1, (int) round($h * $escala));
        $lienzo = imagecreatetruecolor($nw, $nh);
        imagefill($lienzo, 0, 0, (int) imagecolorallocate($lienzo, 255, 255, 255)); // lo transparente queda blanco
        imagecopyresampled($lienzo, $orig, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagejpeg($lienzo, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($orig);
        imagedestroy($lienzo);

        Db::pdo()->prepare(
            "INSERT INTO imagenes (clave, tipo, datos, ancho, alto, actualizado_por) VALUES ('logo', 'image/jpeg', ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE tipo = VALUES(tipo), datos = VALUES(datos), ancho = VALUES(ancho), alto = VALUES(alto), actualizado_por = VALUES(actualizado_por)"
        )->execute([base64_encode($jpeg), $nw, $nh, $usuarioId]);
        self::reset();
        return null;
    }

    public static function quitar(): void
    {
        Db::pdo()->exec("DELETE FROM imagenes WHERE clave = 'logo'");
        self::reset();
    }

    /**
     * Tamaño en milímetros para que entre en una caja de $maxW x $maxH respetando la proporción.
     * @return array{0:float,1:float}
     */
    public static function medida(float $maxW, float $maxH): array
    {
        $l = self::obtener();
        if ($l === null) {
            return [0.0, 0.0];
        }
        $escala = min($maxW / $l['ancho'], $maxH / $l['alto']);
        return [$l['ancho'] * $escala, $l['alto'] * $escala];
    }
}
