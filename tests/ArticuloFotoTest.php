<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Http\Request;
use App\Modules\Articulos\ArticulosController;
use App\Modules\Articulos\BuscadorArticulos;
use App\Modules\Articulos\Foto;

/** Foto de los artículos: se guarda en la base en dos tamaños, la ven los que trabajan con artículos y se sube/quita con el permiso de editar. */
final class ArticuloFotoTest extends ComprobantesTestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        Db::pdo()->exec("DELETE FROM articulos_fotos WHERE articulo LIKE 'T-%'");
        $this->tmp = sys_get_temp_dir() . '/ft_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        Db::pdo()->exec("DELETE FROM articulos_fotos WHERE articulo LIKE 'T-%'");
        foreach (glob($this->tmp . '*') ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    /** @param list<array{0:int|string,1:int|string}> $copia */
    private function restaurar(array $copia): void
    {
        Db::pdo()->exec("DELETE rp FROM rol_permisos rp JOIN roles r ON r.id = rp.rol_id WHERE r.codigo = 'operador'");
        $ins = Db::pdo()->prepare('INSERT IGNORE INTO rol_permisos (rol_id, permiso_id) VALUES (?, ?)');
        foreach ($copia as $f) {
            $ins->execute([$f[0], $f[1]]);
        }
        \App\Modules\Auth\Auth::reset();
    }

    private function quitarEditar(): void
    {
        Db::pdo()->exec("DELETE rp FROM rol_permisos rp JOIN permisos p ON p.id = rp.permiso_id JOIN roles r ON r.id = rp.rol_id WHERE r.codigo = 'operador' AND p.codigo = 'articulos.editar'");
        \App\Modules\Auth\Auth::reset();
    }

    private function postQ(string $path): \App\Http\Response
    {
        return $this->app->handle(Request::make('POST', $path, [\App\Core\Csrf::CAMPO => \App\Core\Csrf::token()], ['codigo' => 'T-ART']));
    }

    private function imagen(int $w, int $h, string $ext = 'png'): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 200, 30, 30));
        $f = $this->tmp . '.' . $ext;
        $ext === 'jpg' ? imagejpeg($img, $f) : imagepng($img, $f);
        return $f;
    }

    public function testGuardaEnDosTamanosYValidaQueSeaUnaImagen(): void
    {
        $this->assertNull(Foto::guardar('T-ART', $this->imagen(3200, 2000)));
        $g = Foto::obtener('T-ART', 'g');
        $c = Foto::obtener('T-ART', 'c');
        $this->assertSame([1600, 1000], array_slice(getimagesizefromstring($g['datos']), 0, 2), 'la grande entra en 1600 px (para el zoom)');
        $this->assertSame([360, 225], array_slice(getimagesizefromstring($c['datos']), 0, 2), 'la chica entra en 360 px');
        $this->assertSame('image/jpeg', getimagesizefromstring($g['datos'])['mime']);
        $this->assertTrue(strlen($c['datos']) < strlen($g['datos']));
        // una foto chica no se agranda; reemplazar y quitar
        $this->assertNull(Foto::guardar('T-ART', $this->imagen(300, 200)));
        $this->assertSame([300, 200], array_slice(getimagesizefromstring(Foto::obtener('T-ART', 'g')['datos']), 0, 2));
        Foto::quitar('T-ART');
        $this->assertNull(Foto::obtener('T-ART', 'g'));
        // lo que no es una imagen
        file_put_contents($this->tmp . '.txt', 'esto no es una foto');
        $this->assertStringContainsString('no es una foto', (string) Foto::guardar('T-ART', $this->tmp . '.txt'));
        file_put_contents($this->tmp . '.php', '<?php echo 1;');
        $this->assertStringContainsString('no es una foto', (string) Foto::guardar('T-ART', $this->tmp . '.php'));
        $this->assertStringContainsString('Elegí', (string) Foto::guardar('T-ART', $this->tmp . '.noexiste'));
        $this->assertNull(Foto::obtener('T-ART', 'c'));
    }

    public function testLosBuscadoresTraenLaFoto(): void
    {
        $this->assertNull(BuscadorArticulos::buscar('T-ART', true)[0]['foto'], 'sin foto');
        Foto::guardar('T-ART', $this->imagen(800, 600));
        $a = BuscadorArticulos::buscar('T-ART', true)[0];
        $this->assertStringContainsString('/articulos/foto?codigo=T-ART&t=c', (string) $a['foto']);
        $this->assertStringContainsString('t=g', (string) $a['foto_grande']);
        $this->assertSame('T-ART', BuscadorArticulos::listar('T-ART', 1)['items'][0]['codigo']);
        $this->assertNotNull(BuscadorArticulos::listar('T-ART', 1)['items'][0]['foto']);
    }

    public function testSubirQuitarVerYPermisos(): void
    {
        $this->loginComo($this->crearUsuario('t_adm_ft', 'admin'));
        $ruta = $this->imagen(1000, 700, 'jpg');
        $req = Request::make('POST', '/articulos/foto', [], ['codigo' => 'T-ART'], ['foto' => ['name' => 'f.jpg', 'tmp_name' => $ruta, 'error' => UPLOAD_ERR_OK, 'size' => filesize($ruta), 'type' => 'image/jpeg']]);
        $r = ArticulosController::subirFoto($req, []);
        $this->assertSame(302, $r->status);
        $this->assertNotNull(Foto::obtener('T-ART', 'g'));
        // la ficha la muestra con el enlace para ampliar y el botón de quitar
        $ficha = $this->get('/articulos/ficha', ['codigo' => 'T-ART']);
        $this->assertStringContainsString('data-ampliar', $ficha->body);
        $this->assertStringContainsString('Quitar la foto', $ficha->body);
        // el archivo se sirve como imagen
        $img = $this->get('/articulos/foto', ['codigo' => 'T-ART', 't' => 'g']);
        $this->assertSame(200, $img->status);
        $this->assertSame('image/jpeg', $img->headers['Content-Type']);
        $this->assertSame(404, $this->get('/articulos/foto', ['codigo' => 'NO-HAY'])->status);
        // el Operador (comprobantes, kiosco) la ve pero no sube ni quita
        $copia = Db::pdo()->query("SELECT rp.rol_id, rp.permiso_id FROM rol_permisos rp JOIN roles r ON r.id = rp.rol_id WHERE r.codigo = 'operador'")->fetchAll(\PDO::FETCH_NUM);
        $this->loginComo($this->crearUsuario('t_op_ft', 'operador'));
        $this->assertSame(200, $this->get('/articulos/foto', ['codigo' => 'T-ART'])->status);
        $this->quitarEditar();
        try {
            $this->assertSame(403, $this->postQ('/articulos/foto')->status);
            $this->assertSame(403, $this->postQ('/articulos/foto/quitar')->status);
        } finally {
            $this->restaurar($copia);
        }
        $this->assertNotNull(Foto::obtener('T-ART', 'g'), 'sigue ahí');
        // sin ningún permiso de los que trabajan con artículos: 403
        Db::pdo()->exec("DELETE rp FROM rol_permisos rp JOIN permisos p ON p.id = rp.permiso_id JOIN roles r ON r.id = rp.rol_id WHERE r.codigo = 'operador' AND p.codigo IN ('articulos.ver', 'comprobantes.ver', 'comprobantes.emitir', 'kiosco.usar', 'cambios.hacer', 'stock.ver')");
        \App\Modules\Auth\Auth::reset();
        try {
            $this->assertSame(403, $this->get('/articulos/foto', ['codigo' => 'T-ART'])->status);
        } finally {
            $this->restaurar($copia);
        }
        // quitar (admin)
        $this->loginComo($this->crearUsuario('t_adm_ft2', 'admin'));
        $this->assertSame(302, $this->postQ('/articulos/foto/quitar')->status);
        $this->assertNull(Foto::obtener('T-ART', 'g'));
    }
}
