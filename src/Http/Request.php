<?php
declare(strict_types=1);

namespace App\Http;

final class Request
{
    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $server = [],
        public readonly array $files = []
    ) {
    }

    public static function fromGlobals(string $basePath = ''): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = rawurldecode((string) (parse_url($uri, PHP_URL_PATH) ?: '/'));
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            self::normalize($path),
            $_GET,
            $_POST,
            $_SERVER,
            $_FILES
        );
    }

    /** Arma un pedido a mano (lo usan las pruebas). */
    public static function make(string $method, string $path, array $post = [], array $query = [], array $files = []): self
    {
        return new self(strtoupper($method), self::normalize($path), $query, $post, [], $files);
    }

    public static function normalize(string $path): string
    {
        return '/' . trim($path, '/');
    }
}
