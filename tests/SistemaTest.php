<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;

final class SistemaTest extends DbTestCase
{
    private const PRUEBA = '9999_prueba_sistema.sql';

    protected function tearDown(): void
    {
        @unlink(dirname(__DIR__) . '/database/migrations/' . self::PRUEBA);
        Db::pdo()->exec('DROP TABLE IF EXISTS prueba_sistema');
        Db::pdo()->prepare('DELETE FROM schema_migrations WHERE filename = ?')->execute([self::PRUEBA]);
        parent::tearDown();
    }

    public function testEntranMasterYAdministradorPeroSoloElMasterAplicaCambiosDeBase(): void
    {
        foreach (['operador', 'vendedor_interno', 'vendedor_externo'] as $rol) {
            $id = $this->crearUsuario('t_' . $rol, ['BP' => $rol]);
            $this->loginComo($id, 'BP');
            $this->assertSame(403, $this->get('/sistema')->status, $rol);
            $this->assertSame(403, $this->post('/sistema/migrar')->status, $rol);
            $this->assertSame(403, $this->post('/sistema/menu', ['disposicion' => 'lateral'])->status, $rol);
            $this->assertStringNotContainsString('href="' . url_base('/sistema') . '"', $this->get('/')->body, $rol);
        }
        // 0.9.6: el Administrador entra y ve todo, pero no aplica los cambios de base.
        $id = $this->crearUsuario('t_admin', ['BP' => 'admin']);
        $this->loginComo($id, 'BP');
        $res = $this->get('/sistema');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('0008_sesiones.sql', $res->body);
        $this->assertStringNotContainsString('action="' . url('/sistema/migrar') . '"', $res->body, 'sin el botón');
        $this->assertSame(403, $this->post('/sistema/migrar')->status);
        $this->assertStringContainsString('href="' . url_base('/sistema') . '"', $this->get('/')->body, 'en el menú');
        // En "Todas" también entra.
        $this->limpiarUsuarios();
        $id = $this->crearUsuario('t_admin2', ['BP' => 'admin', 'OP' => 'admin']);
        $this->loginComo($id, null);
        $this->assertTrue(\App\Modules\Auth\Auth::elegirEmpresa(\App\Modules\Auth\Auth::TODAS));
        \App\Modules\Auth\Auth::reset();
        $this->assertSame(200, $this->get('/sistema')->status);
        $this->assertSame(403, $this->post('/sistema/migrar')->status);
        // El Master sí.
        $id = $this->crearUsuario('t_master', ['BP' => 'master']);
        $this->loginComo($id, 'BP');
        $res = $this->get('/sistema');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('action="' . url('/sistema/migrar') . '"', $res->body);
    }

    public function testAplicaLosCambiosDeBasePendientes(): void
    {
        file_put_contents(dirname(__DIR__) . '/database/migrations/' . self::PRUEBA,
            "CREATE TABLE IF NOT EXISTS prueba_sistema (id INT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n");
        $id = $this->crearUsuario('t_master', ['BP' => 'master']);
        $this->loginComo($id, 'BP');

        $this->assertStringContainsString(self::PRUEBA, $this->get('/sistema')->body);
        $salud = json_decode($this->get('/salud')->body, true);
        $this->assertSame(1, $salud['cambios_de_base_pendientes']);

        $this->assertRedirect('/sistema', $this->post('/sistema/migrar'));
        $this->assertSame(1, (int) Db::pdo()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prueba_sistema'")->fetchColumn());
        $salud = json_decode($this->get('/salud')->body, true);
        $this->assertSame(0, $salud['cambios_de_base_pendientes']);
    }
}
