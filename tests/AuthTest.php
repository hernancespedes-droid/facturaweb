<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Core\Session;
use App\Modules\Auth\Auth;
use App\Modules\Auth\LoginService;

final class AuthTest extends DbTestCase
{
    public function testLoginCorrectoReiniciaIntentosYRegistraIngreso(): void
    {
        $id = $this->crearUsuario('t_ana', 'admin');
        Db::pdo()->exec("UPDATE usuarios SET intentos_fallidos = 3 WHERE id = {$id}");

        $r = (new LoginService())->intentar('T_Ana ', 'Prueba1234');

        $this->assertTrue($r['ok']);
        $this->assertSame($id, $r['usuario_id']);
        $u = Db::pdo()->query("SELECT intentos_fallidos, ultimo_login FROM usuarios WHERE id = {$id}")->fetch();
        $this->assertSame(0, (int) $u['intentos_fallidos']);
        $this->assertTrue($u['ultimo_login'] !== null);
    }

    public function testClaveIncorrectaYUsuarioInexistenteDanElMismoMensaje(): void
    {
        $this->crearUsuario('t_ana', 'admin');
        $mal = (new LoginService())->intentar('t_ana', 'otra-clave');
        $noExiste = (new LoginService())->intentar('t_nadie', 'otra-clave');

        $this->assertFalse($mal['ok']);
        $this->assertSame($mal['error'], $noExiste['error'], 'No tiene que delatar qué usuarios existen');
    }

    public function testSeBloqueaTrasCincoIntentosYNoEntraNiConLaClaveCorrecta(): void
    {
        $id = $this->crearUsuario('t_ana', 'admin');
        $svc = new LoginService();
        for ($i = 0; $i < LoginService::MAX_INTENTOS; $i++) {
            $svc->intentar('t_ana', 'mala');
        }
        $u = Db::pdo()->query("SELECT intentos_fallidos, bloqueado_hasta > NOW() AS bloq FROM usuarios WHERE id = {$id}")->fetch();
        $this->assertSame(LoginService::MAX_INTENTOS, (int) $u['intentos_fallidos']);
        $this->assertSame(1, (int) $u['bloq']);

        $r = $svc->intentar('t_ana', 'Prueba1234');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('bloqueado', $r['error']);
    }

    public function testCuatroIntentosNoBloquean(): void
    {
        $this->crearUsuario('t_ana', 'admin');
        $svc = new LoginService();
        for ($i = 0; $i < LoginService::MAX_INTENTOS - 1; $i++) {
            $svc->intentar('t_ana', 'mala');
        }
        $this->assertTrue($svc->intentar('t_ana', 'Prueba1234')['ok']);
    }

    public function testElBloqueoVenceYElContadorArrancaDeCero(): void
    {
        $id = $this->crearUsuario('t_ana', 'admin');
        Db::pdo()->exec("UPDATE usuarios SET intentos_fallidos = 5, bloqueado_hasta = NOW() - INTERVAL 1 MINUTE WHERE id = {$id}");
        $svc = new LoginService();

        // Un fallo después de vencido no lo vuelve a bloquear de inmediato.
        $svc->intentar('t_ana', 'mala');
        $u = Db::pdo()->query("SELECT intentos_fallidos, bloqueado_hasta FROM usuarios WHERE id = {$id}")->fetch();
        $this->assertSame(1, (int) $u['intentos_fallidos']);
        $this->assertSame(null, $u['bloqueado_hasta']);
        $this->assertTrue($svc->intentar('t_ana', 'Prueba1234')['ok']);
    }

