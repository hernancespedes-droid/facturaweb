<?php
declare(strict_types=1);

/*
 * Genera una imagen de prueba (PNG de 1200 x 800 con un degradé y una grilla) para subir como foto de un artículo en el recorrido de navegador.
 *   php tests/fixtures/e2e_foto.php   imprime la ruta del archivo
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$ruta = sys_get_temp_dir() . '/facturaweb_e2e_foto.png';
$img = imagecreatetruecolor(1200, 800);
for ($x = 0; $x < 1200; $x += 4) {
    imagefilledrectangle($img, $x, 0, $x + 3, 799, (int) imagecolorallocate($img, (int) ($x / 1200 * 255), 120, 255 - (int) ($x / 1200 * 255)));
}
$blanco = (int) imagecolorallocate($img, 255, 255, 255);
for ($i = 0; $i <= 1200; $i += 100) {
    imageline($img, $i, 0, $i, 799, $blanco);
}
for ($j = 0; $j <= 800; $j += 100) {
    imageline($img, 0, $j, 1199, $j, $blanco);
}
imagepng($img, $ruta);
echo $ruta;
