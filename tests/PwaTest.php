<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Core\Session;
use App\Modules\Auth\Auth;
use App\Modules\Configuracion\Apariencia;
use App\Modules\Configuracion\Configuracion;
use App\Modules\Configuracion\Logo;

/** Instalar el sistema como app (0.62.0): manifiesto, íconos y service worker por instalación, sin sesión. */
final class PwaTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->limpiar();
    }

    protected function tearDown(): void
    {
        $this->limpiar();
        parent::tearDown();
    }

    private function limpiar(): void
    {
        Db::pdo()->exec("DELETE FROM imagenes WHERE clave IN ('logo', 'logo_web', 'logo_pantalla', 'logo_icono')");
        Db::pdo()->exec("UPDATE configuracion SET valor = '' WHERE clave IN ('apariencia.color', 'apariencia.nombre')");
        Db::pdo()->exec("UPDATE configuracion SET valor = '1' WHERE clave = 'apariencia.logo_en_pantalla'");
        Configuracion::reset();
        Apariencia::reset();
        Session::clear();
        Auth::reset();
    }

    public function testElIngresoOfreceInstalarYLoNecesarioSeSirveSinSesion(): void
    {
        $html = $this->get('/login')->body;
        $this->assertStringContainsString('rel="manifest"', $html);
        $this->assertStringContainsString('rel="apple-touch-icon"', $html);
        $this->assertStringContainsString('data-sw="/sw.js"', $html);

        $m = $this->get('/manifest.webmanifest');
        $this->assertSame(200, $m->status);
        $this->assertStringStartsWith('application/manifest+json', $m->headers['Content-Type']);
        $j = json_decode($m->body, true);
        $this->assertSame('standalone', $j['display']);
        $this->assertSame('/', $j['start_url']);
        $this->assertSame('#2f6bff', $j['theme_color']);
        $tamanios = array_column($j['icons'], 'sizes');
        $this->assertContains('192x192', $tamanios);
        $this->assertContains('512x512', $tamanios);
        $this->assertContains('maskable', array_column($j['icons'], 'purpose'));

        $sw = $this->get('/sw.js');
        $this->assertSame(200, $sw->status);
        $this->assertStringStartsWith('application/javascript', $sw->headers['Content-Type']);
        $this->assertStringContainsString("addEventListener('fetch'", $sw->body);
        $this->assertSame(200, $this->get('/app/sin-conexion')->status);

        foreach (['192' => 192, '512' => 512, 'maskable' => 512, 'apple' => 180] as $tam => $lado) {
            $tam = (string) $tam;
            $ico = $this->get('/app/icono-' . $tam . '.png', ['v' => '1']);
            $this->assertSame(200, $ico->status, $tam);
            $this->assertSame('image/png', $ico->headers['Content-Type']);
            $gd = imagecreatefromstring($ico->body);
            $this->assertSame([$lado, $lado], [imagesx($gd), imagesy($gd)], $tam);
            // sin logo: fondo del color base en la esquina
            $this->assertSame(0x2f6bff, imagecolorat($gd, 1, 1) & 0xFFFFFF, $tam);
        }
        $this->assertSame(404, $this->get('/app/icono-999.png')->status);
    }

    public function testElManifiestoLlevaElNombreElColorYElLogoDelCliente(): void
    {
        Db::pdo()->exec("UPDATE configuracion SET valor = 'Oeste Repuestos' WHERE clave = 'apariencia.nombre'");
        Db::pdo()->exec("UPDATE configuracion SET valor = '#0d1b4c' WHERE clave = 'apariencia.color'");
        Configuracion::reset();
        $antes = \App\Modules\Pwa\Iconos::version();
        $j = json_decode($this->get('/manifest.webmanifest')->body, true);
        $this->assertSame('Oeste Repuestos', $j['name']);
        $this->assertSame('#0d1b4c', $j['theme_color']);
        $this->assertStringContainsString('content="#0d1b4c"', $this->get('/login')->body);

        // Con logo: el logo sobre blanco y otra versión en la URL (el navegador no se queda con el viejo)
        $gd = imagecreatetruecolor(300, 100);
        imagefill($gd, 0, 0, (int) imagecolorallocate($gd, 200, 0, 0));
        ob_start();
        imagepng($gd);
        $png = (string) ob_get_clean();
        Logo::grabar(Apariencia::IMG_WEB, 'image/png', $png, 300, 100, null);
        $this->assertTrue($antes !== \App\Modules\Pwa\Iconos::version(), "cambia la versión");
        $ico = imagecreatefromstring($this->get('/app/icono-512.png')->body);
        $this->assertSame(0xFFFFFF, imagecolorat($ico, 2, 2) & 0xFFFFFF, 'fondo blanco');
        $this->assertSame(200, (imagecolorat($ico, 256, 256) >> 16) & 0xFF, 'el logo en el centro');
    }
}
