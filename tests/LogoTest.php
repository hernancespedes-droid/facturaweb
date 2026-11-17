<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Csrf;
use App\Core\Db;
use App\Core\Pdf;
use App\Http\Request;
use App\Modules\Configuracion\Logo;

final class LogoTest extends ComprobantesTestCase
{
    /** @var string[] */
    private array $archivos = [];

    protected function tearDown(): void
    {
        Db::pdo()->exec("DELETE FROM imagenes WHERE clave = 'logo'");
        Logo::reset();
        foreach ($this->archivos as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    /** PNG de prueba (con transparencia) en un archivo temporal. */
    private function png(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, (int) imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledrectangle($im, 10, 10, (int) ($w / 2), (int) ($h / 2), (int) imagecolorallocate($im, 200, 30, 30));
        $f = (string) tempnam(sys_get_temp_dir(), 'logo');
        imagepng($im, $f);
        imagedestroy($im);
        return $this->archivos[] = $f;
    }

    private function subir(string $archivo): \App\Http\Response
    {
        $files = ['logo' => ['name' => 'logo.png', 'type' => 'image/png', 'tmp_name' => $archivo, 'error' => UPLOAD_ERR_OK, 'size' => filesize($archivo)]];
        return $this->app->handle(Request::make('POST', '/configuracion/logo', [Csrf::CAMPO => Csrf::token()], [], $files));
    }

    public function testSoloQuienAdministraLaConfiguracionCargaElLogo(): void
    {
        $this->loginComo($this->crearUsuario('t_op_logo', 'operador'));
        $this->assertSame(403, $this->subir($this->png(100, 50))->status);
        $this->assertSame(403, $this->post('/configuracion/logo/quitar')->status);
        $this->assertNull(Logo::obtener());

        $this->loginComo($this->crearUsuario('t_adm_logo', 'admin'));
        $sinCsrf = $this->app->handle(Request::make('POST', '/configuracion/logo', [], [], ['logo' => ['tmp_name' => $this->png(10, 10), 'error' => 0]]));
        $this->assertTrue($sinCsrf->status !== 302, 'sin CSRF no se guarda');
        $this->assertNull(Logo::obtener());

        $this->assertRedirect('/configuracion#logo', $this->subir($this->png(1600, 400)));
        $l = Logo::obtener();
        $this->assertNotNull($l);
        $this->assertSame([800, 200], [$l['ancho'], $l['alto']], 'se achica a 800 de ancho manteniendo la proporción');
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($l['datos'])[2], 'se guarda como JPEG');
        $this->assertStringContainsString('Logo actual', $this->get('/configuracion')->body);

        $this->assertRedirect('/configuracion#logo', $this->post('/configuracion/logo/quitar'));
        $this->assertNull(Logo::obtener());
    }

    public function testRechazaLoQueNoEsUnaImagen(): void
    {
        $f = (string) tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($f, "<?php echo 'hola';");
        $this->archivos[] = $f;
        $this->assertSame('El archivo no es una imagen PNG, JPG, GIF o WEBP.', Logo::guardar($f, null));
        $this->assertNull(Logo::obtener());
    }

    public function testElLogoSaleEnLaImpresionYEnElPdf(): void
    {
        $this->loginComo($this->crearUsuario('t_adm_logo', 'admin'));
        $fc = $this->emitir($this->pedido());
        $this->assertSame(200, $this->get('/comprobantes/' . $fc['id'] . '/imprimir')->status);
        $this->assertStringNotContainsString('class="logo"', $this->get('/comprobantes/' . $fc['id'] . '/imprimir')->body);
        $sinLogo = $this->get('/comprobantes/' . $fc['id'] . '/pdf')->body;
        $this->assertStringNotContainsString('/Subtype /Image', $sinLogo);

        $this->assertNull(Logo::guardar($this->png(300, 100), null));
        $this->assertStringContainsString('<img class="logo" src="data:image/jpeg;base64,', $this->get('/comprobantes/' . $fc['id'] . '/imprimir')->body);
        $pdf = $this->get('/comprobantes/' . $fc['id'] . '/pdf')->body;
        $this->assertStringContainsString('/Subtype /Image', $pdf);
        $this->assertStringContainsString('/Im1 Do', gzuncompress(self::primerContenido($pdf)));
    }

    public function testElPdfIncrustaUnaSolaVezElMismoJpegYLaTablaDeReferenciasSigueBien(): void
    {
        $im = imagecreatetruecolor(40, 20);
        ob_start();
        imagejpeg($im);
        $jpeg = (string) ob_get_clean();
        $pdf = new Pdf();
        $pdf->jpeg($jpeg, 10, 10, 40, 20);
        $pdf->pagina();
        $pdf->jpeg($jpeg, 10, 10, 40, 20);
        $bin = $pdf->salida('Logo');
        $this->assertSame(1, substr_count($bin, '/Subtype /Image'));
        preg_match_all('/(\d{10}) 00000 n /', $bin, $o);
        foreach ($o[1] as $i => $off) {
            $this->assertStringStartsWith(($i + 1) . ' 0 obj', substr($bin, (int) $off, 12), 'objeto ' . ($i + 1));
        }
        try {
            $pdf->jpeg('no es una imagen', 0, 0, 1, 1);
            $this->fail('tenía que rechazar lo que no es JPEG');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }
    }

    private static function primerContenido(string $pdf): string
    {
        preg_match('/\/Filter \/FlateDecode >>\nstream\n(.*?)\nendstream/s', $pdf, $m);
        return $m[1];
    }
}
