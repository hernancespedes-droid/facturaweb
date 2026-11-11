<?php
declare(strict_types=1);

namespace App\Http;

final class Response
{
    /**
     * @param array<string,string> $headers
     */
    public function __construct(
        public int $status = 200,
        public string $body = '',
        public array $headers = []
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            $status,
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self($status, '', ['Location' => $url]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