    public function testIntentosFallidosSimultaneosSeCuentanTodos(): void
    {
        // Concurrencia: varios procesos fallan a la vez; el incremento atómico no pierde ninguno.
        $id = $this->crearUsuario('t_ana', 'admin');
        $script = __DIR__ . '/fixtures/intento_fallido.php';
        $procs = [];
        for ($i = 0; $i < 4; $i++) {
            $procs[] = proc_open([PHP_BINARY, $script, 't_ana'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        }
        foreach ($procs as $p) {
            proc_close($p);
        }
        $n = (int) Db::pdo()->query("SELECT intentos_fallidos FROM usuarios WHERE id = {$id}")->fetchColumn();
        $this->assertSame(4, $n);
    }

    public function testUsuarioInactivoNoEntra(): void
    {
        $this->crearUsuario('t_ana', 'admin', 'Prueba1234', false);
        $r = (new LoginService())->intentar('t_ana', 'Prueba1234');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('desactivado', $r['error']);
    }

    public function testSinSesionTodoRedirigeAlLogin(): void
    {
        foreach (['/', '/usuarios', '/clientes', '/articulos', '/tablas/rubros', '/sistema', '/mi-clave'] as $path) {
            $this->assertRedirect('/login', $this->get($path), $path);
        }
    }

    public function testPostSinTokenCsrfSeRechaza(): void
    {
        $this->crearUsuario('t_ana', 'admin');
        $res = $this->post('/login', ['usuario' => 't_ana', 'clave' => 'Prueba1234'], false);
        $this->assertSame(403, $res->status);
        $this->assertSame(null, Session::get('uid'));

        $res = $this->post('/login', ['usuario' => 't_ana', 'clave' => 'Prueba1234', '_csrf' => 'falso'], false);
        $this->assertSame(403, $res->status);
    }

    public function testLoginVuelveALaPantallaQueSeQueriaAbrir(): void
    {
        $this->crearUsuario('t_ana', 'operador');
        $this->assertRedirect('/login', $this->get('/clientes', ['q' => 'x']));
        $this->assertRedirect('/clientes?q=x', $this->post('/login', ['usuario' => 't_ana', 'clave' => 'Prueba1234']));
    }

    public function testCambiarElRolRigeEnElProximoPedidoSinVolverAIngresar(): void
    {
        $id = $this->crearUsuario('t_ana', 'admin');
        $this->loginComo($id);
        $this->assertSame(200, $this->get('/usuarios')->status);
        Db::pdo()->prepare('UPDATE usuarios SET rol_id = ? WHERE id = ?')->execute([$this->rolId('operador'), $id]);
        Auth::reset();
        $this->assertSame(403, $this->get('/usuarios')->status);
        $this->assertSame('operador', Auth::rol()['codigo']);
    }

    public function testDesactivarRigeEnElProximoPedido(): void
    {
        $id = $this->crearUsuario('t_ana', 'admin');
        $this->loginComo($id);
        $this->assertSame(200, $this->get('/')->status);

        Db::pdo()->exec("UPDATE usuarios SET activo = 0 WHERE id = {$id}");
        $this->assertRedirect('/login', $this->get('/'));
        $this->assertSame(null, Session::get('uid'));
    }

    public function testLaSesionVencePorInactividad(): void
    {
        $id = $this->crearUsuario('t_ana', 'admin');
        $this->loginComo($id);
        Session::set('ultimo_uso', time() - 5 * 3600);
        $this->assertRedirect('/login', $this->get('/'));
    }

    public function testSalirCierraLaSesion(): void
    {
        $id = $this->crearUsuario('t_ana', 'admin');
        $this->loginComo($id);
        $this->assertRedirect('/login', $this->post('/salir'));
        $this->assertSame(null, Session::get('uid'));
        $this->assertRedirect('/login', $this->get('/'));
    }

    public function testConClaveTemporalSoloPuedeCambiarla(): void
    {
        $id = $this->crearUsuario('t_ana', 'admin');
        Db::pdo()->exec("UPDATE usuarios SET debe_cambiar_clave = 1 WHERE id = {$id}");
        $this->assertRedirect('/mi-clave', $this->post('/login', ['usuario' => 't_ana', 'clave' => 'Prueba1234']));
        $this->assertRedirect('/mi-clave', $this->get('/usuarios'));

        $res = $this->post('/mi-clave', ['actual' => 'Prueba1234', 'nueva' => 'corta', 'confirmacion' => 'corta']);
        $this->assertSame(422, $res->status);
        $res = $this->post('/mi-clave', ['actual' => 'Prueba1234', 'nueva' => 'NuevaClave99', 'confirmacion' => 'NuevaClave99']);
        $this->assertRedirect('/', $res);
        $this->assertSame(200, $this->get('/usuarios')->status);
        $this->assertTrue((new LoginService())->intentar('t_ana', 'NuevaClave99')['ok']);
    }

    public function testValidacionesDeClaveNueva(): void
    {
        $id = $this->crearUsuario('t_ana', 'admin');
        $svc = new LoginService();
        $this->assertStringContainsString('actual', (string) $svc->cambiarClave($id, 'mala', 'NuevaClave99', 'NuevaClave99'));
        $this->assertStringContainsString('coinciden', (string) $svc->cambiarClave($id, 'Prueba1234', 'NuevaClave99', 'NuevaClave98'));
        $this->assertStringContainsString('distinta', (string) $svc->cambiarClave($id, 'Prueba1234', 'Prueba1234', 'Prueba1234'));
        $this->assertStringContainsString('igual al usuario', (string) LoginService::validarClaveNueva('t_ana1234', 't_ana1234', 't_ana1234'));
        $this->assertSame(null, $svc->cambiarClave($id, 'Prueba1234', 'NuevaClave99', 'NuevaClave99'));
    }

    public function testLasClavesSeGuardanConHash(): void
    {
        $id = $this->crearUsuario('t_ana', 'admin');
        $hash = (string) Db::pdo()->query("SELECT password_hash FROM usuarios WHERE id = {$id}")->fetchColumn();
        $this->assertFalse(str_contains($hash, 'Prueba1234'));
        $this->assertTrue(password_get_info($hash)['algo'] !== null);
    }
}
