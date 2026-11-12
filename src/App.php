<?php
declare(strict_types=1);

namespace App;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Modules\Auth\Auth;

final class App
{
    private Router $router;

    public function __construct(?Router $router = null)
    {
        $this->router = $router ?? $this->buildRouter();
    }

    public static function root(): string
    {
        return dirname(__DIR__);
    }

    /** @param array<string,mixed>|null $config */
    public static function boot(?array $config = null): self
    {
        Config::load($config ?? require self::root() . '/config/config.php');
        date_default_timezone_set('America/Argentina/Buenos_Aires');
        return new self();
    }

    public function run(): void
    {
        // Errores que cortan PHP (memoria, tiempo): también quedan en storage/logs.
        register_shutdown_function(static function (): void {
            $e = error_get_last();
            if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::registrar(new \ErrorException($e['message'], 0, $e['type'], $e['file'], $e['line']));
            }
        });
        try {
            $basePath = (string) Config::get('app.base_path', '');
            Session::start(); // se conecta a la base (sesiones): queda dentro del try para registrar si falla
            $this->handle(Request::fromGlobals($basePath))->send();
        } catch (\Throwable $e) {
            self::registrar($e);
            self::paginaSinBase();
        }
    }

    /** Si no se pudo ni empezar (ej. la base no respondió): una página simple, sin tocar la base, que invita a reintentar. */
    private static function paginaSinBase(): void
    {
        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: text/html; charset=utf-8');
            header('Retry-After: 5');
            header('Cache-Control: no-store');
        }
        echo '<!doctype html><html lang="es-AR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Un momento</title><style>body{font:16px/1.5 Arial,sans-serif;background:#f3f5f9;color:#18202b;margin:0}'
            . '.c{max-width:460px;margin:12vh auto;padding:24px;background:#fff;border-radius:14px;box-shadow:0 8px 24px -14px rgba(20,30,50,.35)}'
            . 'a{display:inline-block;margin-top:12px;padding:10px 18px;background:#2f6bff;color:#fff;border-radius:8px;text-decoration:none;font-weight:700}</style></head>'
            . '<body><div class="c"><h1>El sistema está ocupado un momento</h1><p>No se pudo completar la operación porque el servidor no respondió. '
            . 'No se perdió nada de lo que ya estaba guardado: volvé a intentar en unos segundos.</p>'
            . '<a href="javascript:location.reload()">Reintentar</a></div></body></html>';
    }

    public function handle(Request $req): Response
    {
        Auth::reset();
        \App\Modules\Configuracion\Configuracion::reset();
        \App\Core\Validador::olvidar();
        try {
            $esApi = str_starts_with($req->path, '/api/'); // API del agente: sin formulario, sin sesión y sin CSRF (usa clave en el encabezado)
            if (!$esApi && $req->method === 'POST' && $req->post === [] && (int) ($req->server['CONTENT_LENGTH'] ?? 0) > 0) {
                // PHP descarta todo el formulario cuando supera post_max_size (típico al subir un Excel grande).
                return $this->withSecurityHeaders($this->errorPage(
                    413,
                    'El archivo es demasiado grande para el servidor (máximo ' . ini_get('post_max_size') . '). '
                    . 'Pedí al hosting que suba el límite.'
                ));
            }
            if (!$esApi && $req->method === 'POST' && !Csrf::valid($req)) {
                // Todo formulario que cambia datos lleva el token (csrf_field()).
                return $this->withSecurityHeaders($this->errorPage(
                    403,
                    'La página venció o el formulario no es válido. Volvé atrás, recargá la página y probá de nuevo.'
                ));
            }
            $res = $this->router->dispatch($req);
        } catch (\Throwable $e) {
            $this->log($e);
            $res = new Response(500);
            if (Config::get('app.debug')) {
                $res = $this->errorPage(500, $e->getMessage());
            }
        }

        if ($res->status >= 400 && $res->body === '') {
            $res = $this->errorPage($res->status);
        }
        return $this->withSecurityHeaders($res);
    }

    private function buildRouter(): Router
    {
        $router = new Router();
        (require self::root() . '/src/routes.php')($router);
        // Cada módulo registra sus rutas en src/Modules/<Nombre>/routes.php
        foreach (glob(self::root() . '/src/Modules/*/routes.php') ?: [] as $file) {
            (require $file)($router);
        }
        return $router;
    }

    private function errorPage(int $status, ?string $detail = null): Response
    {
        $titles = [
            403 => 'Sin permiso',
            404 => 'Página no encontrada',
            413 => 'Archivo demasiado grande',
            405 => 'Método no permitido',
            500 => 'Error interno',
        ];
        $title = $titles[$status] ?? 'Error';
        $html = View::render('error', [
            'title'  => $title,
            'code'   => $status,
            'detail' => $detail ?? ($status === 403 ? 'Tu usuario no tiene permiso para esta pantalla.' : null),
        ]);
        return Response::html($html, $status);
    }

    private function withSecurityHeaders(Response $res): Response
    {
        $res->headers += [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options'        => 'DENY',
            'Referrer-Policy'        => 'same-origin',
        ];
        if (str_starts_with($res->headers['Content-Type'] ?? '', 'text/html')) {
            // Pantallas con datos del usuario: que el navegador no las guarde en caché.
            $res->headers += ['Cache-Control' => 'no-store'];
        }
        return $res;
    }

    private function log(\Throwable $e): void
    {
        self::registrar($e);
    }

    /** Deja el error en storage/logs (no se muestra al usuario). */
    public static function registrar(\Throwable $e): void
    {
        $dir = self::root() . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf(
            "[%s] %s: %s en %s:%d\n%s\n\n",
            date('c'),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
        @file_put_contents($dir . '/app-' . date('Y-m') . '.log', $line, FILE_APPEND);
    }
}
