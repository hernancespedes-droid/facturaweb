<?php
declare(strict_types=1);

namespace Tests;

use App\Core\AutoMigracion;
use App\Core\Config;
use App\Core\Db;
use PDO;

/** Actualización automática de la base (0.38.1): el primer pedido después de actualizar el código aplica los cambios pendientes. */
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
    }

    protected function tearDown(): void
    {
        AutoMigracion::$dir = null;
        Db::pdo()->exec("DELETE FROM schema_migrations WHERE filename LIKE '9998_%'");
        AutoMigracion::marcarAlDia();
        AutoMigracion::$archivoError = null;
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function otraConexion(?string $base = null): PDO
    {
        $c = (array) Config::get('db');
        return new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $base ?? $c['name'], $c['charset']),
            (string) $c['user'], (string) $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function firmaGuardada(): string|false
    {
        return Db::pdo()->query("SELECT valor FROM sistema_estado WHERE clave = 'migraciones_firma'")->fetchColumn();
    }

    public function testConLaBaseAlDiaNoHaceNada(): void
    {
        AutoMigracion::marcarAlDia();
        $this->assertNull(AutoMigracion::asegurar());
        $this->assertSame(AutoMigracion::firma(), $this->firmaGuardada());
        $this->assertNull(AutoMigracion::ultimoError());
        $this->assertStringContainsString(basename((string) max(glob(\App\App::root() . "/database/migrations/*.sql"))), AutoMigracion::firma(), "la firma lleva la última migración");
    }

    public function testDespuesDeActualizarElCodigoAplicaSoloLoPendiente(): void
    {
        // el código nuevo trae una migración que la base todavía no tiene: la firma no coincide y falta el registro
        file_put_contents($this->tmp . '/9998_nueva.sql', 'DO 1;');
        foreach (glob(\App\App::root() . '/database/migrations/*.sql') ?: [] as $f) {
            copy($f, $this->tmp . '/' . basename($f));
        }
        AutoMigracion::$dir = $this->tmp;
        $this->assertTrue($this->firmaGuardada() !== AutoMigracion::firma());
        // un pedido cualquiera (sin sesión) dispara la actualización
        $this->assertSame(302, $this->get('/')->status);
        $this->assertSame(1, (int) Db::pdo()->query("SELECT COUNT(*) FROM schema_migrations WHERE filename = '9998_nueva.sql'")->fetchColumn(), 'se aplicó sola');
        $this->assertSame(AutoMigracion::firma(), $this->firmaGuardada());
        $this->assertTrue(Db::pdo()->query("SELECT valor FROM sistema_estado WHERE clave = 'migrada_en'")->fetchColumn() !== false);
    }

    public function testMientrasOtroPedidoMigraSeEsperaConUnaPaginaDeActualizando(): void
    {
        $otra = $this->otraConexion();
        $candado = AutoMigracion::candado(Db::pdo());
        $this->assertSame('fw_migrar:' . Config::get('db.name'), $candado, 'un candado por base');
        $st = $otra->prepare('SELECT GET_LOCK(?, 0)');
        $st->execute([$candado]);
        $this->assertSame(1, (int) $st->fetchColumn());
        try {
            Db::pdo()->exec("UPDATE sistema_estado SET valor = 'vieja:1' WHERE clave = 'migraciones_firma'");
            $r = AutoMigracion::asegurar();
            $this->assertNotNull($r);
            $this->assertSame(503, $r->status);
            $this->assertSame('5', $r->headers['Retry-After']);
            $this->assertStringContainsString('Actualizando el sistema', $r->body);
            // el chequeo de salud no espera: informa
            $this->assertSame(200, $this->get('/salud')->status);
            // y un pedido normal ve la página de espera
            $this->assertSame(503, $this->get('/login')->status);
        } finally {
            $otra->prepare('SELECT RELEASE_LOCK(?)')->execute([$candado]);
        }
        $this->assertNull(AutoMigracion::asegurar(), 'liberado el candado, migra y sigue');
        $this->assertSame(AutoMigracion::firma(), $this->firmaGuardada());
    }

    public function testOtroClienteMigrandoNoFrenaAEsteCliente(): void
    {
        // con varios clientes en el mismo MySQL, el candado de otra base no tiene que hacer esperar a esta
        $otra = $this->otraConexion();
        $ajeno = 'fw_migrar:otra_base_de_cliente';
        $st = $otra->prepare('SELECT GET_LOCK(?, 0)');
        $st->execute([$ajeno]);
        try {
            Db::pdo()->exec("UPDATE sistema_estado SET valor = 'vieja:1' WHERE clave = 'migraciones_firma'");
            $this->assertNull(AutoMigracion::asegurar());
            $this->assertSame(AutoMigracion::firma(), $this->firmaGuardada());
        } finally {
            $otra->prepare('SELECT RELEASE_LOCK(?)')->execute([$ajeno]);
        }
    }

    public function testSiUnaMigracionFallaNoSeBloqueaElSistemaYNoSeInsiste(): void
    {
        file_put_contents($this->tmp . '/9998_rota.sql', 'ESTO NO ES SQL;');
        AutoMigracion::$dir = $this->tmp;
        $antes = $this->firmaGuardada();
        $this->assertSame(302, $this->get('/')->status, 'no corta el pedido');
        $e = AutoMigracion::ultimoError();
        $this->assertNotNull($e);
        $this->assertStringContainsString('9998_rota.sql', $e['mensaje']);
        $this->assertSame($antes, $this->firmaGuardada(), 'la firma no se guarda hasta que migre bien');
        $this->assertTrue(json_decode($this->get('/salud')->body, true)['migracion_error']);
        // dentro del minuto no vuelve a intentar
        file_put_contents($this->tmp . '/9998_rota.sql', 'DO 1;');
        $this->assertNull(AutoMigracion::asegurar());
        $this->assertNotNull(AutoMigracion::ultimoError(), 'sigue el aviso: todavía no reintentó');
        // pasado el minuto, con la migración arreglada, se aplica y el aviso desaparece
        file_put_contents($this->tmp . '/error.json', json_encode(['ts' => time() - 120, 'mensaje' => 'viejo']));
        $this->assertNull(AutoMigracion::asegurar());
        $this->assertNull(AutoMigracion::ultimoError());
        $this->assertSame(AutoMigracion::firma(), $this->firmaGuardada());
        AutoMigracion::$dir = null;
        $this->assertFalse(json_decode($this->get('/salud')->body, true)['migracion_error']);
    }

    public function testSiSeQuitaLaMigracionRotaElAvisoSeVa(): void
    {
        file_put_contents($this->tmp . '/9998_rota.sql', 'ESTO NO ES SQL;');
        foreach (glob(\App\App::root() . '/database/migrations/*.sql') ?: [] as $f) {
            copy($f, $this->tmp . '/' . basename($f));
        }
        AutoMigracion::$dir = $this->tmp;
        $this->assertNull(AutoMigracion::asegurar());
        $this->assertNotNull(AutoMigracion::ultimoError());
        // se vuelve al código anterior (sin la migración rota): la base ya estaba al día con él
        unlink($this->tmp . '/9998_rota.sql');
        $this->assertNull(AutoMigracion::asegurar());
        $this->assertNull(AutoMigracion::ultimoError());
    }

    public function testElErrorLoVeSoloElMasterYAplicarCambiosLoBorra(): void
    {
        // falló hace un momento (todavía no reintenta) y la base quedó sin marcar al día
        Db::pdo()->exec("UPDATE sistema_estado SET valor = 'vieja:1' WHERE clave = 'migraciones_firma'");
        file_put_contents($this->tmp . '/error.json', json_encode(['ts' => time(), 'mensaje' => 'Falló la migración 9998_x.sql: algo']));
        $this->loginComo($this->crearUsuario('t_adm', 'admin'));
        $this->assertStringNotContainsString('actualización automática de la base falló', $this->get('/sistema')->body);
        $this->loginComo($this->crearUsuario('t_mas', 'master'));
        $html = $this->get('/sistema')->body;
        $this->assertStringContainsString('actualización automática de la base falló', $html);
        $this->assertStringContainsString('9998_x.sql', $html);
        $this->post('/sistema/migrar');
        $this->assertNull(AutoMigracion::ultimoError(), '«Aplicar cambios» con éxito borra el aviso');
        $this->assertStringNotContainsString('actualización automática de la base falló', $this->get('/sistema')->body);
    }

    public function testBaseSinInstalarNoSeToca(): void
    {
        $c = (array) Config::get('db');
        $vacia = 'facturaweb_test_inst';
        try {
            $pdo = $this->otraConexion($vacia);
        } catch (\PDOException) {
            return; // sin la base extra (ver CLAUDE.md) no se puede probar
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $pdo->exec('DROP TABLE `' . str_replace('`', '', (string) $t) . '`'); // base de prueba descartable (la vacía también InstanciasTest)
        }
        Config::set('db', ['name' => $vacia] + $c);
        Db::reset();
        try {
            $this->assertNull(AutoMigracion::asegurar());
            $this->assertSame([], Db::pdo()->query('SHOW TABLES')->fetchAll(), 'sin schema_migrations no crea nada: eso es de /instalar');
        } finally {
            Config::set('db', $c);
            Db::reset();
        }
    }
}
