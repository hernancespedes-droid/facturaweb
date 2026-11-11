<?php
declare(strict_types=1);

namespace Tests;

final class SistemaTest extends DbTestCase
{
    public function testEntranMasterYAdministradorPeroSoloElMasterAplicaCambiosDeBase(): void
    {
        $this->loginComo($this->crearUsuario('t_op', 'operador'));
        $this->assertSame(403, $this->get('/sistema')->status);
        $this->assertSame(403, $this->post('/sistema/migrar')->status);

        $this->loginComo($this->crearUsuario('t_adm', 'admin'));
        $res = $this->get('/sistema');
        $this->assertSame(200, $res->status);
        $this->assertStringNotContainsString('Últimos errores', $res->body, 'los errores los ve solo el Master');
        $this->assertSame(403, $this->post('/sistema/migrar')->status);

        $this->loginComo($this->crearUsuario('t_mas', 'master'));
        $res = $this->get('/sistema');
        $this->assertStringContainsString('Últimos errores', $res->body);
        $this->assertSame(302, $this->post('/sistema/migrar')->status);
    }

    public function testInstalarSoloConTokenYMientrasNoHayaMaster(): void
    {
        $this->assertSame(404, $this->get('/instalar')->status, 'sin token la ruta no existe');
        putenv('INSTALAR_TOKEN=clave-de-prueba-larga');
        try {
            $this->app = \App\App::boot();
            $this->assertSame(200, $this->get('/instalar')->status);
            $mal = $this->post('/instalar', ['token' => 'otra', 'usuario' => 't_inst', 'nombre' => 'Inst', 'clave' => 'Clave12345', 'confirmacion' => 'Clave12345']);
            $this->assertSame(422, $mal->status);
            $this->assertRedirect('/login', $this->post('/instalar', ['token' => 'clave-de-prueba-larga', 'usuario' => 't_inst', 'nombre' => 'Inst',
                'clave' => 'Clave12345', 'confirmacion' => 'Clave12345']));
            $this->assertTrue((new \App\Modules\Auth\LoginService())->intentar('t_inst', 'Clave12345')['ok']);
            $this->assertSame(404, $this->get('/instalar')->status, 'con un Master ya no se puede volver a instalar');
        } finally {
            putenv('INSTALAR_TOKEN');
            $this->app = \App\App::boot();
        }
    }

    public function testMigrarSubiendoUnZipSoloElMaster(): void
    {
        $dir = sys_get_temp_dir() . '/fw_zip_' . bin2hex(random_bytes(4));
        mkdir($dir);
        DbfFalso::escribir($dir . '/vendedores.dbf', [['CODIGO', 'C', 2], ['DESCRIPCIO', 'C', 30]], [['CODIGO' => 'Z8', 'DESCRIPCIO' => 'DESDE ZIP']]);
        $zip = new \ZipArchive();
        $zip->open($dir . '/datos.zip', \ZipArchive::CREATE);
        $zip->addFile($dir . '/vendedores.dbf', 'carpeta/VENDEDORES.DBF');
        $zip->addFromString('../../malo.php', '<?php echo 1;');
        $zip->close();
        $archivo = ['zip' => ['name' => 'datos.zip', 'tmp_name' => $dir . '/datos.zip', 'error' => UPLOAD_ERR_OK, 'size' => filesize($dir . '/datos.zip')]];
        $pedido = fn (array $post) => $this->app->handle(\App\Http\Request::make('POST', '/sistema/migrar-vfp', $post + ['_csrf' => \App\Core\Csrf::token()], [], $archivo));
        try {
            $this->loginComo($this->crearUsuario('t_adm', 'admin'));
            $this->assertSame(403, $pedido(['simular' => '0', 'reemplazar' => '1'])->status);

            $this->loginComo($this->crearUsuario('t_mas', 'master'));
            $pedido(['simular' => '1', 'reemplazar' => '1']);
            $this->assertSame(0, (int) \App\Core\Db::pdo()->query("SELECT COUNT(*) FROM vendedores WHERE codigo = 'Z8'")->fetchColumn(), 'simulación');
            $pedido(['simular' => '0', 'reemplazar' => '1']);
            $this->assertSame('DESDE ZIP', \App\Core\Db::pdo()->query("SELECT descripcion FROM vendedores WHERE codigo = 'Z8'")->fetchColumn());
            $this->assertSame([], glob(\App\App::root() . '/storage/migracion/*/*') ?: [], 'los DBF subidos se borran');
            $this->assertSame(false, is_file(\App\App::root() . '/storage/malo.php'));
        } finally {
            \App\Core\Db::pdo()->exec("DELETE FROM vendedores WHERE codigo = 'Z8'");
            \App\Core\Db::pdo()->exec('DELETE FROM migracion_vfp');
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }
}
