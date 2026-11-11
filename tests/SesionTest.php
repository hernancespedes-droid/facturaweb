<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Db;
use App\Core\DbSessionHandler;

final class SesionTest extends DbTestCase
{
    protected function tearDown(): void
    {
        Db::pdo()->exec("DELETE FROM sesiones WHERE id LIKE 'test%'");
        parent::tearDown();
    }

    public function testGuardaLeeYBorraSesionesEnLaBase(): void
    {
        $h = new DbSessionHandler(Db::pdo());
        $this->assertFalse($h->validateId('test1'), 'Un id que no existe no se acepta');
        $this->assertSame('', $h->read('test1'));

        $h->write('test1', 'uid|i:5;');
        $this->assertTrue($h->validateId('test1'));
        $this->assertSame('uid|i:5;', $h->read('test1'));

        $h->write('test1', 'uid|i:6;');
        $this->assertSame('uid|i:6;', $h->read('test1'), 'Escribir de nuevo actualiza');

        $h->destroy('test1');
        $this->assertSame('', $h->read('test1'));
    }

    public function testLimpiaLasSesionesVencidas(): void
    {
        $h = new DbSessionHandler(Db::pdo());
        $h->write('test_vieja', 'x');
        $h->write('test_nueva', 'y');
        Db::pdo()->exec("UPDATE sesiones SET actualizado = actualizado - 7200 WHERE id = 'test_vieja'");

        $h->gc(3600);
        $this->assertFalse($h->validateId('test_vieja'));
        $this->assertTrue($h->validateId('test_nueva'));
    }
}
