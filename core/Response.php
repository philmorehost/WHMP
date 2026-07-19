<?php

declare(strict_types=1);

namespace CodeVault;

class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    public function __construct(
        private string $body = '',
        private int $status = 200
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        $response = new self($body, $status);
        $response->headers['Content-Type'] = 'text/html; charset=utf-8';

        return $response;
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $response = new self((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), $status);
        $response->headers['Content-Type'] = 'application/json';

        return $response;
    }

    public static function redirect(string $to, int $status = 302): self
    {
        $response = new self('', $status);
        $response->headers['Location'] = $to;

        return $response;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        echo $this->body;
    }
}
