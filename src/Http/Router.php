<?php
declare(strict_types=1);

namespace App\Http;

/**
 * Enrutador mínimo. Las rutas aceptan parámetros: /vehiculos/{id}.
 * Cada handler recibe (Request $req, array $params) y devuelve una Response.
 */
final class Router
{
    /** @var array<int,array{0:string,1:string,2:callable}> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function add(string $method, string $path, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $path) . '$#';
        $this->routes[] = [strtoupper($method), $regex, $handler];
    }

    /** Devuelve 404 o 405 con cuerpo vacío; la App arma la página de error. */
    public function dispatch(Request $req): Response
    {
        $pathMatched = false;
        foreach ($this->routes as [$method, $regex, $handler]) {
            if (preg_match($regex, $req->path, $m) !== 1) {
                continue;
            }
            $pathMatched = true;
            if ($method === $req->method) {
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                return $handler($req, $params);
            }
        }
        return new Response($pathMatched ? 405 : 404);
    }
}
