<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Config;
use App\Modules\Instancias\InstanciasService;

/**
 * Panel de instalaciones de clientes. Usa una segunda base vacía, facturaweb_test_inst (crearla una vez con root:
 * CREATE DATABASE facturaweb_test_inst; GRANT ALL ON facturaweb_test_inst.* TO 'facturaweb'@'localhost'). Si no existe, la prueba
 * de alta se saltea avisando.
 */
final class InstanciasTest extends DbTestCase
{
    private const PRINCIPAL = 'panel.prueba.test';
    private const HOST = 'tcliente.prueba.test';
    private const BASE = 'facturaweb_test_inst';

    private ?string $principalPrevio = null;

    protected function setUp(): void
    {
        parent::setUp();
        $f = InstanciasService::raiz() . '/_principal.php';
        $this->principalPrevio = is_file($f) ? (string) file_get_contents($f) : null;
        $_SERVER['HTTP_HOST'] = self::PRINCIPAL;
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_HOST']);
        foreach ([self::HOST, 'tlleno.prueba.test'] as $h) {
            $this->borrar(InstanciasService::raiz() . '/' . $h);
        }
        $f = InstanciasService::raiz() . '/_principal.php';
        $this->principalPrevio === null ? @unlink($f) : file_put_contents($f, $this->principalPrevio);
        Config::set('instancia', []);
        parent::tearDown();
    }

    public function testSoloElMasterYSoloEnLaPrincipal(): void
    {
        $this->loginComo($this->crearUsuario('t_adm', 'admin'));
        $this->assertSame(403, $this->get('/instancias')->status);
        $this->assertSame(403, $this->post('/instancias/nueva', ['nombre' => 'X'])->status);
        $this->assertSame(403, $this->post('/instancias/actualizar')->status);

        $this->loginComo($this->crearUsuario('t_mas', 'master'));
        $res = $this->get('/instancias');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Nuevo cliente', $res->body);
        $this->assertSame(200, $this->get('/instancias/nueva')->status);

        // Dentro de la instalación de un cliente el panel no existe, ni para su Master.
        Config::set('instancia', ['host' => self::HOST, 'dir' => '/tmp/x']);
        $this->assertSame(403, $this->get('/instancias')->status);
        $this->assertFalse(\App\Core\Menu::visible('instancias'));
    }

    public function testValidaLosDatosAntesDeTocarLaBase(): void
    {
        $this->loginComo($this->crearUsuario('t_mas', 'master'));
        $res = $this->post('/instancias/nueva', ['nombre' => '', 'cuit' => '20111111111', 'subdominio' => 'Mal Nombre', 'db_name' => '',
            'master_usuario' => 'oys', 'master_clave' => 'corta', 'master_confirmacion' => 'corta']);
        $this->assertSame(422, $res->status);
        foreach (['La razón social es obligatoria', 'CUIT inválido', 'Subdominio de letras', 'Completá la base', 'al menos'] as $txt) {
            $this->assertStringContainsString($txt, $res->body);
        }
        // La base de la principal nunca se usa para un cliente.
        $res = $this->post('/instancias/nueva', $this->datos(['db_name' => (string) Config::get('db.name'), 'db_host' => (string) Config::get('db.host')]));
        $this->assertStringContainsString('Esa es la base de la instalación principal', $res->body);
        $this->assertFalse(is_dir(InstanciasService::raiz() . '/' . self::HOST));
    }

