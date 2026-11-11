<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Core\Session;
use App\Modules\Auth\LoginService;
use App\Modules\Usuarios\UsuariosService;

final class UsuariosTest extends DbTestCase
{
    private function rolEn(int $usuarioId, string $empresa): ?string
    {
        $st = Db::pdo()->prepare(
            'SELECT r.codigo FROM usuario_empresas ue JOIN roles r ON r.id = ue.rol_id WHERE ue.usuario_id = ? AND ue.empresa_id = ?'
        );
        $st->execute([$usuarioId, $this->empresaId($empresa)]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public function testAltaGeneraClaveTemporalQueObligaACambiarla(): void
    {
        $admin = $this->crearUsuario('t_admin', ['BP' => 'admin', 'OP' => 'admin']);
        $this->loginComo($admin, 'BP');

        $res = $this->post('/usuarios', [
            'usuario' => 'T_Nuevo', 'nombre' => 'Nuevo Usuario', 'email' => 'nuevo@ejemplo.com',
            'roles' => [$this->empresaId('BP') => $this->rolId('operador'), $this->empresaId('OP') => '0'],
        ]);
        $this->assertSame(302, $res->status);
        $id = (int) Db::pdo()->query("SELECT id FROM usuarios WHERE usuario = 't_nuevo'")->fetchColumn();
        $this->assertTrue($id > 0, 'El usuario se guarda en minúsculas');
        $this->assertSame('operador', $this->rolEn($id, 'BP'));
        $this->assertSame(null, $this->rolEn($id, 'OP'));

        $flash = Session::takeFlash();
        $clave = null;
        foreach ($flash as $f) {
            if ($f['tipo'] === 'clave' && preg_match('/: (\S{12}) /', $f['texto'], $m)) {
                $clave = $m[1];
            }
        }
        $this->assertTrue($clave !== null, 'Se muestra la contraseña temporal una vez');
        $this->assertTrue((new LoginService())->intentar('t_nuevo', (string) $clave)['ok']);
        $this->assertSame(1, (int) Db::pdo()->query("SELECT debe_cambiar_clave FROM usuarios WHERE id = {$id}")->fetchColumn());
    }

    public function testValidacionesDelAlta(): void
    {
        $admin = $this->crearUsuario('t_admin', ['BP' => 'admin']);
        $svc = new UsuariosService($admin, false);
        $bp = $this->empresaId('BP');

        $r = $svc->crear(['usuario' => 'x', 'nombre' => '', 'email' => 'no-es-mail', 'roles' => []]);
        $this->assertFalse($r['ok']);
        $this->assertTrue(isset($r['errores']['usuario'], $r['errores']['nombre'], $r['errores']['email']));

        $r = $svc->crear(['usuario' => 't_sinrol', 'nombre' => 'Sin rol', 'roles' => [$bp => '0']]);
        $this->assertTrue(isset($r['errores']['roles']), 'Tiene que tener al menos un rol');

        $r = $svc->crear(['usuario' => 't_admin', 'nombre' => 'Repetido', 'roles' => [$bp => $this->rolId('operador')]]);
        $this->assertSame('Ese nombre de usuario ya existe.', $r['errores']['usuario'] ?? null);
    }

    public function testAdministradorNoVeNiTocaUsuariosMaster(): void
    {
        $admin = $this->crearUsuario('t_admin', ['BP' => 'admin', 'OP' => 'admin']);
        $master = $this->crearUsuario('t_master', ['BP' => 'master', 'OP' => 'master']);
        $this->loginComo($admin, 'BP');

        $lista = $this->get('/usuarios')->body;
        $this->assertFalse(str_contains($lista, 't_master'), 'El Master no aparece en la lista');
        $this->assertSame(404, $this->get('/usuarios/' . $master)->status);
        $this->assertSame(404, $this->post('/usuarios/' . $master, ['nombre' => 'Hackeado'])->status);
        $this->assertSame(404, $this->post('/usuarios/' . $master . '/activo', ['activo' => '0'])->status);
        $this->assertSame(404, $this->post('/usuarios/' . $master . '/clave')->status);
        $this->assertSame(404, $this->post('/usuarios/' . $master . '/desbloquear')->status);

        $u = Db::pdo()->query("SELECT nombre, activo FROM usuarios WHERE id = {$master}")->fetch();
        $this->assertSame('Nombre t_master', $u['nombre']);
        $this->assertSame(1, (int) $u['activo']);
        $this->assertTrue((new LoginService())->intentar('t_master', 'Prueba1234')['ok'], 'La clave del Master no cambió');
    }

    public function testAdministradorNoPuedeAsignarElRolMaster(): void
    {
        $admin = $this->crearUsuario('t_admin', ['BP' => 'admin']);
        $otro = $this->crearUsuario('t_otro', ['BP' => 'operador']);
        $this->loginComo($admin, 'BP');
        $bp = $this->empresaId('BP');
        $master = $this->rolId('master');

        $this->assertFalse(str_contains($this->get('/usuarios/nuevo')->body, 'Master (O&amp;S)'), 'El rol Master no se ofrece');

        $res = $this->post('/usuarios', ['usuario' => 't_colado', 'nombre' => 'Colado', 'roles' => [$bp => (string) $master]]);
        $this->assertSame(422, $res->status);
        $this->assertSame(0, (int) Db::pdo()->query("SELECT COUNT(*) FROM usuarios WHERE usuario = 't_colado'")->fetchColumn());

        $res = $this->post('/usuarios/' . $otro, ['nombre' => 'Otro', 'roles' => [$bp => (string) $master]]);
        $this->assertSame(422, $res->status);
        $this->assertSame('operador', $this->rolEn($otro, 'BP'));
    }

    public function testMasterVeYAsignaMaster(): void
    {
        $master = $this->crearUsuario('t_master', ['BP' => 'master']);
        $otroMaster = $this->crearUsuario('t_master2', ['OP' => 'master']);
        $otro = $this->crearUsuario('t_otro', ['BP' => 'operador']);
        $this->loginComo($master, 'BP');

        $this->assertStringContainsString('t_master2', $this->get('/usuarios')->body);
        $this->assertSame(200, $this->get('/usuarios/' . $otroMaster)->status);
        $res = $this->post('/usuarios/' . $otro, ['nombre' => 'Otro', 'roles' => [$this->empresaId('BP') => (string) $this->rolId('master')]]);
        $this->assertSame(302, $res->status);
        $this->assertSame('master', $this->rolEn($otro, 'BP'));
    }

    public function testAdministradorDeUnaEmpresaSoloManejaEsaEmpresa(): void
    {
        $adminBp = $this->crearUsuario('t_adminbp', ['BP' => 'admin']);
        $soloOp = $this->crearUsuario('t_soloop', ['OP' => 'operador']);
        $ambas = $this->crearUsuario('t_ambas', ['BP' => 'operador', 'OP' => 'vendedor_interno']);
        $this->loginComo($adminBp, 'BP');

        $lista = $this->get('/usuarios')->body;
        $this->assertFalse(str_contains($lista, 't_soloop'), 'No ve usuarios que solo están en OP');
        $this->assertStringContainsString('t_ambas', $lista);
        $this->assertSame(404, $this->get('/usuarios/' . $soloOp)->status);

        // No puede asignar roles en OP...
        $res = $this->post('/usuarios/' . $ambas, ['nombre' => 'Ambas', 'roles' => [$this->empresaId('OP') => (string) $this->rolId('admin')]]);
        $this->assertSame(422, $res->status);
        $this->assertSame('vendedor_interno', $this->rolEn($ambas, 'OP'));

        // ...y al cambiar el rol en BP, el de OP queda como estaba.
        $res = $this->post('/usuarios/' . $ambas, ['nombre' => 'Ambas', 'roles' => [$this->empresaId('BP') => (string) $this->rolId('vendedor_externo')]]);
        $this->assertSame(302, $res->status);
        $this->assertSame('vendedor_externo', $this->rolEn($ambas, 'BP'));
        $this->assertSame('vendedor_interno', $this->rolEn($ambas, 'OP'));
    }

    public function testNadieSeDesactivaNiSeCambiaSusPropiosRoles(): void
    {
        $admin = $this->crearUsuario('t_admin', ['BP' => 'admin']);
        $this->loginComo($admin, 'BP');

        $this->post('/usuarios/' . $admin . '/activo', ['activo' => '0']);
        $this->assertSame(1, (int) Db::pdo()->query("SELECT activo FROM usuarios WHERE id = {$admin}")->fetchColumn());

        $this->post('/usuarios/' . $admin, ['nombre' => 'Yo mismo', 'roles' => [$this->empresaId('BP') => '0']]);
        $this->assertSame('admin', $this->rolEn($admin, 'BP'));
        $this->assertSame('Yo mismo', Db::pdo()->query("SELECT nombre FROM usuarios WHERE id = {$admin}")->fetchColumn());
    }

    public function testDesactivarImpideIngresarYActivarLoDevuelve(): void
    {
        $admin = $this->crearUsuario('t_admin', ['BP' => 'admin']);
        $otro = $this->crearUsuario('t_otro', ['BP' => 'operador']);
        $this->loginComo($admin, 'BP');

        $this->post('/usuarios/' . $otro . '/activo', ['activo' => '0']);
        $this->assertFalse((new LoginService())->intentar('t_otro', 'Prueba1234')['ok']);
        $this->post('/usuarios/' . $otro . '/activo', ['activo' => '1']);
        $this->assertTrue((new LoginService())->intentar('t_otro', 'Prueba1234')['ok']);
    }

    public function testResetDeClaveDesbloqueaYPideCambiarla(): void
    {
        $admin = $this->crearUsuario('t_admin', ['BP' => 'admin']);
        $otro = $this->crearUsuario('t_otro', ['BP' => 'operador']);
        Db::pdo()->exec("UPDATE usuarios SET intentos_fallidos = 5, bloqueado_hasta = NOW() + INTERVAL 10 MINUTE WHERE id = {$otro}");
        $svc = new UsuariosService($admin, false);

        $clave = $svc->resetearClave($otro);
        $this->assertTrue(is_string($clave) && strlen($clave) === 12);
        $this->assertFalse((new LoginService())->intentar('t_otro', 'Prueba1234')['ok'], 'La clave vieja ya no sirve');
        $this->assertTrue((new LoginService())->intentar('t_otro', (string) $clave)['ok']);
        $this->assertSame(1, (int) Db::pdo()->query("SELECT debe_cambiar_clave FROM usuarios WHERE id = {$otro}")->fetchColumn());
        $this->assertSame(null, $svc->resetearClave($admin), 'La propia se cambia desde Mi contraseña');
    }

    public function testDesbloquear(): void
    {
        $admin = $this->crearUsuario('t_admin', ['BP' => 'admin']);
        $otro = $this->crearUsuario('t_otro', ['BP' => 'operador']);
        Db::pdo()->exec("UPDATE usuarios SET intentos_fallidos = 5, bloqueado_hasta = NOW() + INTERVAL 10 MINUTE WHERE id = {$otro}");
        $this->loginComo($admin, 'BP');
        $this->assertSame(302, $this->post('/usuarios/' . $otro . '/desbloquear')->status);
        $this->assertTrue((new LoginService())->intentar('t_otro', 'Prueba1234')['ok']);
    }

    public function testBuscarEscapaComodinesYDatosEnPantalla(): void
    {
        $admin = $this->crearUsuario('t_admin', ['BP' => 'admin']);
        $x = $this->crearUsuario('t_xss', ['BP' => 'operador']);
        Db::pdo()->prepare('UPDATE usuarios SET nombre = ? WHERE id = ?')->execute(['<script>alert(1)</script>', $x]);
        $this->loginComo($admin, 'BP');

        $html = $this->get('/usuarios')->body;
        $this->assertFalse(str_contains($html, '<script>alert(1)</script>'));
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertSame([], (new UsuariosService($admin, false))->listar('%'), 'El % se busca literal');
    }
}
