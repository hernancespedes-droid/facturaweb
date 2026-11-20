<?php
declare(strict_types=1);

namespace Tests;

use App\Core\AutoMigracion;
use App\Core\Config;
use App\Core\Db;
use App\Core\Perfiles;
use App\Http\Request;
use App\Modules\Sistema\Instalaciones;
use PDO;

/** Actualización automática de la base, perfiles de instalación y panel de instalaciones del Master. */
final class AutoMigracionTest extends DbTestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/am_' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
        AutoMigracion::$dir = null;
        AutoMigracion::$archivoError = $this->tmp . '/error.json';
        Instalaciones::$transporte = null;
        Db::pdo()->exec('DELETE FROM instalaciones');
    }

    protected function tearDown(): void
    {
        AutoMigracion::$dir = null;
        AutoMigracion::$archivoError = null;
        Instalaciones::$transporte = null;
        Db::pdo()->exec('DELETE FROM instalaciones');
        Db::pdo()->exec("DELETE FROM schema_migrations WHERE filename LIKE '9998_%'");
        Db::pdo()->exec("DELETE FROM sistema_estado WHERE clave = 'perfil_aplicado'");
        Config::set('perfil', '');
        AutoMigracion::marcarAlDia();
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function otraConexion(): PDO
    {
        $c = (array) Config::get('db');
        return new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['name'], $c['charset']), (string) $c['user'], (string) $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function testConLaBaseAlDiaNoHaceNada(): void
    {
        AutoMigracion::marcarAlDia();
        $this->assertNull(AutoMigracion::asegurar());
        $this->assertSame(AutoMigracion::firma(), Db::pdo()->query("SELECT valor FROM sistema_estado WHERE clave = 'migraciones_firma'")->fetchColumn());
        $this->assertNull(AutoMigracion::ultimoError());
    }

    public function testDespuesDeActualizarElCodigoAplicaSoloLoPendiente(): void
    {
        // se simula que el código nuevo trae una migración que la base todavía no tiene: la firma no coincide y falta el registro
        Db::pdo()->exec("DELETE FROM schema_migrations WHERE filename = '0030_instalaciones.sql'");
        Db::pdo()->exec("UPDATE sistema_estado SET valor = 'vieja:1' WHERE clave = 'migraciones_firma'");
        $this->assertNull(AutoMigracion::asegurar());
        $this->assertSame(1, (int) Db::pdo()->query("SELECT COUNT(*) FROM schema_migrations WHERE filename = '0030_instalaciones.sql'")->fetchColumn(), 'se aplicó sola');
        $this->assertSame(AutoMigracion::firma(), Db::pdo()->query("SELECT valor FROM sistema_estado WHERE clave = 'migraciones_firma'")->fetchColumn());
        $this->assertTrue(Db::pdo()->query("SELECT valor FROM sistema_estado WHERE clave = 'migrada_en'")->fetchColumn() !== false);
    }

    public function testMientrasOtroPedidoMigraSeEsperaConUnaPaginaDeActualizando(): void
    {
        $otra = $this->otraConexion();
        $this->assertSame(1, (int) $otra->query("SELECT GET_LOCK('fw_migrar', 0)")->fetchColumn());
        try {
            Db::pdo()->exec("UPDATE sistema_estado SET valor = 'vieja:1' WHERE clave = 'migraciones_firma'");
            $r = AutoMigracion::asegurar();
            $this->assertNotNull($r);
            $this->assertSame(503, $r->status);
            $this->assertSame('5', $r->headers['Retry-After']);
            $this->assertStringContainsString('Actualizando el sistema', $r->body);
            // el chequeo de salud y la instalación no esperan: informan
            $this->assertSame(200, $this->get('/salud')->status);
            // y un pedido normal ve la página de espera
            $this->assertSame(503, $this->get('/login')->status);
        } finally {
            $otra->query("SELECT RELEASE_LOCK('fw_migrar')");
        }
        $this->assertNull(AutoMigracion::asegurar(), 'liberado el candado, migra y sigue');
    }

    public function testSiUnaMigracionFallaNoSeBloqueaElSistemaYNoSeInsiste(): void
    {
        file_put_contents($this->tmp . '/9998_rota.sql', 'ESTO NO ES SQL;');
        AutoMigracion::$dir = $this->tmp;
        $antes = Db::pdo()->query("SELECT valor FROM sistema_estado WHERE clave = 'migraciones_firma'")->fetchColumn();
        $this->assertNull(AutoMigracion::asegurar(), 'no corta el pedido');
        $e = AutoMigracion::ultimoError();
        $this->assertNotNull($e);
        $this->assertStringContainsString('9998_rota.sql', $e['mensaje']);
        $this->assertSame($antes, Db::pdo()->query("SELECT valor FROM sistema_estado WHERE clave = 'migraciones_firma'")->fetchColumn(), 'la firma no se guarda hasta que migre bien');
        $salud = json_decode($this->get('/salud')->body, true);
        $this->assertTrue($salud['migracion_error']);
        // dentro del minuto no vuelve a intentar
        file_put_contents($this->tmp . '/9998_rota.sql', 'DO 1;');
        $this->assertNull(AutoMigracion::asegurar());
        $this->assertNotNull(AutoMigracion::ultimoError(), 'sigue el aviso: todavía no reintentó');
        // pasado el minuto, con la migración arreglada, se aplica y el aviso desaparece
        file_put_contents($this->tmp . '/error.json', json_encode(['ts' => time() - 120, 'mensaje' => 'viejo']));
        $this->assertNull(AutoMigracion::asegurar());
        $this->assertNull(AutoMigracion::ultimoError());
        $this->assertSame(AutoMigracion::firma(), Db::pdo()->query("SELECT valor FROM sistema_estado WHERE clave = 'migraciones_firma'")->fetchColumn());
        AutoMigracion::$dir = null;
        $this->assertFalse(json_decode($this->get('/salud')->body, true)['migracion_error']);
    }

    public function testElSaludTraeLoQueNecesitaElPanel(): void
    {
        $s = json_decode($this->get('/salud')->body, true);
        foreach (['ok', 'version', 'cambios_de_base_pendientes', 'migracion_error', 'licencia_dias'] as $k) {
            $this->assertTrue(array_key_exists($k, $s), $k);
        }
        $this->assertSame(0, $s['cambios_de_base_pendientes']);
    }

    // ---------------------------------------------------------------- perfiles

    public function testElPerfilSeAplicaUnaSolaVez(): void
    {
        $this->assertSame(['basico', 'mostrador'], array_keys(Perfiles::PERFILES));
        $orig = Db::pdo()->query('SELECT kiosco, ticket_cambio, impresion FROM parametros WHERE id = 1')->fetch();
        $cfg = Db::pdo()->query("SELECT valor FROM configuracion WHERE clave = 'stock.controlar'")->fetchColumn();
        try {
            Config::set('perfil', 'mostrador');
            $this->assertSame('mostrador', Perfiles::aplicarSiCorresponde());
            $p = Db::pdo()->query('SELECT kiosco, ticket_cambio, impresion FROM parametros WHERE id = 1')->fetch();
            $this->assertSame([1, 1, 't80'], [(int) $p['kiosco'], (int) $p['ticket_cambio'], $p['impresion']]);
            $this->assertSame('1', Db::pdo()->query("SELECT valor FROM configuracion WHERE clave = 'stock.controlar'")->fetchColumn());
            // después el cliente cambia lo que quiera y el perfil no lo pisa
            Db::pdo()->exec("UPDATE parametros SET kiosco = 0 WHERE id = 1");
            $this->assertNull(Perfiles::aplicarSiCorresponde());
            $this->assertSame(0, (int) Db::pdo()->query('SELECT kiosco FROM parametros WHERE id = 1')->fetchColumn());
            // un perfil que no existe no hace nada
            Db::pdo()->exec("DELETE FROM sistema_estado WHERE clave = 'perfil_aplicado'");
            Config::set('perfil', 'inventado');
            $this->assertNull(Perfiles::aplicarSiCorresponde());
        } finally {
            Db::pdo()->prepare('UPDATE parametros SET kiosco = ?, ticket_cambio = ?, impresion = ? WHERE id = 1')->execute([$orig['kiosco'], $orig['ticket_cambio'], $orig['impresion']]);
            Db::pdo()->prepare("UPDATE configuracion SET valor = ? WHERE clave = 'stock.controlar'")->execute([$cfg]);
            \App\Modules\Kiosco\KioscoService::reset();
        }
    }

    // ---------------------------------------------------------------- panel de instalaciones (Master)

    public function testSoloElMasterVeYAdministraLasInstalaciones(): void
    {
        foreach (['t_inst_adm' => 'admin', 't_inst_op' => 'operador'] as $u => $rol) {
            $this->loginComo($this->crearUsuario($u, $rol));
            $this->assertSame(403, $this->get('/sistema/instalaciones')->status, $rol);
            $this->assertSame(403, $this->post('/sistema/instalaciones', ['nombre' => 'X', 'url' => 'https://x.com'])->status);
            $this->assertSame(403, $this->post('/sistema/instalaciones/revisar')->status);
        }
        $this->loginComo($this->crearUsuario('t_inst_master', 'master'));
        $this->assertSame(200, $this->get('/sistema/instalaciones')->status);
    }

    public function testAgregarRevisarYMarcarLasDesactualizadas(): void
    {
        $this->loginComo($this->crearUsuario('t_inst_m2', 'master'));
        $this->assertSame(302, $this->post('/sistema/instalaciones', ['nombre' => 'Cliente Uno', 'url' => 'https://uno.example.com/salud/', 'notas' => 'prod'])->status);
        $this->assertSame(302, $this->post('/sistema/instalaciones', ['nombre' => 'Cliente Dos', 'url' => 'https://dos.example.com'])->status);
        $this->assertSame(302, $this->post('/sistema/instalaciones', ['nombre' => 'Cliente Tres', 'url' => 'https://tres.example.com'])->status);
        $this->assertSame(422, $this->post('/sistema/instalaciones', ['nombre' => 'Repetida', 'url' => 'https://uno.example.com'])->status, 'la dirección no se repite');
        foreach (['', 'ftp://x.com', 'javascript:alert(1)', 'https://u:p@x.com', 'no es una url'] as $mala) {
            $this->assertSame(422, $this->post('/sistema/instalaciones', ['nombre' => 'Mala', 'url' => $mala])->status, $mala);
        }
        $propia = trim((string) file_get_contents(\App\App::root() . '/VERSION'));
        Instalaciones::$transporte = static function (string $url) use ($propia): array {
            return match (true) {
                str_contains($url, 'uno.') => ['status' => 200, 'body' => json_encode(['ok' => true, 'version' => $propia, 'cambios_de_base_pendientes' => 0, 'licencia_dias' => 25, 'migracion_error' => false])],
                str_contains($url, 'dos.') => ['status' => 200, 'body' => json_encode(['ok' => true, 'version' => '0.1.0', 'cambios_de_base_pendientes' => 3, 'licencia_dias' => 4, 'migracion_error' => true])],
                default => ['status' => 502, 'body' => '<html>Bad gateway</html>'],
            };
        };
        $this->assertSame(302, $this->post('/sistema/instalaciones/revisar')->status);
        $pag = $this->get('/sistema/instalaciones')->body;
        foreach (['Cliente Uno', 'Cliente Dos', 'Cliente Tres'] as $n) {
            $this->assertStringContainsString($n, $pag);
        }
        $this->assertStringContainsString('vence en 25 día(s)', $pag);
        $this->assertStringContainsString('desactualizada', $pag, 'la 0.1.0 está atrasada respecto de la más nueva');
        $this->assertStringContainsString('Falló la actualización automática', $pag);
        $this->assertStringContainsString('No contestó como FacturaWeb', $pag);
        $filas = Db::pdo()->query('SELECT nombre, ok, version, pendientes FROM instalaciones ORDER BY nombre')->fetchAll();
        $this->assertSame(['Cliente Dos', 'Cliente Tres', 'Cliente Uno'], array_column($filas, 'nombre'));
        $this->assertSame([1, $propia, 0], [(int) $filas[2]['ok'], $filas[2]['version'], (int) $filas[2]['pendientes']]);
        $this->assertSame([0, 3], [(int) $filas[1]['ok'], 3], 'la que no contestó como FacturaWeb queda marcada');
        $this->assertSame(3, (int) $filas[0]['pendientes']);
        $this->assertSame('https://uno.example.com', Db::pdo()->query("SELECT url FROM instalaciones WHERE nombre = 'Cliente Uno'")->fetchColumn(), 'se guarda la dirección base');
        // quitar
        $id = (int) Db::pdo()->query("SELECT id FROM instalaciones WHERE nombre = 'Cliente Tres'")->fetchColumn();
        $this->assertSame(302, $this->post('/sistema/instalaciones/' . $id . '/borrar')->status);
        $this->assertSame(2, (int) Db::pdo()->query('SELECT COUNT(*) FROM instalaciones')->fetchColumn());
    }

    public function testDesdeProduccionNoSeConsultanDireccionesInternas(): void
    {
        $env = Config::get('app.env');
        Config::set('app.env', 'production');
        try {
            foreach (['http://localhost', 'http://127.0.0.1', 'http://192.168.1.10', 'http://169.254.169.254', 'http://10.0.0.5'] as $u) {
                $this->assertNull(Instalaciones::normalizar($u), $u);
            }
            $this->assertSame('https://facturador.cliente.com.ar', Instalaciones::normalizar('https://FACTURADOR.cliente.com.ar/salud'));
        } finally {
            Config::set('app.env', $env);
        }
    }
}
