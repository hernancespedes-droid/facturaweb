<?php
declare(strict_types=1);

namespace Tests;

use App\App;
use App\Core\Db;

/** 0.9.24: errores 500 de a ratos: reintento de la conexión a la base y "Últimos errores" en Sistema (solo Master). */
final class ErroresTest extends DbTestCase
{
    public function testErroresPasajerosDeLaBase(): void
    {
        $e = static function (string $msg, int $codigo): \PDOException {
            $x = new \PDOException($msg, $codigo);
            $x->errorInfo = ['HY000', $codigo, $msg];
            return $x;
        };
        $this->assertTrue(Db::esPasajero($e('SQLSTATE[HY000] [1040] Too many connections', 1040)));
        $this->assertTrue(Db::esPasajero($e("User 'x' has exceeded the 'max_user_connections' resource", 1203)));
        $this->assertTrue(Db::esPasajero($e('MySQL server has gone away', 2006)));
        $this->assertFalse(Db::esPasajero($e("Access denied for user 'x'", 1045)), 'clave mal: no se reintenta');
    }

    public function testSistemaMuestraLosUltimosErroresSoloAlMaster(): void
    {
        $marca = 'ZZ prueba de error ' . bin2hex(random_bytes(4));
        App::registrar(new \RuntimeException($marca));
        $this->loginComo($this->crearUsuario('t_master', ['BP' => 'master']));
        $html = $this->get('/sistema')->body;
        $this->assertStringContainsString('Últimos errores', $html);
        $this->assertStringContainsString($marca, $html);
        $this->loginComo($this->crearUsuario('t_adm', ['BP' => 'admin']));
        $adm = $this->get('/sistema');
        $this->assertFalse(str_contains($adm->body, $marca), 'el Administrador no ve los errores');
    }
}
