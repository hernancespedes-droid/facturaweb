<?php
declare(strict_types=1);

namespace App\Modules\Sistema;

use App\App;
use App\Core\Config;
use App\Core\Db;
use App\Core\Migrator;
use App\Core\Session;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\LoginService;

/**
 * Instalación desde la web, para hostings sin SSH (Ferozo): crea las tablas y el primer usuario Master.
 * Solo existe mientras config/local.php tenga 'instalar_token' y todavía no haya ningún usuario Master; se pide
 * esa clave en el formulario. Después de instalar se borra el token de local.php (la ruta igual queda apagada
 * porque ya hay un Master).
 */
final class InstalarController
{
    private static function disponible(): bool
    {
        if ((string) Config::get('app.instalar_token', '') === '') {
            return false;
        }
        try {
            return (int) Db::pdo()->query('SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE r.es_master = 1')->fetchColumn() === 0;
        } catch (\PDOException) {
            return true; // base vacía: todavía no hay tablas
        }
    }

    public static function form(Request $req, array $params): Response
    {
        if (!self::disponible()) {
            return new Response(404);
        }
        return Response::html(View::render('sistema/instalar', ['title' => 'Instalación', 'error' => null, 'datos' => ['usuario' => 'oys', 'nombre' => 'O&S Sistemas']]));
    }

    public static function instalar(Request $req, array $params): Response
    {
        if (!self::disponible()) {
            return new Response(404);
        }
        $p = $req->post;
        $datos = ['usuario' => mb_strtolower(trim((string) ($p['usuario'] ?? ''))), 'nombre' => trim((string) ($p['nombre'] ?? ''))];
        $error = null;
        if (!hash_equals((string) Config::get('app.instalar_token', ''), (string) ($p['token'] ?? ''))) {
            $error = 'La clave de instalación no coincide con la de config/local.php.';
        } elseif (!preg_match('/^[a-z0-9._-]{3,40}$/', $datos['usuario']) || $datos['nombre'] === '') {
            $error = 'Usuario de 3 a 40 letras sin acentos, números, punto o guion, y el nombre.';
        } else {
            $error = LoginService::validarClaveNueva((string) ($p['clave'] ?? ''), (string) ($p['confirmacion'] ?? ''), $datos['usuario']);
        }
        if ($error !== null) {
            return Response::html(View::render('sistema/instalar', ['title' => 'Instalación', 'error' => $error, 'datos' => $datos]), 422);
        }
        $pdo = Db::pdo();
        $aplicadas = (new Migrator($pdo, App::root() . '/database/migrations'))->run();
        $rol = (int) $pdo->query("SELECT id FROM roles WHERE codigo = 'master'")->fetchColumn();
        $pdo->prepare('INSERT INTO usuarios (usuario, nombre, password_hash, rol_id) VALUES (?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), rol_id = VALUES(rol_id), activo = 1, debe_cambiar_clave = 0')
            ->execute([$datos['usuario'], $datos['nombre'], password_hash((string) $p['clave'], PASSWORD_DEFAULT), $rol]);
        Session::flash('ok', 'Instalación lista (' . count($aplicadas) . ' cambio(s) de base). Ingresá con el usuario ' . $datos['usuario']
            . '. Borrá "instalar_token" de config/local.php.');
        return Response::redirect(url('/login'));
    }
}
