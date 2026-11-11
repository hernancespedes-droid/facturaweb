<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\Auth\LoginService;
use App\Modules\Usuarios\UsuariosService;

final class UsuariosTest extends DbTestCase
{
    private function rol(int $usuarioId): string
    {
        $st = Db::pdo()->prepare('SELECT r.codigo FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?');
        $st->execute([$usuarioId]);
        return (string) $st->fetchColumn();
    }

    public function testAltaGeneraClaveTemporalQueObligaACambiarla(): void
    {
        $this->loginComo($this->crearUsuario('t_admin', 'admin'));
        $res = $this->post('/usuarios', ['usuario' => 'T_Nuevo', 'nombre' => 'Nuevo', 'email' => 'Nuevo@Ejemplo.com', 'rol_id' => (string) $this->rolId('operador')]);
        $this->assertSame(302, $res->status);
        $id = (int) Db::pdo()->query("SELECT id FROM usuarios WHERE usuario = 't_nuevo'")->fetchColumn();
        $this->assertSame('operador', $this->rol($id));
        $this->assertSame('nuevo@ejemplo.com', Db::pdo()->query("SELECT email FROM usuarios WHERE id = {$id}")->fetchColumn());
        $flash = \App\Core\Session::get('_flash');
        $this->assertSame('clave', $flash[1]['tipo'] ?? null);
        preg_match('/: (\S{12}) —/', $flash[1]['texto'], $m);
        $r = (new LoginService())->intentar('t_nuevo', $m[1] ?? '');
        $this->assertTrue($r['ok'], 'la clave temporal sirve para entrar');
        $this->loginComo($id);
        $this->assertRedirect('/mi-clave', $this->get('/clientes'), 'con clave temporal solo puede cambiarla');
    }

    public function testValidacionesDelAlta(): void
    {
        $this->crearUsuario('t_admin', 'admin');
        $svc = new UsuariosService(0, false);
        $r = $svc->crear(['usuario' => 'x', 'nombre' => '', 'email' => 'no-es-mail', 'rol_id' => '']);
        $this->assertFalse($r['ok']);
        foreach (['usuario', 'nombre', 'email', 'rol_id'] as $campo) {
            $this->assertTrue(isset($r['errores'][$campo]), $campo);
        }
        $r = $svc->crear(['usuario' => 't_admin', 'nombre' => 'Repetido', 'rol_id' => (string) $this->rolId('operador')]);
        $this->assertSame('Ese nombre de usuario ya existe.', $r['errores']['usuario'] ?? null);
    }

    public function testOperadorNoEntraAUsuariosNiRoles(): void
    {
        $this->loginComo($this->crearUsuario('t_op', 'operador'));
        foreach (['/usuarios', '/usuarios/nuevo', '/roles'] as $p) {
            $this->assertSame(403, $this->get($p)->status, $p);
        }
        $this->assertSame(403, $this->post('/usuarios', ['usuario' => 't_colado', 'nombre' => 'X', 'rol_id' => (string) $this->rolId('admin')])->status);
        $this->assertSame(0, (int) Db::pdo()->query("SELECT COUNT(*) FROM usuarios WHERE usuario = 't_colado'")->fetchColumn());
    }

    public function testAdministradorNoVeNiTocaNiAsignaMaster(): void
    {
        $admin = $this->crearUsuario('t_admin', 'admin');
        $master = $this->crearUsuario('t_master', 'master');
        $otro = $this->crearUsuario('t_otro', 'operador');
        $this->loginComo($admin);
        $lista = $this->get('/usuarios')->body;
        $this->assertStringNotContainsString('t_master', $lista);
        $this->assertStringContainsString('t_otro', $lista);
        $this->assertSame(404, $this->get('/usuarios/' . $master)->status);
        $this->assertSame(404, $this->post('/usuarios/' . $master . '/activo', ['activo' => '0'])->status);
        $this->assertSame(404, $this->post('/usuarios/' . $master . '/clave')->status);

        $res = $this->post('/usuarios/' . $otro, ['nombre' => 'Otro', 'rol_id' => (string) $this->rolId('master')]);
        $this->assertSame(422, $res->status);
        $this->assertSame('operador', $this->rol($otro));
        $res = $this->post('/usuarios', ['usuario' => 't_colado', 'nombre' => 'Colado', 'rol_id' => (string) $this->rolId('master')]);
        $this->assertSame(422, $res->status);
    }

    public function testMasterVeYAsignaMaster(): void
    {
        $master = $this->crearUsuario('t_master', 'master');
        $otro = $this->crearUsuario('t_otro', 'operador');
        $this->loginComo($master);
        $this->assertSame(302, $this->post('/usuarios/' . $otro, ['nombre' => 'Otro', 'rol_id' => (string) $this->rolId('master')])->status);
        $this->assertSame('master', $this->rol($otro));
    }

    public function testNadieSeDesactivaNiSeCambiaSuPropioRol(): void
    {
        $admin = $this->crearUsuario('t_admin', 'admin');
        $this->loginComo($admin);
        $this->post('/usuarios/' . $admin . '/activo', ['activo' => '0']);
        $this->assertSame(1, (int) Db::pdo()->query("SELECT activo FROM usuarios WHERE id = {$admin}")->fetchColumn());
        $this->post('/usuarios/' . $admin, ['nombre' => 'Yo mismo', 'rol_id' => (string) $this->rolId('operador')]);
        $this->assertSame('admin', $this->rol($admin));
        $this->assertSame('Yo mismo', Db::pdo()->query("SELECT nombre FROM usuarios WHERE id = {$admin}")->fetchColumn());
    }

    public function testDesactivarImpideIngresarYResetearDesbloquea(): void
    {
        $admin = $this->crearUsuario('t_admin', 'admin');
        $otro = $this->crearUsuario('t_otro', 'operador');
        Db::pdo()->exec("UPDATE usuarios SET intentos_fallidos = 5, bloqueado_hasta = NOW() + INTERVAL 1 HOUR WHERE id = {$otro}");
        $this->loginComo($admin);
        $this->post('/usuarios/' . $otro . '/clave');
        $u = Db::pdo()->query("SELECT debe_cambiar_clave, intentos_fallidos, bloqueado_hasta FROM usuarios WHERE id = {$otro}")->fetch();
        $this->assertSame(1, (int) $u['debe_cambiar_clave']);
        $this->assertSame(0, (int) $u['intentos_fallidos']);
        $this->assertNull($u['bloqueado_hasta']);

        $this->post('/usuarios/' . $otro . '/activo', ['activo' => '0']);
        $this->assertSame(0, (int) Db::pdo()->query("SELECT activo FROM usuarios WHERE id = {$otro}")->fetchColumn());
        $this->loginComo($otro);
        $this->assertRedirect('/login', $this->get('/'), 'un usuario desactivado queda afuera en el próximo pedido');
    }

    public function testBuscarEscapaComodinesYDatosEnPantalla(): void
    {
        $admin = $this->crearUsuario('t_admin', 'admin');
        $x = $this->crearUsuario('t_xss', 'operador');
        Db::pdo()->exec("UPDATE usuarios SET nombre = '<script>alert(1)</script>' WHERE id = {$x}");
        $this->loginComo($admin);
        $body = $this->get('/usuarios')->body;
        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
        $this->assertSame([], (new UsuariosService($admin, false))->listar('%'), 'el % se busca literal');
    }
}
