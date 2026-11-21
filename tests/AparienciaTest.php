<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Csrf;
use App\Core\Db;
use App\Core\Session;
use App\Http\Request;
use App\Modules\Configuracion\Apariencia;
use App\Modules\Configuracion\Configuracion;
use App\Modules\Configuracion\Logo;

/** Apariencia de la instalación (0.31.0): logo, color y nombre del cliente en todo el sistema. */
final class AparienciaTest extends DbTestCase
{
    /** @var string[] */
    private array $archivos = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->limpiar();
    }

    protected function tearDown(): void
    {
        $this->limpiar();
        foreach ($this->archivos as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function limpiar(): void
    {
        Db::pdo()->exec("DELETE FROM imagenes WHERE clave IN ('logo', 'logo_web', 'logo_pantalla', 'logo_icono')");
        Db::pdo()->exec("UPDATE configuracion SET valor = '' WHERE clave IN ('apariencia.color', 'apariencia.nombre')");
        Db::pdo()->exec("UPDATE configuracion SET valor = '1' WHERE clave = 'apariencia.logo_en_pantalla'");
        Db::pdo()->exec("UPDATE configuracion SET valor = '0' WHERE clave = 'apariencia.logo_placa'");
        Db::pdo()->exec("DELETE FROM configuracion WHERE clave = 'apariencia.menu'");
        Configuracion::reset();
        Apariencia::reset();
        Logo::reset();
    }

    private function png(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, (int) imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledrectangle($im, 5, 5, (int) ($w / 2), (int) ($h / 2), (int) imagecolorallocate($im, 200, 30, 30));
        $f = (string) tempnam(sys_get_temp_dir(), 'ap');
        imagepng($im, $f);
        imagedestroy($im);
        return $this->archivos[] = $f;
    }

    private function subir(string $ruta, string $archivo): \App\Http\Response
    {
        $files = ['logo' => ['name' => 'logo.png', 'type' => 'image/png', 'tmp_name' => $archivo, 'error' => UPLOAD_ERR_OK, 'size' => filesize($archivo)]];
        return $this->app->handle(Request::make('POST', $ruta, [Csrf::CAMPO => Csrf::token()], [], $files));
    }

    public function testSinConfigurarSeVeComoSiempre(): void
    {
        $this->loginComo($this->crearUsuario('t_adm_ap', 'admin'));
        $html = $this->get('/')->body;
        $this->assertStringContainsString('<span>Factura<b>Web</b></span>', $html);
        $this->assertStringNotContainsString('--on-brand', $html, 'sin color propio no se agrega estilo');
        $this->assertStringNotContainsString('rel="icon"', $html);
        $this->assertStringContainsString('class="pie wrap"', $html, 'O&S firma al pie');
        $this->assertFalse(Apariencia::personalizada());
        $this->assertSame(404, $this->get('/marca/logo')->status);
    }

    public function testSoloQuienAdministraLaConfiguracionCambiaLaApariencia(): void
    {
        $this->loginComo($this->crearUsuario('t_op_ap', 'operador'));
        $this->assertSame(403, $this->post('/configuracion/apariencia', ['nombre' => 'Otro', 'color_propio' => '1', 'color' => '#ff0000'])->status);
        $this->assertSame(403, $this->subir('/configuracion/apariencia/logo', $this->png(100, 40))->status);
        $this->assertSame(403, $this->post('/configuracion/apariencia/logo/quitar')->status);
        Configuracion::reset();
        Apariencia::reset();
        $this->assertSame('', Apariencia::nombre());
        $this->assertNull(Apariencia::color());

        $this->loginComo($this->crearUsuario('t_adm_ap', 'admin'));
        $sinCsrf = $this->post('/configuracion/apariencia', ['nombre' => 'Otro'], false);
        $this->assertTrue($sinCsrf->status !== 302, 'sin CSRF no se guarda');
        Configuracion::reset();
        $this->assertSame('', Apariencia::nombre());
    }

    public function testNombreYColorSeVenEnTodoElSistemaYEnElIngreso(): void
    {
        $this->loginComo($this->crearUsuario('t_adm_ap', 'admin'));
        $this->assertRedirect('/configuracion#apariencia', $this->post('/configuracion/apariencia', [
            'nombre' => '  Gogolinas   <b>Moda</b> ', 'color_propio' => '1', 'color' => '#E91E63', 'logo_en_pantalla' => '1',
        ]));
        Configuracion::reset();
        Apariencia::reset();
        $this->assertSame('Gogolinas <b>Moda</b>', Apariencia::nombre());
        $this->assertSame('#e91e63', Apariencia::color());

        $html = $this->get('/clientes')->body;
        $this->assertStringContainsString('<span class="brand-nombre">Gogolinas &lt;b&gt;Moda&lt;/b&gt;</span>', $html, 'el nombre va escapado');
        $this->assertStringContainsString('- Gogolinas &lt;b&gt;Moda&lt;/b&gt;</title>', $html);
        $this->assertStringContainsString('--brand:#e91e63;', $html);
        $this->assertStringContainsString('<meta name="theme-color" content="#e91e63">', $html);
        $this->assertStringNotContainsString('<span>Factura<b>Web</b></span>', $html);
        $this->assertStringContainsString('Factura<b>Web</b> · Organización', $html, 'el pie sigue firmando O&S');

        Session::clear();
        \App\Modules\Auth\Auth::reset();
        $login = $this->get('/login')->body;
        $this->assertStringContainsString('auth-cliente">Gogolinas', $login);
        $this->assertStringContainsString('Factura<b>Web</b> · Organización', $login);
        $this->assertStringContainsString('--brand:#e91e63;', $login);

        // Sin «color propio» vuelve el azul; un color inválido no se guarda
        $this->loginComo($this->crearUsuario('t_adm_ap2', 'admin'));
        $mal = $this->post('/configuracion/apariencia', ['nombre' => 'X', 'color_propio' => '1', 'color' => 'red;}body{display:none']);
        $this->assertSame(422, $mal->status);
        $this->assertStringContainsString('Elegí un color válido', $mal->body);
        Configuracion::reset();
        $this->assertSame('#e91e63', Apariencia::color());
        $largo = $this->post('/configuracion/apariencia', ['nombre' => str_repeat('a', 61)]);
        $this->assertSame(422, $largo->status);
        $this->assertRedirect('/configuracion#apariencia', $this->post('/configuracion/apariencia', ['nombre' => '', 'logo_en_pantalla' => '1']));
        Configuracion::reset();
        Apariencia::reset();
        $this->assertNull(Apariencia::color());
        $this->assertFalse(Apariencia::personalizada());
    }

    public function testElColorSiempreSeLee(): void
    {
        // Amarillo: texto de botón oscuro y enlaces oscurecidos sobre fondo blanco
        Db::pdo()->exec("UPDATE configuracion SET valor = '#ffeb3b' WHERE clave = 'apariencia.color'");
        Configuracion::reset();
        $css = Apariencia::estilo();
        $this->assertStringContainsString('--on-brand:#111111', $css);
        preg_match('/data-tema="claro"\]\{--brand:#ffeb3b;--brand-2:(#[0-9a-f]{6})/', $css, $m);
        $this->assertTrue(isset($m[1]) && Apariencia::contraste($m[1], '#ffffff') >= 4.5, 'el enlace se lee sobre blanco');
        // Azul oscuro: texto blanco y enlaces aclarados sobre el fondo oscuro
        Db::pdo()->exec("UPDATE configuracion SET valor = '#0d1b4c' WHERE clave = 'apariencia.color'");
        Configuracion::reset();
        $css = Apariencia::estilo();
        $this->assertStringContainsString('--on-brand:#ffffff', $css);
        preg_match('/^:root\{--brand:#0d1b4c;--brand-2:(#[0-9a-f]{6})/', $css, $m);
        $this->assertTrue(isset($m[1]) && Apariencia::contraste($m[1], '#171b22') >= 4.5, 'el enlace se lee sobre el fondo oscuro');
        // Lo que no es un color no llega nunca al estilo
        Db::pdo()->exec("UPDATE configuracion SET valor = 'red}</style><script>' WHERE clave = 'apariencia.color'");
        Configuracion::reset();
        $this->assertSame('', Apariencia::estilo());
        $this->assertSame('#aabbcc', Apariencia::normalizarColor('ABC'));
    }

    public function testElLogoDeLosComprobantesSeVeArribaConTransparenciaYDaElIcono(): void
    {
        $this->loginComo($this->crearUsuario('t_adm_ap', 'admin'));
        $this->assertRedirect('/configuracion#logo', $this->subir('/configuracion/logo', $this->png(1200, 300)));
        $url = Apariencia::urlLogo();
        $this->assertNotNull($url);
        $html = $this->get('/')->body;
        $this->assertStringContainsString('<img class="brand-logo" src="' . e((string) $url) . '"', $html);
        $this->assertStringContainsString('rel="icon" type="image/png"', $html);
        $this->assertStringNotContainsString('<span>Factura<b>Web</b></span>', $html, 'con logo y sin nombre va solo el logo');

        // Se sirve sin sesión (pantalla de ingreso) y en PNG con lo transparente
        Session::clear();
        \App\Modules\Auth\Auth::reset();
        $img = $this->get('/marca/logo', ['v' => '1']);
        $this->assertSame(200, $img->status);
        $this->assertSame('image/png', $img->headers['Content-Type']);
        $this->assertStringContainsString('immutable', $img->headers['Cache-Control']);
        $gd = imagecreatefromstring($img->body);
        $this->assertSame([600, 150], [imagesx($gd), imagesy($gd)], 'entra en 600 x 200');
        $this->assertSame(127, (imagecolorat($gd, 599, 149) >> 24) & 0x7F, 'la esquina sigue transparente');
        $ico = $this->get('/marca/icono');
        $this->assertSame(200, $ico->status);
        $gi = imagecreatefromstring($ico->body);
        $this->assertSame([64, 64], [imagesx($gi), imagesy($gi)]);
        $this->assertStringContainsString('<img src="', $this->get('/login')->body);
        // El comprobante sigue con su JPEG de fondo blanco
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring((string) Logo::obtener()['datos'])[2]);

        // Se puede ocultar de la pantalla sin quitarlo de los comprobantes
        $this->loginComo($this->crearUsuario('t_adm_ap2', 'admin'));
        $this->post('/configuracion/apariencia', ['nombre' => '']);
        Configuracion::reset();
        Apariencia::reset();
        $this->assertNull(Apariencia::urlLogo());
        $this->assertNotNull(Logo::obtener());
        $this->assertStringContainsString('<span>Factura<b>Web</b></span>', $this->get('/')->body);
    }

    public function testLogoPropioDeLaPantallaYQuitarlo(): void
    {
        $this->loginComo($this->crearUsuario('t_adm_ap', 'admin'));
        $this->assertNull(Logo::guardar($this->png(300, 100), null));
        $this->assertSame(Apariencia::IMG_WEB, Apariencia::claveLogo());
        $this->assertRedirect('/configuracion#apariencia', $this->subir('/configuracion/apariencia/logo', $this->png(100, 100)));
        Apariencia::reset();
        $this->assertSame(Apariencia::IMG_PANTALLA, Apariencia::claveLogo());
        $this->assertTrue(Apariencia::tieneLogoPantalla());
        $this->assertSame([100, 100], array_slice(getimagesizefromstring((string) Apariencia::datos('logo_pantalla')['datos']), 0, 2));
        $this->assertStringContainsString('Quitar logo de la pantalla', $this->get('/configuracion')->body);

        $this->assertRedirect('/configuracion#apariencia', $this->post('/configuracion/apariencia/logo/quitar'));
        Apariencia::reset();
        $this->assertSame(Apariencia::IMG_WEB, Apariencia::claveLogo(), 'vuelve el de los comprobantes');

        // Quitar el logo de los comprobantes lo saca también de la pantalla y del ícono
        $this->post('/configuracion/logo/quitar');
        Apariencia::reset();
        $this->assertNull(Apariencia::claveLogo());
        $this->assertNull(Apariencia::datos(Apariencia::IMG_ICONO));
        $this->assertSame(404, $this->get('/marca/icono')->status);

        $f = (string) tempnam(sys_get_temp_dir(), 'ap');
        file_put_contents($f, "<?php echo 'hola';");
        $this->archivos[] = $f;
        $this->assertSame('El archivo no es una imagen PNG, JPG, GIF o WEBP.', Apariencia::guardarLogoPantalla($f, null));
    }

    public function testLogoCargadoAntesDeLaVersionSeSigueViendo(): void
    {
        // Instalación con logo de antes de la 0.31.0: solo el JPEG, sin copia PNG ni ícono
        $this->assertNull(Logo::guardar($this->png(300, 100), null));
        Db::pdo()->exec("DELETE FROM imagenes WHERE clave IN ('logo_web', 'logo_icono')");
        Apariencia::reset();
        $this->assertSame('logo', Apariencia::claveLogo());
        $this->assertSame('image/jpeg', $this->get('/marca/logo')->headers['Content-Type']);
        $this->assertSame(200, $this->get('/marca/icono')->status, 'el ícono se hace la primera vez');
        $this->assertNotNull(Apariencia::datos(Apariencia::IMG_ICONO));
    }

    public function testElPortalMuestraLaMarca(): void
    {
        Db::pdo()->exec("UPDATE configuracion SET valor = 'Mi Negocio' WHERE clave = 'apariencia.nombre'");
        Db::pdo()->exec("UPDATE configuracion SET valor = '#2e7d32' WHERE clave = 'apariencia.color'");
        Configuracion::reset();
        $html = \App\Core\View::render('portal/ingresar', ['empresa' => 'Razón SA', 'error' => null, 'mail' => '', 'enviado' => false, 'mailListo' => false, 'title' => 'Mis comprobantes'], 'portal/layout');
        $this->assertStringContainsString('<span class="brand-nombre">Mi Negocio</span>', $html);
        $this->assertStringContainsString('--brand:#2e7d32;', $html);
    }

    public function testLaListaGeneralDeConfiguracionNoMuestraLasClavesDeApariencia(): void
    {
        foreach (Configuracion::listar() as $p) {
            $this->assertFalse(str_starts_with((string) $p['clave'], 'apariencia.'), $p['clave']);
        }
        // y el formulario general no las cambia
        $r = Configuracion::guardar(['apariencia.color' => '#000000'], null);
        $this->assertSame(0, $r['cambiados']);
    }

    public function testMenuHorizontalOLateralParaTodaLaInstalacion(): void
    {
        $this->loginComo($this->crearUsuario('t_adm_menu', 'admin'));
        $this->assertSame('horizontal', Apariencia::menu(), 'sin elegir: horizontal, como siempre');
        $this->assertStringContainsString('<body class="menu-horizontal">', $this->get('/')->body);

        $r = $this->post('/configuracion/apariencia', ['nombre' => '', 'logo_en_pantalla' => '1', 'menu' => 'lateral']);
        $this->assertSame(302, $r->status);
        $this->assertSame('lateral', Apariencia::menu());
        $this->assertStringContainsString('<body class="menu-lateral">', $this->get('/')->body);
        $this->assertStringContainsString('name="menu" value="lateral" checked', $this->get('/configuracion')->body);

        // vale para todos los usuarios de la instalación
        $this->loginComo($this->crearUsuario('t_op_menu', 'operador'));
        $this->assertStringContainsString('<body class="menu-lateral">', $this->get('/')->body);
        // y quien no administra la configuración no lo cambia
        $this->assertSame(403, $this->post('/configuracion/apariencia', ['menu' => 'horizontal'])->status);
        $this->assertSame('lateral', Apariencia::menu());

        // un valor inventado no se graba; guardar sin el campo (formulario viejo) conserva lo elegido
        $this->loginComo($this->crearUsuario('t_adm_menu2', 'admin'));
        $this->assertTrue($this->post('/configuracion/apariencia', ['menu' => 'flotante'])->status !== 302);
        $this->assertSame('lateral', Apariencia::menu());
        $this->post('/configuracion/apariencia', ['nombre' => '', 'logo_en_pantalla' => '1']);
        $this->assertSame('lateral', Apariencia::menu());
    }

    public function testSinSesionElIngresoNoLlevaMenuLateral(): void
    {
        Db::pdo()->exec("INSERT INTO configuracion (clave, valor, tipo, descripcion) VALUES ('apariencia.menu', 'lateral', 'texto', 'menú') ON DUPLICATE KEY UPDATE valor = 'lateral'");
        Configuracion::reset();
        $this->assertStringNotContainsString('menu-lateral', $this->get('/login')->body);
    }

    public function testElMenuLlevaIconosEnGruposYOpciones(): void
    {
        $this->loginComo($this->crearUsuario('t_adm_ico', 'admin'));
        $html = $this->get('/')->body;
        $grupos = substr_count($html, 'class="menu-grupo-btn"');
        $this->assertTrue($grupos > 0);
        $this->assertSame($grupos, substr_count($html, '<span class="menu-grupo-ico"><svg class="menu-ico"'), 'cada grupo con su ícono');
        $this->assertStringContainsString('class="menu-inicio', $html);
        $this->assertTrue(preg_match_all('#<a href="[^"]*" class="[^"]*"><svg class="menu-ico"#', $html) >= $grupos, 'las opciones también');
        // toda opción declarada tiene ícono propio o el de su grupo
        foreach (\App\Core\Menu::opciones() as $k => $o) {
            $this->assertStringContainsString('<svg class="menu-ico"', \App\Core\Menu::icono($k, $o['grupo']));
        }
    }
}
