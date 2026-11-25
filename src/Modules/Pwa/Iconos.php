<?php
declare(strict_types=1);

namespace App\Modules\Pwa;

use App\Modules\Configuracion\Apariencia;

/**
 * Íconos de la aplicación instalada (PWA): el logo del cliente sobre fondo blanco o, sin logo,
 * una hoja de factura blanca sobre el color de la marca. «maskable» deja el margen que pide Android
 * para recortar el ícono en círculo o en gota.
 */
final class Iconos
{
    /** Tamaño pedido => [lado en píxeles, maskable]. */
    public const TAMANIOS = [
        '192' => [192, false],
        '512' => [512, false],
        'maskable' => [512, true],
        'apple' => [180, true],
    ];

    /** Color de fondo sin marca propia (el azul de FacturaWeb). */
    public const COLOR_BASE = '#2f6bff';

    /** Cambia cuando cambia el logo o el color: va en la URL para que el navegador no use uno viejo. */
    public static function version(): string
    {
        return substr(md5((Apariencia::urlIcono() ?? '') . '|' . (Apariencia::color() ?? '')), 0, 10);
    }

    public static function colorFondo(): string
    {
        return Apariencia::color() ?? self::COLOR_BASE;
    }

    /** PNG del tamaño pedido, o null si el tamaño no existe o no hay GD. */
    public static function png(string $tam): ?string
    {
        if (!isset(self::TAMANIOS[$tam]) || !function_exists('imagecreatetruecolor')) {
            return null;
        }
        [$lado, $maskable] = self::TAMANIOS[$tam];
        $logo = self::logo();
        $img = $logo !== null ? self::conLogo($logo, $lado, $maskable) : self::sinLogo($lado, $maskable);
        if ($logo !== null) {
            imagedestroy($logo);
        }
        ob_start();
        imagepng($img, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($img);
        return $png;
    }

    private static function logo(): ?\GdImage
    {
        $clave = Apariencia::claveLogo();
        $datos = $clave === null ? null : Apariencia::datos($clave);
        if ($datos === null || !function_exists('imagecreatefromstring')) {
            return null;
        }
        $gd = @imagecreatefromstring($datos['datos']);
        return $gd === false ? null : $gd;
    }

    private static function conLogo(\GdImage $logo, int $lado, bool $maskable): \GdImage
    {
        $img = imagecreatetruecolor($lado, $lado);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 255, 255, 255));
        imagealphablending($img, true);
        // Zona segura: 80 % del lado en «maskable» (Android recorta) y 88 % en el común.
        $caja = (int) round($lado * ($maskable ? 0.66 : 0.84));
        [$w, $h] = [imagesx($logo), imagesy($logo)];
        $escala = min($caja / $w, $caja / $h);
        $nw = max(1, (int) round($w * $escala));
        $nh = max(1, (int) round($h * $escala));
        imagecopyresampled($img, $logo, intdiv($lado - $nw, 2), intdiv($lado - $nh, 2), 0, 0, $nw, $nh, $w, $h);
        return $img;
    }

    /** Hoja de factura blanca con renglones del color de la marca, dibujada 4 veces más grande y achicada (bordes suaves). */
    private static function sinLogo(int $lado, bool $maskable): \GdImage
    {
        $f = 4;
        $g = $lado * $f;
        $big = imagecreatetruecolor($g, $g);
        [$r, $v, $a] = sscanf(self::colorFondo(), '#%02x%02x%02x');
        $fondo = (int) imagecolorallocate($big, (int) $r, (int) $v, (int) $a);
        $blanco = (int) imagecolorallocate($big, 255, 255, 255);
        imagefill($big, 0, 0, $fondo);

        $s = $maskable ? 0.78 : 1.0; // achica el dibujo dentro de la zona segura
        $c = $g / 2;
        $p = fn (float $x): int => (int) round($c + ($x - 0.5) * $g * $s);
        // Hoja: rectángulo con el borde de abajo dentado (ticket).
        $x0 = 0.27; $x1 = 0.73; $y0 = 0.2; $y1 = 0.78;
        imagefilledrectangle($big, $p($x0), $p($y0), $p($x1), $p($y1), $blanco);
        $dientes = 5;
        $ancho = ($x1 - $x0) / $dientes;
        for ($i = 0; $i < $dientes; $i++) {
            $xa = $x0 + $i * $ancho;
            imagefilledpolygon($big, [$p($xa), $p($y1), $p($xa + $ancho), $p($y1), $p($xa + $ancho / 2), $p($y1 + 0.05)], $blanco);
        }
        // Renglones y total.
        foreach ([[0.33, 0.62, 0.32], [0.33, 0.55, 0.42], [0.33, 0.62, 0.52]] as [$xa, $xb, $y]) {
            imagefilledrectangle($big, $p($xa), $p($y), $p($xb), $p($y + 0.035), $fondo);
        }
        imagefilledrectangle($big, $p(0.48), $p(0.64), $p(0.67), $p(0.69), $fondo);

        $img = imagecreatetruecolor($lado, $lado);
        imagecopyresampled($img, $big, 0, 0, 0, 0, $lado, $lado, $g, $g);
        imagedestroy($big);
        return $img;
    }
}
