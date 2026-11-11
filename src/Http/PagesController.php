<?php
declare(strict_types=1);

namespace App\Http;

use App\App;
use App\Core\Config;
use App\Core\Db;
use App\Core\Diagnostico;
use App\Core\View;

final class PagesController
{
    /**
     * @param array<string,string> $params
     */
    public static function home(Request $req, array $params): Response
    {
        return Response::html(View::render('home', ['title' => 'Inicio', 'nav' => 'inicio']));
    }

    /**
     * Chequeo de salud: sirve para verificar el despliegue (¿levanta? ¿conecta a la base?).
     * No expone datos sensibles.
     *
     * @param array<string,string> $params
     */
    public static function salud(Request $req, array $params): Response
    {
        $db = false;
        $pendientes = null;
        try {
            Db::pdo()->query('SELECT 1');
            $db = true;
            $estado = (new \App\Core\Migrator(Db::pdo(), App::root() . '/database/migrations'))->status();
            $pendientes = count(array_filter($estado, static fn ($a) => !$a));
        } catch (\Throwable $e) {
            $db = false;
        }
        $version = trim((string) @file_get_contents(App::root() . '/VERSION'));

        return Response::json([
            'ok'      => $db,
            'db'      => $db,
            'version' => $version,
            'cambios_de_base_pendientes' => $pendientes,
            'php'     => PHP_VERSION,
            'hora'    => date('c'),
        ], $db ? 200 : 503);
    }

    /**
     * Diagnóstico del servidor tal como lo ve la web. Está apagado salvo que config/local.php
     * defina app.diag_token; se usa una vez tras instalar y se vuelve a borrar el token.
     *
     * @param array<string,string> $params
     */
    public static function diagnostico(Request $req, array $params): Response
    {
        $token = (string) Config::get('app.diag_token', '');
        $given = (string) ($req->query['token'] ?? '');
        if ($token === '' || !hash_equals($token, $given)) {
            return new Response(404);
        }
        return Response::json(Diagnostico::report(), 200);
    }
}
