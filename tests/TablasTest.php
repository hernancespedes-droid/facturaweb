<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;

final class TablasTest extends DbTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        $pdo = Db::pdo();
        $pdo->exec("DELETE FROM clientes WHERE razon_social LIKE 'T %'");
        $pdo->exec("DELETE FROM vendedores WHERE codigo LIKE 'T%'");
        $pdo->exec("DELETE FROM subrubros WHERE rubro LIKE 'T%'");
        $pdo->exec("DELETE FROM rubros WHERE codigo LIKE 'T%'");
        $pdo->exec("DELETE FROM cotizacion_dolar WHERE fecha = '2001-01-01'");
    }

    private function id(string $tabla, string $where): int
    {
        return (int) Db::pdo()->query("SELECT id FROM `{$tabla}` WHERE {$where}")->fetchColumn();
    }

    public function testAltaModificacionYCodigoQueNoSeCambia(): void
    {
        $this->loginComo($this->crearUsuario('t_op', 'operador'));
        $res = $this->post('/tablas/vendedores', ['codigo' => 'tz', 'descripcion' => 'Prueba', 'comision_1' => '1,5']);
        $this->assertRedirect('/tablas/vendedores', $res);
        $id = $this->id('vendedores', "codigo = 'TZ'");
        $this->assertTrue($id > 0, 'el código se guarda en mayúsculas');
        $this->assertSame('1.50', Db::pdo()->query("SELECT comision_1 FROM vendedores WHERE id = {$id}")->fetchColumn());

        $res = $this->post('/tablas/vendedores', ['codigo' => 'TZ', 'descripcion' => 'Otra']);
        $this->assertSame(422, $res->status, 'código repetido');
        $this->assertStringContainsString('Ya hay otro registro con ese código', $res->body);

        $this->post('/tablas/vendedores/' . $id, ['codigo' => 'XX', 'descripcion' => 'Cambiada']);
        $fila = Db::pdo()->query("SELECT codigo, descripcion FROM vendedores WHERE id = {$id}")->fetch();
        $this->assertSame(['codigo' => 'TZ', 'descripcion' => 'Cambiada'], $fila, 'la clave no se cambia al modificar');
    }

    public function testValidacionesPorTipo(): void
    {
        $this->loginComo($this->crearUsuario('t_op', 'operador'));
        $res = $this->post('/tablas/vendedores', ['codigo' => 'TTT', 'descripcion' => '', 'comision_1' => 'abc']);
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('Hasta 2 caracteres', $res->body);
        $this->assertStringContainsString('Completá descripción', $res->body);
        $this->assertStringContainsString('Tiene que ser un número', $res->body);

        $res = $this->post('/tablas/cotizacion_dolar', ['fecha' => '31/02/2001', 'importe' => '0']);
        $this->assertStringContainsString('La fecha no es válida', $res->body);
        $this->post('/tablas/cotizacion_dolar', ['fecha' => '01/01/2001', 'importe' => '1.234,5678']);
        $this->assertSame('1234.5678', Db::pdo()->query("SELECT importe FROM cotizacion_dolar WHERE fecha = '2001-01-01'")->fetchColumn());

        $this->post('/tablas/rubros', ['codigo' => 'T1', 'descripcion' => 'Rubro']);
        $res = $this->post('/tablas/subrubros', ['rubro' => 'NO', 'codigo' => 'S1', 'descripcion' => 'Sub']);
        $this->assertStringContainsString('Elegí una opción de la lista', $res->body, 'el rubro tiene que existir');
        $this->assertRedirect('/tablas/subrubros', $this->post('/tablas/subrubros', ['rubro' => 'T1', 'codigo' => 'S1', 'descripcion' => 'Sub']));
    }

    public function testNoSeBorraLoQueSeUsa(): void
    {
        $this->loginComo($this->crearUsuario('t_op', 'operador'));
        $this->post('/tablas/vendedores', ['codigo' => 'TU', 'descripcion' => 'Usado']);
        $id = $this->id('vendedores', "codigo = 'TU'");
        Db::pdo()->exec("INSERT INTO clientes (codigo, razon_social, iva, vendedor) VALUES (999901, 'T Cliente', 3, 'TU')");
        $this->post('/tablas/vendedores/' . $id . '/borrar');
        $this->assertSame($id, $this->id('vendedores', "codigo = 'TU'"), 'en uso no se borra');
        $flash = (array) \App\Core\Session::get('_flash');
        $this->assertStringContainsString('lo usan 1 clientes', (string) (end($flash)['texto'] ?? ''));

        Db::pdo()->exec('DELETE FROM clientes WHERE codigo = 999901');
        $this->post('/tablas/vendedores/' . $id . '/borrar');
        $this->assertSame(0, $this->id('vendedores', "codigo = 'TU'"));
    }

    public function testPermisosPorTablaYParametrosUnico(): void
    {
        $this->loginComo($this->crearUsuario('t_op', 'operador'));
        $this->assertSame(403, $this->get('/tablas/sucursales')->status);
        $this->assertSame(403, $this->get('/tablas/parametros/1')->status);
        $this->assertSame(403, $this->post('/tablas/parametros/1', ['empresa' => 'Hack'])->status);
        $this->assertSame(404, $this->get('/tablas/usuarios')->status, 'solo las tablas declaradas');
        $this->assertSame(404, $this->get('/tablas/clientes')->status);

        $this->loginComo($this->crearUsuario('t_adm', 'admin'));
        $this->assertRedirect('/tablas/parametros/1', $this->get('/tablas/parametros'));
        $this->assertSame(404, $this->get('/tablas/parametros/nuevo')->status);
        $antes = Db::pdo()->query('SELECT empresa, cuit FROM parametros WHERE id = 1')->fetch();
        $res = $this->post('/tablas/parametros/1', ['empresa' => 'T EMPRESA', 'cuit' => '30-71435753-8', 'iva' => '1']);
        $this->assertSame(422, $res->status, 'CUIT con dígito verificador mal');
        $this->post('/tablas/parametros/1', ['empresa' => 'T EMPRESA', 'cuit' => '30-71435753-7', 'iva' => '1']);
        $this->assertSame('30714357537', (string) Db::pdo()->query('SELECT cuit FROM parametros WHERE id = 1')->fetchColumn());
        Db::pdo()->prepare('UPDATE parametros SET empresa = ?, cuit = ? WHERE id = 1')->execute([$antes['empresa'], $antes['cuit']]);
    }
}
