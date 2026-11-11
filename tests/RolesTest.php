<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\Auth\Auth;

final class RolesTest extends DbTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        Db::pdo()->exec("DELETE FROM roles WHERE nombre LIKE 'T %'");
    }

    /** @return string[] */
    private function permisos(int $rolId): array
    {
        $st = Db::pdo()->prepare('SELECT p.codigo FROM rol_permisos rp JOIN permisos p ON p.id = rp.permiso_id WHERE rp.rol_id = ? ORDER BY p.codigo');
        $st->execute([$rolId]);
        return $st->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testOperadorTieneMaestrosYTablasMenosSucursalesYParametros(): void
    {
        $p = $this->permisos($this->rolId('operador'));
        foreach (['clientes.ver', 'clientes.editar', 'articulos.ver', 'articulos.editar', 'tablas.rubros', 'tablas.forma_de_pagos'] as $c) {
            $this->assertContains($c, $p);
        }
        foreach (['tablas.sucursales', 'tablas.parametros', 'usuarios.administrar', 'configuracion.administrar'] as $c) {
            $this->assertNotContains($c, $p);
        }
        $todos = (int) Db::pdo()->query('SELECT COUNT(*) FROM permisos')->fetchColumn();
        $this->assertCount($todos, $this->permisos($this->rolId('admin')));
        $this->assertCount($todos, $this->permisos($this->rolId('master')));
    }

    public function testRolNuevoSoloConLoTildadoYSeVerificaEnElServidor(): void
    {
        $this->loginComo($this->crearUsuario('t_adm', 'admin'));
        // "Editar clientes" sin "Ver clientes" no vale.
        $res = $this->post('/roles', ['nombre' => 'T Consulta', 'permisos' => ['articulos.ver', 'clientes.editar', 'tablas.bancos']]);
        $this->assertSame(302, $res->status);
        $rol = (int) Db::pdo()->query("SELECT id FROM roles WHERE nombre = 'T Consulta'")->fetchColumn();
        $this->assertSame(['articulos.ver', 'tablas.bancos'], $this->permisos($rol));

        $u = $this->crearUsuario('t_cons', 'operador');
        Db::pdo()->exec("UPDATE usuarios SET rol_id = {$rol} WHERE id = {$u}");
        $this->loginComo($u);
        $this->assertSame(200, $this->get('/articulos')->status);
        $this->assertSame(403, $this->get('/articulos/nuevo')->status);
        $this->assertSame(403, $this->post('/articulos', ['codigo' => 'T1', 'descripcion' => 'X'])->status);
        $this->assertSame(403, $this->get('/clientes')->status);
        $this->assertSame(200, $this->get('/tablas/bancos')->status);
        $this->assertSame(403, $this->get('/tablas/rubros')->status);
        $this->assertSame(403, $this->post('/tablas/rubros', ['codigo' => 'T1', 'descripcion' => 'X'])->status);
        $this->assertStringNotContainsString('/tablas/rubros"', $this->get('/')->body, 'el menú no muestra lo que no puede');
    }

    public function testMarcaAdministradorDaTodoYSoloElMasterCreaMaster(): void
    {
        $this->loginComo($this->crearUsuario('t_adm', 'admin'));
        $this->post('/roles', ['nombre' => 'T Jefe', 'es_admin' => '1']);
        $rol = (int) Db::pdo()->query("SELECT id FROM roles WHERE nombre = 'T Jefe'")->fetchColumn();
        $this->assertCount((int) Db::pdo()->query('SELECT COUNT(*) FROM permisos')->fetchColumn(), $this->permisos($rol));

        $res = $this->post('/roles', ['nombre' => 'T Falso master', 'es_master' => '1']);
        $this->assertSame(422, $res->status);
        $this->assertStringNotContainsString('Master (O&amp;S)', $this->get('/roles')->body, 'el admin no ve el rol Master');
    }

    public function testNoSeQuitaUsuariosASuPropioRolNiSeBorraUnRolEnUso(): void
    {
        $adm = $this->crearUsuario('t_adm', 'admin');
        $this->loginComo($adm);
        $this->post('/roles', ['nombre' => 'T Mio', 'permisos' => ['usuarios.administrar']]);
        $rol = (int) Db::pdo()->query("SELECT id FROM roles WHERE nombre = 'T Mio'")->fetchColumn();
        Db::pdo()->exec("UPDATE usuarios SET rol_id = {$rol} WHERE id = {$adm}");
        Auth::reset();
        $res = $this->post('/roles/' . $rol, ['nombre' => 'T Mio', 'permisos' => ['clientes.ver']]);
        $this->assertSame(422, $res->status);

        $this->post('/roles/' . $rol . '/borrar');
        $this->assertSame(1, (int) Db::pdo()->query("SELECT COUNT(*) FROM roles WHERE id = {$rol}")->fetchColumn(), 'en uso no se borra');
        $this->post('/roles/' . $this->rolId('operador') . '/borrar');
        $this->assertSame(1, (int) Db::pdo()->query("SELECT COUNT(*) FROM roles WHERE codigo = 'operador'")->fetchColumn(), 'los del sistema no se borran');
    }
}
