<?php
declare(strict_types=1);

namespace Tests;

use App\App;
use App\Core\Config;
use App\Core\Db;
use App\Core\Qr;
use App\Modules\Arca\Arca;

/** Pantalla Sistema → ARCA: permisos por rol, diagnóstico, y borrar pruebas (solo Master, con frase). */
final class ArcaHttpTest extends ComprobantesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $cfg = require App::root() . '/config/config.php';
        $cfg['afip'] = ['modo' => 'simulado'];
        Config::load($cfg);
        Arca::reiniciar();
        Db::pdo()->exec("DELETE FROM afip_operaciones");
        Db::pdo()->exec("UPDATE sucursales SET fac_electronica = 1 WHERE codigo = 99");
    }

    protected function tearDown(): void
    {
        Db::pdo()->exec("DELETE FROM afip_operaciones");
        Config::load(require App::root() . '/config/config.php');
        Arca::reiniciar();
        parent::tearDown();
    }

    public function testQuienNoTienePermisoNoEntra(): void
    {
        $this->loginComo($this->crearUsuario('t_op', 'operador'));
        $this->assertSame(403, $this->get('/arca')->status);
        $this->assertSame(403, $this->post('/arca/diagnostico')->status);
        $this->assertSame(403, $this->post('/arca/borrar-pruebas', ['frase' => 'BORRAR PRUEBAS'])->status);
        $this->assertSame(403, $this->post('/arca/1/recuperar')->status);
    }

    public function testAdministradorVeLaPantallaYProbaConexionPeroNoBorra(): void
    {
        $this->emitir($this->pedido());
        $this->loginComo($this->crearUsuario('t_adm', 'admin'));
        $r = $this->get('/arca');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('ARCA SIMULADO', $r->body);
        $this->assertRedirect('/arca', $this->post('/arca/diagnostico'));
        $this->assertStringContainsString('Ticket de acceso', $this->get('/arca')->body);
        $this->assertSame(403, $this->post('/arca/borrar-pruebas', ['frase' => 'BORRAR PRUEBAS'])->status);
        $this->assertSame(1, (int) Db::pdo()->query('SELECT COUNT(*) FROM subdiario')->fetchColumn());
    }

    public function testMasterBorraPruebasConLaFraseYReacomodaNumeradores(): void
    {
        $this->emitir($this->pedido());
        $this->emitir($this->pedido());
        $this->loginComo($this->crearUsuario('t_master', 'master'));
        $this->assertRedirect('/arca', $this->post('/arca/borrar-pruebas', ['frase' => 'borrar']));
        $this->assertSame(2, (int) Db::pdo()->query('SELECT COUNT(*) FROM subdiario')->fetchColumn(), 'sin la frase no borra');
        $this->assertRedirect('/arca', $this->post('/arca/borrar-pruebas', ['frase' => 'BORRAR PRUEBAS']));
        $this->assertSame(0, (int) Db::pdo()->query('SELECT COUNT(*) FROM subdiario')->fetchColumn());
        $this->assertSame(0, (int) Db::pdo()->query('SELECT COUNT(*) FROM iva')->fetchColumn());
        $this->assertSame(0, (int) $this->fila('SELECT ultima_factura_a AS n FROM sucursales WHERE codigo = 99')['n']);
    }

    public function testQrDeLaUrlDeArcaCabeEnLaVersion13(): void
    {
        $url = 'https://www.afip.gob.ar/fe/qr/?p=' . str_repeat('A', 309 - 34);
        $this->assertSame(69, count(Qr::matriz($url)), 'versión 13 = 69 módulos');
    }
}