    public function testAltaCompletaConfiguracionPorDireccionYActualizacion(): void
    {
        $pdo = $this->baseCliente();
        if ($pdo === null) {
            echo "      (se saltea: falta la base " . self::BASE . ")\n";
            return;
        }
        $this->loginComo($this->crearUsuario('t_mas', 'master'));
        $res = $this->post('/instancias/nueva', $this->datos());
        $this->assertSame(200, $res->status, substr($res->body, 0, 3000));
        $this->assertStringContainsString('Listo: ' . self::HOST, $res->body);
        $this->assertTrue(preg_match('/contraseña provisoria <b><code>([^<]+)<\/code>/', $res->body, $m) === 1);

        // La base del cliente: estructura completa, empresa y dos usuarios (el Administrador con clave provisoria).
        $this->assertSame('Cliente de Prueba SA', $pdo->query('SELECT empresa FROM parametros WHERE id = 1')->fetchColumn());
        $this->assertSame('30712345671', (string) $pdo->query('SELECT cuit FROM parametros WHERE id = 1')->fetchColumn());
        $usuarios = $pdo->query('SELECT u.usuario, u.debe_cambiar_clave, r.codigo, u.password_hash FROM usuarios u JOIN roles r ON r.id = u.rol_id ORDER BY u.usuario')->fetchAll();
        $this->assertCount(2, $usuarios);
        $this->assertSame(['admin', 1, 'admin'], [$usuarios[0]['usuario'], (int) $usuarios[0]['debe_cambiar_clave'], $usuarios[0]['codigo']]);
        $this->assertTrue(password_verify($m[1], $usuarios[0]['password_hash']));
        $this->assertSame(['tmaster', 'master'], [$usuarios[1]['usuario'], $usuarios[1]['codigo']]);

        // config/config.php elige la configuración por la dirección del pedido.
        $_SERVER['HTTP_HOST'] = self::HOST;
        $c = require \App\App::root() . '/config/config.php';
        $this->assertSame(self::BASE, $c['db']['name']);
        $this->assertSame(InstanciasService::raiz() . '/' . self::HOST, $c['instancia']['dir']);
        $this->assertSame(true, $c['licencia']['exigir']);
        $this->assertSame('', (string) ($c['mp']['access_token'] ?? ''), 'nada de local.php pasa a un cliente');
        $_SERVER['HTTP_HOST'] = 'otro.prueba.test';
        $c = require \App\App::root() . '/config/config.php';
        $this->assertSame('otro.prueba.test', $c['instancia']['desconocida'] ?? null, 'un subdominio sin instalación no ve la principal');
        $_SERVER['HTTP_HOST'] = self::PRINCIPAL . ':443';
        $c = require \App\App::root() . '/config/config.php';
        $this->assertFalse(isset($c['instancia']), 'la principal sigue respondiendo en su dirección');
        $_SERVER['HTTP_HOST'] = self::PRINCIPAL;

        // No se puede repetir la dirección; una base con tablas no se pisa y no deja carpeta a medias.
        $this->assertStringContainsString('Ya existe una instalación', $this->post('/instancias/nueva', $this->datos())->body);
        $res = $this->post('/instancias/nueva', $this->datos(['subdominio' => 'tlleno']));
        $this->assertStringContainsString('ya tiene tablas', $res->body);
        $this->assertFalse(is_dir(InstanciasService::raiz() . '/tlleno.prueba.test'));

        // Listado, edición (la contraseña del mail se conserva si no se escribe) y actualización de todas.
        $this->assertStringContainsString('Cliente de Prueba SA', $this->get('/instancias')->body);
        $this->assertSame(200, $this->get('/instancias/editar', ['host' => self::HOST])->status);
        $this->assertSame(404, $this->get('/instancias/editar', ['host' => '../../config'])->status);
        $this->assertRedirect('/instancias', $this->post('/instancias/editar', ['host' => self::HOST, 'afip_modo' => 'simulado', 'mail_host' => 'smtp.cliente.test',
            'mail_user' => 'f@cliente.test', 'mail_pass' => 'secreta', 'mail_from' => 'f@cliente.test']));
        $this->post('/instancias/editar', ['host' => self::HOST, 'afip_modo' => 'simulado', 'licencia' => '1', 'mail_host' => 'smtp.cliente.test',
            'mail_user' => 'f@cliente.test', 'mail_pass' => '', 'mail_from' => 'f@cliente.test']);
        $c = InstanciasService::config(self::HOST);
        $this->assertSame(['simulado', 'secreta', true], [$c['afip']['modo'], $c['mail']['pass'], $c['licencia']['exigir']]);
        $this->assertSame(422, $this->post('/instancias/editar', ['host' => self::HOST, 'afip_modo' => 'produccion', 'afip_config' => ''])->status);

        $r = InstanciasService::actualizarTodas();
        $this->assertSame('al día', $r[self::HOST]);
        $this->assertSame('al día', $r['Principal']);
    }

    /** @param array<string,string> $cambios @return array<string,string> */
    private function datos(array $cambios = []): array
    {
        return $cambios + ['nombre' => 'Cliente de Prueba SA', 'cuit' => '30-71234567-1', 'iva' => '1', 'licencia' => '1', 'subdominio' => 'tcliente',
            'dominio' => 'prueba.test', 'db_host' => (string) Config::get('db.host'), 'db_port' => (string) Config::get('db.port'), 'db_name' => self::BASE,
            'db_user' => (string) Config::get('db.user'), 'db_pass' => (string) Config::get('db.pass'), 'master_usuario' => 'tmaster',
            'master_clave' => 'ClaveMaster123', 'master_confirmacion' => 'ClaveMaster123', 'admin_usuario' => 'admin'];
    }

    /** La base del cliente, vacía. Null si no existe en este entorno. */
    private function baseCliente(): ?\PDO
    {
        try {
            $pdo = InstanciasService::conectar(['host' => Config::get('db.host'), 'port' => Config::get('db.port'), 'name' => self::BASE,
                'user' => Config::get('db.user'), 'pass' => Config::get('db.pass')]);
        } catch (\PDOException) {
            return null;
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $t) {
            $pdo->exec('DROP TABLE `' . str_replace('`', '', (string) $t) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        return $pdo;
    }

    private function borrar(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
