<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;

final class ArticulosTest extends DbTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        Db::pdo()->exec("DELETE FROM articulos WHERE codigo LIKE 'T-%'");
        Db::pdo()->exec("DELETE FROM subrubros WHERE rubro = 'TR'");
        Db::pdo()->exec("DELETE FROM rubros WHERE codigo = 'TR'");
    }

    private function datos(array $extra = []): array
    {
        return $extra + ['descripcion' => 'Artículo de prueba', 'estado' => 'AC', 'moneda' => '$', 'alicuota' => '21', 'grabado' => '1'];
    }

    public function testAltaConCodigoEnMayusculasQueNoSeRepiteNiCambia(): void
    {
        $this->loginComo($this->crearUsuario('t_op', 'operador'));
        $res = $this->post('/articulos', $this->datos(['codigo' => 't-abc 1', 'precio_de_venta_1' => '1.234,56']));
        $this->assertSame(302, $res->status);
        $a = Db::pdo()->query("SELECT * FROM articulos WHERE codigo = 'T-ABC 1'")->fetch();
        $this->assertSame('1234.5600', $a['precio_de_venta_1']);
        $this->assertSame(422, $this->post('/articulos', $this->datos(['codigo' => 'T-ABC 1']))->status, 'repetido');
        $this->assertSame(422, $this->post('/articulos', $this->datos(['codigo' => 'T-A/B']))->status, 'sin barras');

        $this->app->handle(\App\Http\Request::make('POST', '/articulos/ficha',
            $this->datos(['codigo' => 'OTRO', 'descripcion' => 'Cambiada', '_csrf' => \App\Core\Csrf::token()]), ['codigo' => 'T-ABC 1']));
        $this->assertSame('Cambiada', Db::pdo()->query("SELECT descripcion FROM articulos WHERE codigo = 'T-ABC 1'")->fetchColumn());
        $this->assertSame(0, (int) Db::pdo()->query("SELECT COUNT(*) FROM articulos WHERE codigo = 'OTRO'")->fetchColumn());
        $this->assertSame(200, $this->app->handle(\App\Http\Request::make('GET', '/articulos/ficha', [], ['codigo' => 'T-ABC 1']))->status);
    }

    public function testAlicuotaYSubrubroTienenQueExistir(): void
    {
        $pdo = Db::pdo();
        $pdo->exec("INSERT INTO rubros (codigo, descripcion) VALUES ('TR', 'Rubro prueba')");
        $pdo->exec("INSERT INTO subrubros (rubro, codigo, descripcion) VALUES ('TR', 'S1', 'Sub')");
        \App\Core\Validador::olvidar(); // las opciones se leen una vez por pedido
        $svc = new \App\Modules\Articulos\ArticulosService();
        $r = $svc->guardar(null, $this->datos(['codigo' => 'T-1', 'alicuota' => '99']));
        $this->assertTrue(isset($r['errores']['alicuota']));
        $r = $svc->guardar(null, $this->datos(['codigo' => 'T-1', 'rubro' => 'TR', 'subrubro' => 'S9']));
        $this->assertStringContainsString('no existe en el rubro', $r['errores']['subrubro'] ?? '');
        $this->assertTrue($svc->guardar(null, $this->datos(['codigo' => 'T-1', 'rubro' => 'TR', 'subrubro' => 's1']))['ok']);
        $this->assertSame('S1', $pdo->query("SELECT subrubro FROM articulos WHERE codigo = 'T-1'")->fetchColumn());
    }

    public function testBusquedaYBajasNoSalenPorOmision(): void
    {
        $svc = new \App\Modules\Articulos\ArticulosService();
        $svc->guardar(null, $this->datos(['codigo' => 'T-ACT', 'descripcion' => 'Tornillo activo']));
        $svc->guardar(null, $this->datos(['codigo' => 'T-BAJ', 'descripcion' => 'Tornillo dado de baja', 'estado' => 'BA']));
        $codigos = static fn (array $r) => array_column($r['filas'], 'codigo');
        $this->assertSame(['T-ACT'], $codigos($svc->listar(['q' => 'Tornillo'])));
        $this->assertSame(['T-BAJ'], $codigos($svc->listar(['q' => 'Tornillo', 'estado' => 'BA'])));
        $this->assertCount(2, $codigos($svc->listar(['q' => 't-', 'estado' => 'todos'])));
        $this->assertSame([], $codigos($svc->listar(['q' => '%', 'estado' => 'todos'])), 'el % se busca literal');
    }
}
