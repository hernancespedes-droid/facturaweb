<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Modules\Clientes\ClientesService;

final class ClientesTest extends DbTestCase
{
    private int $ultimo = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ultimo = (int) Db::pdo()->query('SELECT ultimo_cliente FROM parametros WHERE id = 1')->fetchColumn();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Db::pdo()->exec("DELETE FROM clientes WHERE razon_social LIKE 'T %'");
        Db::pdo()->exec("UPDATE parametros SET ultimo_cliente = {$this->ultimo} WHERE id = 1");
    }

    private function alta(array $datos): array
    {
        return (new ClientesService())->guardar(null, $datos + ['iva' => '3', 'forma_pago' => '1']);
    }

    public function testCodigoSigueLaNumeracionDelVfp(): void
    {
        Db::pdo()->exec('UPDATE parametros SET ultimo_cliente = 990000 WHERE id = 1');
        $r = $this->alta(['razon_social' => 'T Uno']);
        $this->assertSame(990001, $r['codigo']);
        $this->assertSame(990001, (int) Db::pdo()->query('SELECT ultimo_cliente FROM parametros WHERE id = 1')->fetchColumn());
        $this->assertSame(date('Y-m-d'), Db::pdo()->query('SELECT fecha_alta FROM clientes WHERE codigo = 990001')->fetchColumn());
    }

    public function testAltasSimultaneasNoRepitenCodigo(): void
    {
        Db::pdo()->exec('UPDATE parametros SET ultimo_cliente = 990100 WHERE id = 1');
        $procs = [];
        for ($i = 0; $i < 5; $i++) {
            $procs[] = proc_open([PHP_BINARY, __DIR__ . '/fixtures/alta_cliente.php', (string) $i], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        }
        foreach ($procs as $p) {
            proc_close($p);
        }
        $codigos = Db::pdo()->query("SELECT codigo FROM clientes WHERE razon_social LIKE 'T Concurrente%' ORDER BY codigo")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([990101, 990102, 990103, 990104, 990105], array_map('intval', $codigos));
    }

    public function testCuitObligatorioSalvoConsumidorFinalYRepetidoSeConfirma(): void
    {
        $r = $this->alta(['razon_social' => 'T Inscripto', 'iva' => '1']);
        $this->assertStringContainsString('CUIT es obligatorio', $r['errores']['cuit'] ?? '');
        $r = $this->alta(['razon_social' => 'T Inscripto', 'iva' => '1', 'cuit' => '30-71435753-8']);
        $this->assertStringContainsString('no es válido', $r['errores']['cuit'] ?? '');
        $this->assertTrue($this->alta(['razon_social' => 'T Final'])['ok'], 'consumidor final sin CUIT');

        $this->assertTrue($this->alta(['razon_social' => 'T Inscripto', 'iva' => '1', 'cuit' => '30714357537'])['ok']);
        $r = $this->alta(['razon_social' => 'T Sucursal', 'iva' => '1', 'cuit' => '30-71435753-7']);
        $this->assertStringContainsString('ya lo tiene el cliente', $r['errores']['cuit'] ?? '');
        $this->assertTrue($this->alta(['razon_social' => 'T Sucursal', 'iva' => '1', 'cuit' => '30-71435753-7', 'cuit_repetido' => '1'])['ok']);
    }

    public function testMailsYCodigosDeTablas(): void
    {
        $r = $this->alta(['razon_social' => 'T Mails', 'emailfc' => 'a@x.com; malo', 'provincia' => 'ZZ']);
        $this->assertTrue(isset($r['errores']['emailfc']));
        $this->assertTrue(isset($r['errores']['provincia']), 'la provincia tiene que existir');
        $r = $this->alta(['razon_social' => 'T Mails', 'emailfc' => 'A@x.com, b@y.com', 'provincia' => '02']);
        $this->assertSame('a@x.com; b@y.com', Db::pdo()->query("SELECT emailfc FROM clientes WHERE codigo = {$r['codigo']}")->fetchColumn());
    }

    public function testPermisosYPantallas(): void
    {
        $r = $this->alta(['razon_social' => 'T <b>Negrita</b> SA']);
        $codigo = (int) $r['codigo'];
        $sinPermiso = $this->crearUsuario('t_sin', 'operador');
        $rol = Db::pdo()->query("SELECT id FROM roles WHERE codigo = 'operador'")->fetchColumn();
        // Rol de consulta: solo clientes.ver.
        Db::pdo()->exec("INSERT INTO roles (codigo, nombre) VALUES ('t_consulta', 'T Consulta')");
        $consulta = (int) Db::pdo()->lastInsertId();
        Db::pdo()->exec("INSERT INTO rol_permisos (rol_id, permiso_id) SELECT {$consulta}, id FROM permisos WHERE codigo = 'clientes.ver'");
        Db::pdo()->exec("UPDATE usuarios SET rol_id = {$consulta} WHERE id = {$sinPermiso}");
        try {
            $this->loginComo($sinPermiso);
            $ficha = $this->get('/clientes/' . $codigo);
            $this->assertSame(200, $ficha->status);
            $this->assertStringContainsString('T &lt;b&gt;Negrita&lt;/b&gt; SA', $ficha->body);
            $this->assertStringNotContainsString('Guardar cambios', $ficha->body, 'sin editar no hay botón');
            $this->assertSame(403, $this->post('/clientes/' . $codigo, ['razon_social' => 'T Cambiado', 'iva' => '3', 'forma_pago' => '1'])->status);
            $this->assertSame(403, $this->post('/clientes/' . $codigo . '/activo', ['activo' => '0'])->status);
            $this->assertSame(403, $this->get('/clientes/nuevo')->status);

            $this->loginComo($this->crearUsuario('t_op', 'operador'));
            $this->assertRedirect('/clientes/' . $codigo, $this->post('/clientes/' . $codigo . '/activo', ['activo' => '0']));
            $fila = 'T &lt;b&gt;Negrita&lt;/b&gt; SA</a>';
            $this->assertStringNotContainsString($fila, $this->get('/clientes', ['q' => 'Negrita'])->body, 'los inactivos no salen por omisión');
            $this->assertStringContainsString($fila, $this->get('/clientes', ['q' => 'Negrita', 'estado' => 'todos'])->body);
            $this->assertStringContainsString($fila, $this->get('/clientes', ['q' => (string) $codigo, 'estado' => 'todos'])->body, 'busca por código');
            $this->assertSame(404, $this->get('/clientes/123456789')->status);
        } finally {
            Db::pdo()->exec("DELETE FROM usuarios WHERE rol_id = {$consulta}");
            Db::pdo()->exec("DELETE FROM roles WHERE id = {$consulta}");
        }
    }
}
