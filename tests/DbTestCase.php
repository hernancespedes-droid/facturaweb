<?php
declare(strict_types=1);

namespace Tests;

use App\App;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Session;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Auth;

/**
 * Base de las pruebas que usan la base *_test. Los usuarios de prueba empiezan con "t_"
 * y se borran antes y después de cada prueba (los datos de prueba de cada módulo los limpia su prueba).
 */
abstract class DbTestCase extends TestCase
{
    protected App $app;

    protected function setUp(): void
    {
        Db::reset();
        $this->app = App::boot();
        Session::clear();
        Auth::reset();
        \App\Core\Validador::olvidar();
        $this->limpiarUsuarios();
    }

    protected function tearDown(): void
    {
        Session::clear();
        $this->limpiarUsuarios();
    }

    protected function limpiarUsuarios(): void
    {
        Db::pdo()->exec("DELETE FROM usuarios WHERE usuario LIKE 't\\_%'");
    }

    /** Crea un usuario de prueba (empieza con "t_") con el rol indicado (codigo). */
    protected function crearUsuario(string $usuario, string $rol, string $clave = 'Prueba1234', bool $activo = true): int
    {
        $pdo = Db::pdo();
        $pdo->prepare('INSERT INTO usuarios (usuario, nombre, password_hash, rol_id, activo) SELECT ?, ?, ?, id, ? FROM roles WHERE codigo = ?')
            ->execute([$usuario, 'Nombre ' . $usuario, password_hash($clave, PASSWORD_DEFAULT), $activo ? 1 : 0, $rol]);
        return (int) $pdo->lastInsertId();
    }

    /** Deja la sesión como si el usuario hubiera ingresado. */
    protected function loginComo(int $usuarioId): void
    {
        Session::clear();
        Auth::login($usuarioId);
        Auth::reset();
    }

    protected function rolId(string $codigo): int
    {
        $st = Db::pdo()->prepare('SELECT id FROM roles WHERE codigo = ?');
        $st->execute([$codigo]);
        return (int) $st->fetchColumn();
    }

    protected function get(string $path, array $query = []): Response
    {
        return $this->app->handle(Request::make('GET', $path, [], $query));
    }

    /** POST con el token CSRF de la sesión (salvo que se pida lo contrario). */
    protected function post(string $path, array $data = [], bool $conCsrf = true): Response
    {
        if ($conCsrf) {
            $data[Csrf::CAMPO] = Csrf::token();
        }
        return $this->app->handle(Request::make('POST', $path, $data));
    }

    protected function assertRedirect(string $path, Response $res, string $message = ''): void
    {
        $this->assertSame(302, $res->status, $message);
        $this->assertSame(url($path), $res->headers['Location'] ?? null, $message);
    }
}
