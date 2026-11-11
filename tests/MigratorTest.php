<?php
declare(strict_types=1);

namespace Tests;

use App\App;
use App\Core\Config;
use App\Core\Db;
use App\Core\Migrator;

final class MigratorTest extends TestCase
{
    private const TABLE = 'test_schema_migrations';

    protected function setUp(): void
    {
        Config::load(require App::root() . '/config/config.php');
        Db::reset();
        $this->limpiar();
    }

    protected function tearDown(): void
    {
        $this->limpiar();
    }

    private function limpiar(): void
    {
        $pdo = Db::pdo();
        $pdo->exec('DROP TABLE IF EXISTS test_mig_a');
        $pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    public function testSeparaSentenciasRespetandoComillasYComentarios(): void
    {
        $sql = <<<'SQL'
        -- comentario inicial; con punto y coma
        CREATE TABLE t (a VARCHAR(10)); /* otro; comentario */
        INSERT INTO t VALUES ('a;b'), ('it''s'), ("c;d");
        # comentario estilo mysql
        UPDATE t SET a = `a` WHERE a = 'x'
        SQL;

        $out = Migrator::splitStatements($sql);

        $this->assertCount(3, $out);
        $this->assertSame('CREATE TABLE t (a VARCHAR(10))', $out[0]);
        $this->assertStringContainsString("'a;b'", $out[1]);
        $this->assertStringContainsString("'it''s'", $out[1]);
        $this->assertStringStartsWith('UPDATE t SET', $out[2]);
    }

    public function testAplicaEnOrdenYEsIdempotente(): void
    {
        $dir = __DIR__ . '/fixtures/migrations';
        $migrator = new Migrator(Db::pdo(), $dir, self::TABLE);

        $primera = $migrator->run();
        $this->assertSame(['0001_crear_tabla.sql', '0002_agregar_columna.sql'], $primera);

        $fila = Db::pdo()->query('SELECT id, nombre, extra FROM test_mig_a')->fetch();
        $this->assertSame('a;b', $fila['nombre']);
        $this->assertSame(7, (int) $fila['extra']);

        $this->assertSame([], $migrator->run(), 'Una segunda corrida no debe aplicar nada');
        $this->assertSame(
            ['0001_crear_tabla.sql' => true, '0002_agregar_columna.sql' => true],
            $migrator->status()
        );
    }

    public function testUnaMigracionRotaNoQuedaRegistrada(): void
    {
        $dir = sys_get_temp_dir() . '/mig_rota_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/0001_rota.sql', 'CREATE TABLE test_mig_a (id INT); SELECT * FROM tabla_que_no_existe_xyz');

        try {
            $migrator = new Migrator(Db::pdo(), $dir, self::TABLE);
            try {
                $migrator->run();
                $this->fail('Debía lanzar una excepción');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('0001_rota.sql', $e->getMessage());
            }
            $this->assertSame(['0001_rota.sql' => false], $migrator->status());
        } finally {
            @unlink($dir . '/0001_rota.sql');
            @rmdir($dir);
        }
    }
}
