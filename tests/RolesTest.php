<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;

/** Roles editables: opciones del menú y acciones por rol, marcas Administrador / Master, y "Aprueba remitos" del usuario. */
final class RolesTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->limpiarRoles();
    }

    protected function tearDown(): void
    {
        parent::tearDown(); // primero los usuarios t_* (usan los roles)
        $this->limpiarRoles();
    }

    private function limpiarRoles(): void
    {
        Db::pdo()->exec("DELETE FROM roles WHERE nombre LIKE 'T %'");
    }

    private function rolPorNombre(string $nombre): int
    {
        $st = Db::pdo()->prepare('SELECT id FROM roles WHERE nombre = ?');
        $st->execute([$nombre]);
        return (int) $st->fetchColumn();
    }

    /** @return string[] */
    private function permisosDe(int $rolId): array
    {
        $c = Db::pdo()->query("SELECT p.codigo FROM rol_permisos rp JOIN permisos p ON p.id = rp.permiso_id WHERE rp.rol_id = {$rolId} ORDER BY p.codigo")->fetchAll(\PDO::FETCH_COLUMN);
        return array_map('strval', $c);
    }

    private function asignar(int $usuarioId, int $rolId, string $empresa = 'BP'): void
    {
        Db::pdo()->prepare('INSERT INTO usuario_empresas (usuario_id, empresa_id, rol_id) VALUES (?, ?, ?)')->execute([$usuarioId, $this->empresaId($empresa), $rolId]);
    }

    public function testSoloQuienAdministraUsuariosEntraARoles(): void
    {
        $this->loginComo($this->crearUsuario('t_vend', ['BP' => 'vendedor_interno']));
        $this->assertSame(403, $this->get('/roles')->status);
        $this->assertSame(403, $this->post('/roles', ['nombre' => 'T Hack', 'es_admin' => '1'])->status);
        $this->assertSame(0, $this->rolPorNombre('T Hack'));
        $this->loginComo($this->crearUsuario('t_adm', ['BP' => 'admin']));
        $html = $this->get('/roles')->body;
        $this->assertStringContainsString('Vendedor Externo', $html);
        $this->assertStringNotContainsString('Master (O&amp;S)', $html, 'el Administrador no ve el rol Master');
        $this->assertSame(404, $this->get('/roles/' . $this->rolId('master'))->status);
    }

    public function testMenuYAccionesPorRolSeVerificanEnElServidor(): void
    {
        $adm = $this->crearUsuario('t_adm', ['BP' => 'admin']);
        $this->loginComo($adm);
        $res = $this->post('/roles', ['nombre' => 'T Mostrador', 'permisos' => ['productos.ver', 'productos.ver_costo', 'remitos.crear', 'vehiculos.editar', 'diccionario.administrar']]);
        $this->assertSame(302, $res->status, $res->body);
        $rol = $this->rolPorNombre('T Mostrador');
        // Una acción sin su opción del menú (editar vehículos sin Vehículos) no vale, y lo del Master no se puede dar.
        $this->assertSame(['productos.ver', 'productos.ver_costo', 'remitos.crear'], $this->permisosDe($rol));

        $u = $this->crearUsuario('t_most', []);
        $this->asignar($u, $rol);
        $this->loginComo($u);
        $home = $this->get('/')->body;
        $this->assertStringContainsString('/consulta', $home);
        $this->assertStringContainsString('/remitos/carrito', $home);
        $this->assertStringNotContainsString('href="/vehiculos"', $home);
        $this->assertSame(200, $this->get('/consulta')->status);
        $this->assertSame(200, $this->get('/remitos/carrito')->status);
        $this->assertSame(403, $this->get('/vehiculos')->status);
        $this->assertSame(403, $this->get('/usuarios')->status);

        // Se le saca el Carrito: deja de poder usarlo en el próximo pedido.
        $this->loginComo($adm);
        $this->post('/roles/' . $rol, ['nombre' => 'T Mostrador', 'permisos' => ['productos.ver']]);
        $this->loginComo($u);
        $this->assertSame(403, $this->get('/remitos/carrito')->status);
        $this->assertStringNotContainsString('/remitos/carrito', $this->get('/')->body);
    }

    public function testMarcasAdministradorYMaster(): void
    {
        $adm = $this->crearUsuario('t_adm', ['BP' => 'admin']);
        $this->loginComo($adm);
        // El Administrador no puede crear un rol Master.
        $this->assertSame(422, $this->post('/roles', ['nombre' => 'T Jefe', 'es_master' => '1'])->status);
        $this->assertSame(0, $this->rolPorNombre('T Jefe'));
        // "Es administrador": todo menos lo exclusivo del Master, aunque no tilde nada.
        $this->post('/roles', ['nombre' => 'T Encargado', 'es_admin' => '1']);
        $rol = $this->rolPorNombre('T Encargado');
        $todosMenosMaster = array_map('strval', Db::pdo()->query("SELECT codigo FROM permisos WHERE modulo <> 'master' ORDER BY codigo")->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame($todosMenosMaster, $this->permisosDe($rol));
        $this->assertNotContains('diccionario.administrar', $this->permisosDe($rol));
        $u = $this->crearUsuario('t_enc', []);
        $this->asignar($u, $rol);
        $this->loginComo($u);
        $this->assertSame(200, $this->get('/usuarios')->status);
        $this->assertSame(403, $this->get('/diccionario')->status);

        // Un Master sí crea un rol Master: tiene todo, incluso Diccionario y Sistema, y el Administrador no lo ve.
        $master = $this->crearUsuario('t_master', ['BP' => 'master']);
        $this->loginComo($master);
        $this->post('/roles', ['nombre' => 'T Socio', 'es_master' => '1']);
        $socio = $this->rolPorNombre('T Socio');
        $this->assertSame(1, (int) Db::pdo()->query("SELECT es_master FROM roles WHERE id = {$socio}")->fetchColumn());
        $this->assertSame(0, (int) Db::pdo()->query("SELECT asignable FROM roles WHERE id = {$socio}")->fetchColumn());
        $this->assertContains('diccionario.administrar', $this->permisosDe($socio));
        $s = $this->crearUsuario('t_socio', []);
        $this->asignar($s, $socio);
        $this->loginComo($s);
        $this->assertSame(200, $this->get('/sistema')->status);
        $this->loginComo($adm);
        $this->assertSame(404, $this->get('/roles/' . $socio)->status);
        $this->assertStringNotContainsString('T Socio', $this->get('/roles')->body);
        $this->assertStringNotContainsString('t_socio', $this->get('/usuarios')->body, 'tampoco ve a sus usuarios');
    }

    public function testNoSeDejaSinAccesoNiBorraRolesEnUso(): void
    {
        $adm = $this->crearUsuario('t_adm', ['BP' => 'admin']);
        $this->loginComo($adm);
        $admin = $this->rolId('admin');
        $res = $this->post('/roles/' . $admin, ['nombre' => 'Administrador', 'permisos' => ['productos.ver']]);
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('Es tu rol', $res->body);
        $this->assertSame(1, (int) Db::pdo()->query("SELECT es_admin FROM roles WHERE id = {$admin}")->fetchColumn());

        $this->post('/roles', ['nombre' => 'T Temporal', 'permisos' => ['productos.ver']]);
        $tmp = $this->rolPorNombre('T Temporal');
        $u = $this->crearUsuario('t_tmp', []);
        $this->asignar($u, $tmp);
        $this->post('/roles/' . $tmp . '/borrar');
        $this->assertTrue($this->rolPorNombre('T Temporal') > 0, 'con usuarios no se borra');
        Db::pdo()->exec("DELETE FROM usuario_empresas WHERE usuario_id = {$u}");
        $this->post('/roles/' . $tmp . '/borrar');
        $this->assertSame(0, $this->rolPorNombre('T Temporal'));
        $this->post('/roles/' . $this->rolId('operador') . '/borrar');
        $this->assertTrue($this->rolId('operador') > 0, 'los del sistema no se borran');
    }

    public function testMarcaApruebaRemitosEnElUsuario(): void
    {
        $adm = $this->crearUsuario('t_adm', ['BP' => 'admin']);
        $v = $this->crearUsuario('t_vend', ['BP' => 'vendedor_interno']);
        $this->loginComo($adm);
        $this->assertStringContainsString('Aprueba remitos', $this->get('/usuarios/' . $v)->body);
        $this->post('/usuarios/' . $v, ['nombre' => 'Vend', 'roles' => [$this->empresaId('BP') => $this->rolId('vendedor_interno')], 'aprueba_remitos' => '1']);
        $this->assertSame(1, (int) Db::pdo()->query("SELECT aprueba_remitos FROM usuarios WHERE id = {$v}")->fetchColumn());
        $this->post('/usuarios/' . $v, ['nombre' => 'Vend', 'roles' => [$this->empresaId('BP') => $this->rolId('vendedor_interno')], 'aprueba_remitos' => '0']);
        $this->assertSame(0, (int) Db::pdo()->query("SELECT aprueba_remitos FROM usuarios WHERE id = {$v}")->fetchColumn());
        // La propia marca no se la cambia uno mismo.
        $this->post('/usuarios/' . $adm, ['nombre' => 'Yo', 'aprueba_remitos' => '1']);
        $this->assertSame(0, (int) Db::pdo()->query("SELECT aprueba_remitos FROM usuarios WHERE id = {$adm}")->fetchColumn());
        // Alta con la marca.
        $this->post('/usuarios', ['usuario' => 't_nuevo', 'nombre' => 'Nuevo', 'roles' => [$this->empresaId('BP') => $this->rolId('vendedor_externo')], 'aprueba_remitos' => '1']);
        $this->assertSame(1, (int) Db::pdo()->query("SELECT aprueba_remitos FROM usuarios WHERE usuario = 't_nuevo'")->fetchColumn());
    }
}
