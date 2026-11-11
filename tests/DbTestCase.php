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
 * y se borran antes y después de cada prueba.
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
        $this->limpiarUsuarios();
    }

    protected function tearDown(): void
    {
        Session::clear();
        $this->limpiarUsuarios();
    }

    protected function limpiarUsuarios(): void
    {
        $pdo = Db::pdo();
        $ids = $pdo->query("SELECT id FROM usuarios WHERE usuario LIKE 't\\_%'")->fetchAll(\PDO::FETCH_COLUMN);
        if ($ids === []) {
            return;
        }
        $in = implode(',', array_map('intval', $ids));
        // Remitos y reservas de los usuarios de prueba (módulo 5).
        // Pagos (módulo 7) de los usuarios de prueba o imputados a sus remitos.
        $pdo->exec("DELETE FROM pagos WHERE creado_por IN ({$in}) OR id IN (SELECT * FROM (SELECT i.pago_id FROM pago_imputaciones i
                      JOIN remitos r ON r.id = i.remito_id WHERE r.vendedor_id IN ({$in})) x)");
        $pdo->exec("UPDATE pagos SET anulado_por = NULL WHERE anulado_por IN ({$in})");
        $pdo->exec("UPDATE producto_movimientos SET remito_id = NULL WHERE remito_id IN (SELECT id FROM remitos WHERE vendedor_id IN ({$in}))");
        $pdo->exec("DELETE FROM remitos WHERE vendedor_id IN ({$in})");
        $pdo->exec("UPDATE remitos SET cerrado_por = NULL WHERE cerrado_por IN ({$in})");
        $pdo->exec("UPDATE remitos SET autorizado_por = NULL WHERE autorizado_por IN ({$in})");
        $pdo->exec("UPDATE remitos SET anulado_por = NULL WHERE anulado_por IN ({$in})");
        $pdo->exec("UPDATE productos SET estado = IF(estado = 'reservado', 'en_stock', estado), reservado_por = NULL, reservado_hasta = NULL WHERE reservado_por IN ({$in})");
        $pdo->exec("UPDATE producto_movimientos SET usuario_id = NULL WHERE usuario_id IN ({$in})");
        $pdo->exec("UPDATE diccionario_campos SET creado_por = NULL WHERE creado_por IN ({$in})");
        $pdo->exec("UPDATE diccionario_cambios SET usuario_id = NULL WHERE usuario_id IN ({$in})");
        $pdo->exec("UPDATE diccionario_publicaciones SET usuario_id = NULL WHERE usuario_id IN ({$in})");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ({$in})");
    }

    /**
     * @param array<string,string> $roles empresa (BP/OP) => rol (codigo)
     */
    protected function crearUsuario(string $usuario, array $roles, string $clave = 'Prueba1234', bool $activo = true): int
    {
        $pdo = Db::pdo();
        $pdo->prepare('INSERT INTO usuarios (usuario, nombre, password_hash, activo) VALUES (?, ?, ?, ?)')
            ->execute([$usuario, 'Nombre ' . $usuario, password_hash($clave, PASSWORD_DEFAULT), $activo ? 1 : 0]);
        $id = (int) $pdo->lastInsertId();
        foreach ($roles as $empresa => $rol) {
            $pdo->prepare(
                'INSERT INTO usuario_empresas (usuario_id, empresa_id, rol_id)
                 SELECT ?, e.id, r.id FROM empresas e, roles r WHERE e.codigo = ? AND r.codigo = ?'
            )->execute([$id, $empresa, $rol]);
        }
        return $id;
    }

    /** Deja la sesión como si el usuario hubiera ingresado y elegido la empresa. */
    protected function loginComo(int $usuarioId, ?string $empresa = 'BP'): void
    {
        Session::clear();
        Auth::login($usuarioId);
        if ($empresa !== null) {
            $this->assertTrue(Auth::elegirEmpresa($this->empresaId($empresa)), "acceso a {$empresa}");
        }
        Auth::reset();
    }

    protected function empresaId(string $codigo): int
    {
        $st = Db::pdo()->prepare('SELECT id FROM empresas WHERE codigo = ?');
        $st->execute([$codigo]);
        return (int) $st->fetchColumn();
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
