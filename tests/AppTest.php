<?php
declare(strict_types=1);

namespace Tests;

use App\App;
use App\Core\Db;
use App\Http\Request;

final class AppTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        Db::reset();
        $this->app = App::boot();
    }

    public function testInicioPideIngresarYElLoginCargaConLayout(): void
    {
        $this->assertSame(302, $this->app->handle(Request::make('GET', '/'))->status);
        $res = $this->app->handle(Request::make('GET', '/login'));

        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('text/html', $res->headers['Content-Type']);
        $this->assertSame('no-store', $res->headers['Cache-Control']);
        $this->assertStringContainsString('<title>Ingresar - FacturaWeb</title>', $res->body);
        $this->assertStringContainsString('name="_csrf"', $res->body, 'El formulario de ingreso lleva token CSRF');
        $this->assertStringContainsString('meta name="viewport"', $res->body, 'El layout debe ser responsive');
    }

    public function testSaludConectaALaBase(): void
    {
        $res = $this->app->handle(Request::make('GET', '/salud'));
        $data = json_decode($res->body, true);

        $this->assertSame(200, $res->status);
        $this->assertTrue($data['ok']);
        $this->assertTrue($data['db']);
        $this->assertSame(trim((string) file_get_contents(App::root() . '/VERSION')), $data['version']);
    }

    public function testRutaInexistenteMuestraPaginaAmigable(): void
    {
        $res = $this->app->handle(Request::make('GET', '/no-existe'));

        $this->assertSame(404, $res->status);
        $this->assertStringContainsString('Página no encontrada', $res->body);
    }

    public function testCabecerasDeSeguridad(): void
    {
        $res = $this->app->handle(Request::make('GET', '/'));

        $this->assertSame('nosniff', $res->headers['X-Content-Type-Options']);
        $this->assertSame('DENY', $res->headers['X-Frame-Options']);
    }

    public function testEscapeDeHtml(): void
    {
        $this->assertSame('&lt;script&gt;&quot;x&quot;&lt;/script&gt;', e('<script>"x"</script>'));
    }
}
